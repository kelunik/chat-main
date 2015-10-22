<?php

namespace Kelunik\ChatMain;

use Aerys\Request;
use Aerys\Response;
use Aerys\Session;
use Amp\Artax\Client as Artax;
use Amp\Dns\ResolutionException;
use Amp\Mysql\Pool;
use Amp\Redis\Client as Redis;
use function Amp\resolve;
use Kelunik\ChatMain\OAuth\GitHub;
use Kelunik\ChatMain\OAuth\OAuthException;
use Kelunik\ChatMain\OAuth\StackExchange;
use Kelunik\Template\TemplateService;
use LogicException;

class Auth {
    private $db;
    private $artax;
    private $redis;
    private $templateService;

    public function __construct(Pool $db, Redis $redis, Artax $artax, TemplateService $templateService) {
        $this->db = $db;
        $this->artax = $artax;
        $this->redis = $redis;
        $this->templateService = $templateService;
    }

    public function logIn(Request $request, Response $response) {
        $session = yield (new Session($request))->read();

        if ($session->get("login")) {
            $response->setStatus(302);
            $response->setHeader("location", "/");
            $response->send("");

            return;
        }

        $template = $this->templateService->load("auth.php");
        $response->send($template->render());
    }

    public function doLogInRedirect(Request $request, Response $response, array $args) {
        $provider = $this->getProviderFromString($args["provider"]);

        $session = yield (new Session($request))->open();

        $token = bin2hex(random_bytes(32));
        $session->set("token:oauth", $token);

        yield $session->save();

        $url = $provider->getAuthorizeRedirectUrl($token);

        $response->setStatus(302);
        $response->setHeader("location", $url);
        $response->send("");
    }

    public function doLogIn(Request $request, Response $response, array $args) {
        $session = yield (new Session($request))->read();

        $provider = $this->getProviderFromString($args["provider"]);
        $token = $session->get("token:oauth");

        $get = $request->getQueryVars();

        $code = isset($get["code"]) && is_string($get["code"]) ? $get["code"] : "";
        $state = isset($get["state"]) && is_string($get["state"]) ? $get["state"] : "";

        if (empty($code) || empty($state) || empty($token) || !hash_equals($token, $state)) {
            $response->setStatus(400);
            $response->setHeader("aerys-generic-response", "enable");
            $response->send("");

            return;
        }

        try {
            $accessToken = yield resolve($provider->getAccessTokenFromCode($code));
        } catch (OAuthException $e) {
            // TODO pretty error page
            $response->setStatus(403);
            $response->setHeader("aerys-generic-response", "enable");
            $response->send("");

            return;
        } catch (ResolutionException $e) {
            // TODO pretty error page
            $response->setStatus(503);
            $response->setHeader("aerys-generic-response", "enable");
            $response->send("");

            return;
        }

        $identity = yield resolve($provider->getIdentity($accessToken));

        if (!$identity) {
            $response->setStatus(403);
            $response->setHeader("aerys-generic-response", "enable");
            $response->send("");

            return;
        }

        $query = yield $this->db->prepare("SELECT user_id FROM oauth WHERE provider = ? AND identity = ?", [
            $args["provider"], $identity["id"],
        ]);

        $response->setStatus(302);
        $user = yield $query->fetchObject();

        $query = yield $this->db->prepare("SELECT id, name, avatar FROM user WHERE id = ?", [$user->user_id]);
        $user = yield $query->fetchObject();

        yield $session->open();

        if ($user) {
            $session->set("login", $user->id);
            $session->set("login:name", $user->name);
            $session->set("login:avatar", $user->avatar);
            $session->set("login:time", time());
            $response->setHeader("location", "/");
        } else {
            $session->set("auth:provider", $args["provider"]);
            $session->set("auth:identity:id", $identity["id"]);
            $session->set("auth:identity:name", $identity["name"]);
            $session->set("auth:identify:avatar", $identity["avatar"]);
            $response->setHeader("location", "/join");
        }

        yield $session->save();
        $response->send("");
    }

    public function join(Request $request, Response $response) {
        $session = yield (new Session($request))->read();
        $template = $this->templateService->load("sign-up.php");
        $template->set("hint", $session->get("auth:identity:name") ?? "");
        $response->send($template->render());
    }

    public function doJoin(Request $request, Response $response) {
        $session = yield (new Session($request))->open();
        parse_str(yield $request->getBody(), $post);

        $username = isset($post["username"]) && is_string($post["username"]) ? $post["username"] : "";
        $avatar = $session->get("auth:identity:avatar");
        $provider = $session->get("auth:provider");

        if (!$provider) {
            $response->setStatus(400);
            $response->setHeader("aerys-generic-response", "enable");
            $response->send("");

            return;
        }

        if (!preg_match("~^[a-z][a-z0-9-]+[a-z0-9]$~i", $username)) {
            $template = $this->templateService->load("sign-up.php");
            $template->set("hint", $username);
            $template->set("error", "username must start with a-z and only contain a-z, 0-9 or dashes afterwards");
            $response->send($template->render());

            return;
        }

        $query = yield $this->db->prepare("INSERT IGNORE INTO user (`name`, `avatar`) VALUES (?, ?)", [
            $username, $avatar
        ]);

        if ($query->affectedRows) {
            yield $this->db->prepare("INSERT INTO oauth (user_id, provider, identity, label) VALUES (?, ?, ?, ?)", [
                $query->insertId, $provider, $session->get("auth:identity:id"), $session->get("auth:identity:name"),
            ]);

            $session->set("login", $query->insertId);
            $session->set("login:name", $username);
            $session->set("login:avatar", $avatar);
            $session->set("login:time", time());

            $response->setStatus(302);
            $response->setHeader("location", "/");
            $response->send("");
        } else {
            $template = $this->templateService->load("sign-up.php");
            $template->set("hint", $username);
            $template->set("error", "username already taken");
            $response->send($template->render());
        }

        yield $session->save();
    }

    private function getProviderFromString(string $provider) {
        switch ($provider) {
            case "github":
                return new GitHub($this->artax);
            case "stack-exchange":
                return new StackExchange($this->artax);
            default:
                throw new LogicException("unknown provider: " . $provider);
        }
    }

    public function doLogOut(Request $request, Response $response) {
        $session = new Session($request);

        yield $session->open();
        yield $session->destroy();

        $response->setStatus(302);
        $response->setHeader("location", "/");
        $response->send("");
    }
}
