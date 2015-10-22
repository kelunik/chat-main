<?php

namespace Kelunik\ChatMain;

use Aerys\Request;
use Aerys\Response;
use Aerys\Session;
use Aerys\Websocket;
use Aerys\Websocket\Message;
use Amp\Pause;
use Kelunik\Chat\Boundaries\Data;
use Kelunik\Chat\Boundaries\Error;
use Kelunik\Chat\Boundaries\Response as ApiResponse;
use Kelunik\Chat\Boundaries\StandardRequest;
use Kelunik\Chat\Boundaries\User;
use Kelunik\Chat\Chat;
use Kelunik\Chat\Events\ConnectionState;
use Kelunik\Chat\Events\EventSub;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use function Amp\all;
use function Amp\coroutine;
use function Amp\resolve;

class WebSocketChat implements Websocket {
    /**
     * @var Websocket\Endpoint
     */
    private $endpoint;

    /**
     * @var array
     */
    private $clientStates;

    /**
     * @var array
     */
    private $session2clients;

    /**
     * @var array
     */
    private $user2sessions;

    /**
     * @var array
     */
    private $client2user;

    /**
     * @var array
     */
    private $client2session;

    /**
     * @var Session[]
     */
    private $sessions;

    /**
     * @var Chat
     */
    private $chat;

    /**
     * @var Counter
     */
    private $counter;

    /**
     * @var ReflectionProperty
     */
    private $sessionField;

    /**
     * @var EventSub
     */
    private $eventSub;

    /**
     * @var array
     */
    private $rooms;

    public function __construct(Chat $chat, Counter $counter, EventSub $eventSub) {
        $this->chat = $chat;
        $this->counter = $counter;
        $this->eventSub = $eventSub;
        $this->clientStates = [];
        $this->session2clients = [];
        $this->user2sessions = [];
        $this->client2user = [];
        $this->client2session = [];
        $this->sessions = [];
        $this->rooms = [];

        $sessionClass = new ReflectionClass(Session::class);
        $sessionField = $sessionClass->getProperty("id");
        $sessionField->setAccessible(true);
        $this->sessionField = $sessionField;
    }

    public function onStart(Websocket\Endpoint $endpoint) {
        $this->endpoint = $endpoint;
        $this->initRoomSubscription();
        $this->initUserSubscription();
    }

    public function onStop() {
        // there's nothing to do...
    }

    public function onHandshake(Request $request, Response $response) {
        $origin = $request->getHeader("origin");

        if (!isOriginAllowed($origin)) {
            $response->setStatus(400);
            $response->setReason("Invalid Origin");
            $response->send("<h1>Invalid Origin</h1>");

            return null;
        }

        if ($this->eventSub->getConnectionState() !== ConnectionState::CONNECTED) {
            $response->setStatus(503);
            $response->setReason("Service unavailable");
            $response->send("");

            return null;
        }

        return new Session($request);
    }

    public function onOpen(int $clientId, $session) {
        yield $session->read();

        $sessionId = $this->sessionField->getValue($session);
        $userId = $session->get("login") ?? 0;

        $this->session2clients[$sessionId][$clientId] = true;
        $this->user2sessions[$userId][$sessionId] = true;
        $this->client2user[$clientId] = $userId;
        $this->client2session[$clientId] = $sessionId;
        $this->clientStates[$clientId] = true;
        $this->sessions[$sessionId] = $session;

        if ($userId) {
            yield all([
                $this->counter->update("ws:connected", $userId, 1),
                $this->counter->update("ws:active", $userId, 1),
            ]);
        }
    }

