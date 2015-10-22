<?php

use Aerys\Request;
use Aerys\Response;
use Aerys\Session;
use Amp\Mysql\Pool as MySQL;
use Amp\Redis\Client as Redis;
use Amp\Redis\SubscribeClient;
use Auryn\Injector;
use Kelunik\Chat\Boundaries\StandardRequest;
use Kelunik\Chat\Boundaries\User;
use Kelunik\Chat\Chat;
use Kelunik\ChatMain\SecurityMiddleware;
use Kelunik\Template\TemplateService;
use function Aerys\root;
use function Aerys\router;
use function Aerys\session;
use function Amp\reactor;

$injector = new Injector;
$injector->alias("Kelunik\\Chat\\Storage\\MessageStorage", "Kelunik\\Chat\\Storage\\MysqlMessageStorage");
$injector->alias("Kelunik\\Chat\\Storage\\PingStorage", "Kelunik\\Chat\\Storage\\MysqlPingStorage");
$injector->alias("Kelunik\\Chat\\Storage\\RoomStorage", "Kelunik\\Chat\\Storage\\MysqlRoomStorage");
$injector->alias("Kelunik\\Chat\\Storage\\UserStorage", "Kelunik\\Chat\\Storage\\MysqlUserStorage");
$injector->alias("Kelunik\\Chat\\Events\\EventSub", "Kelunik\\Chat\\Events\\RedisEventSub");
$injector->alias("Kelunik\\Chat\\Search\\Messages\\MessageSearch", "Kelunik\\Chat\\Search\\Messages\\ElasticSearch");
$injector->prepare("Kelunik\\Template\\TemplateService", function (TemplateService $service) {
    $service->setBaseDirectory(__DIR__ . "/../res/html");
});

$injector->define("Kelunik\\Chat\\Search\\Messages\\ElasticSearch", [
    ":host" => config("elastic.host"),
    ":port" => config("elastic.port"),
]);

$injector->share($injector);
$injector->share("Kelunik\\Template\\TemplateService");
$injector->share(new Redis(config("redis.protocol") . "://" . config("redis.host") . ":" . config("redis.port")));
$injector->share(new SubscribeClient(config("redis.protocol") . "://" . config("redis.host") . ":" . config("redis.port")));
$injector->share(new MySQL(sprintf(
    "host=%s;user=%s;pass=%s;db=%s",
    config("database.host"),
    config("database.user"),
    config("database.pass"),
    config("database.name")
)));

$auth = $injector->make("Kelunik\\ChatMain\\Auth");

/** @var TemplateService $templateService */
$templateService = $injector->make("Kelunik\\Template\\TemplateService");

/** @var Chat $chat */
$chat = $injector->make("Kelunik\\Chat\\Chat");

$router = router()
    ->get("", function (Request $req, Response $resp) use ($templateService) {
        $session = yield (new Session($req))->read();

        if (!$session->get("login")) {
            $template = $templateService->load("main.php");
            $resp->send($template->render());
        }
    })
    ->get("login", [$auth, "logIn"])
    ->post("login/{provider:github|stack-exchange}", [$auth, "doLogInRedirect"])
    ->get("login/{provider:github|stack-exchange}", [$auth, "doLogIn"])
    ->get("join", [$auth, "join"])
    ->post("join", [$auth, "doJoin"])
    ->post("logout", [$auth, "doLogOut"]);

$router->get("ws", Aerys\websocket($injector->make("Kelunik\\ChatMain\\WebSocketChat")));

$root = root(realpath(__DIR__ . "/../public"), ["indexes" => []]);
$host = (new Aerys\Host)
    ->expose("*", config("app.port"))
    ->name(config("app.host"))
    ->use($router)
    ->use($root)
    ->use(function (Request $request, Response $response) use ($chat, $templateService) {
        $session = yield (new Session($request))->read();

        $user = new User($session->get("login") ?? 0, $session->get("login:name") ?? "", $session->get("login:avatar") ?? "");
        $apiRequest = new StandardRequest("me/rooms", new stdClass, null);
        $apiResponse = yield $chat->process($apiRequest, $user);

        if ($apiResponse->getStatus() !== 200) {
            $response->setStatus(503);
            $response->send("service currently not available");

            return;
        }

        $template = $templateService->load("app.php");

        $template->set("user", [
            "id" => $user->id,
            "name" => $user->name,
            "avatar" => $user->avatar,
        ]);

        $template->set("rooms", $apiResponse->getData());

        $response->send($template->render());
    });

$uri = config("redis.protocol") . "://" . config("redis.host") . ":" . config("redis.port");

// Sessions
$host->use(session(["driver" => new Aerys\Session\RedisPublish(
    new Amp\Redis\Client($uri),
    new Amp\Redis\Mutex($uri, [])
)]));

// CSP and other security related headers
$host->use(new SecurityMiddleware("ws://" . config("app.host") . ":* wss://" . config("app.host")));