    public function onClose(int $clientId, int $code, string $reason) {
        $userId = $this->client2user[$clientId];
        $sessionId = $this->client2session[$clientId];
        $active = $this->clientStates[$clientId];

        unset(
            $this->clientStates[$clientId],
            $this->client2user[$clientId],
            $this->client2session[$clientId],
            $this->session2clients[$sessionId][$clientId]
        );

        if (empty($this->session2clients[$sessionId])) {
            unset(
                $this->sessions[$sessionId],
                $this->session2clients[$sessionId],
                $this->user2sessions[$userId][$sessionId]
            );

            if (empty($this->user2sessions[$userId])) {
                unset($this->user2sessions[$userId]);
            }
        }

        if ($userId) {
            $promises[] = $this->counter->update("ws:connected", $userId, -1);

            if ($active) {
                $promises[] = $this->counter->update("ws:active", $userId, -1);
            }

            yield all($promises);
        }
    }

    public function onData(int $clientId, Message $payload) {
        $data = json_decode(yield $payload);
        yield resolve($this->onMessage($clientId, $data));
    }

    protected function onMessage($clientId, $data) {
        if (is_array($data)) {
            foreach ($data as $message) {
                yield resolve($this->onMessage($clientId, $message));
            }
        } else {
            if (!$this->isValidPayload($data)) {
                $this->writeResponse($clientId, $data->request_id ?? null, new Error("invalid_request", "invalid payload received", 400));

                return;
            }

            if ($data->endpoint === "subscribe" && is_int($data->args->room_id)) {
                $this->rooms[$data->args->room_id][$clientId] = true;
                $this->writeResponse($clientId, $data->request_id, new Data("success"));

                return;
            }

            $data->args = $data->args ?? new stdClass;

            if (!$data->args instanceof stdClass) {
                $this->writeResponse($clientId, $data->request_id, Error::make("bad_request"));
            }


            $sessionId = $this->client2session[$clientId];
            $session = $this->sessions[$sessionId];

            $user = new User($session->get("login") ?? 0, $session->get("login:name") ?? "", $session->get("login:avatar") ?? null);
            $request = new StandardRequest($data->endpoint, $data->args, $data->payload ?? null);
            $response = yield $this->chat->process($request, $user);

            $this->writeResponse($clientId, $data->request_id, $response);
        }
    }

    protected function writeResponse(int $clientId, $requestId, ApiResponse $response) {
        $this->endpoint->send($clientId, json_encode([
            "request_id" => $requestId,
            "status" => $response->getStatus(),
            "data" => $response->getData(),
            "links" => $response->getLinks(),
        ]));
    }

    protected function isValidPayload($data): bool {
        if (!isset($data->request_id, $data->endpoint)) {
            return false;
        }

        if (!is_string($data->endpoint)) {
            return false;
        }

        if (isset($data->args) && !$data->args instanceof stdClass) {
            return false;
        }

        if (isset($this->args)) {
            foreach ($this->args as $value) {
                if (!is_scalar($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function initRoomSubscription() {
        $subscription = $this->eventSub->subscribe("chat:room");
        $subscription->watch(function ($payload) {
            if (empty($this->rooms[$payload->room_id])) {
                return;
            }

            $this->endpoint->broadcast(json_encode([
                "status" => "event",
                "type" => $payload->type,
                "data" => $payload->payload,
            ]), array_keys($this->rooms[$payload->room_id]));
        });

        $subscription->when(function ($error) {
            if ($error) {
                (new Pause(1000))->when(function () {
                    $this->initRoomSubscription();
                });
            }
        });
    }

    protected function initUserSubscription() {
        $subscription = $this->eventSub->subscribe("chat:user");
        $subscription->watch(function ($payload) {
            if (empty($this->user2sessions[$payload->user_id])) {
                return;
            }

            $sessions = $this->user2sessions[$payload->user_id];
            $clients = [];

            foreach (array_keys($sessions) as $sessionId) {
                $clients = array_merge($this->session2clients[$sessionId]);
            }

            $this->endpoint->broadcast(json_encode([
                "status" => "event",
                "type" => $payload->type,
                "data" => $payload->payload,
            ]), $clients);
        });

        $subscription->when(function ($error) {
            if ($error) {
                (new Pause(1000))->when(function () {
                    $this->initUserSubscription();
                });
            }
        });
    }
}
