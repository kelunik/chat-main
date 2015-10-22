<?php

namespace Kelunik\ChatMain\OAuth;

use Amp\Artax\Client;
use Amp\Artax\Request;

class StackExchange extends Provider {
    public function __construct(Client $client, string $scope = "") {
        parent::__construct($client, $scope);

        $host = config("app.host");
        $this->redirectUri = "http://{$host}/login/stack-exchange";
        $this->authorizeUrl = "https://stackexchange.com/oauth";
        $this->accessTokenUrl = "https://stackexchange.com/oauth/access_token";
        $this->clientId = config("api.stack-exchange.id");
        $this->clientSecret = config("api.stack-exchange.secret");
        $this->client = $client;
        $this->scope = $scope;
    }

    public function getIdentity(string $token) {
        $query = http_build_query([
            "key" => config("api.stack-exchange.key"),
            "site" => "stackoverflow",
            "access_token" => $token,
        ]);

        $request = (new Request)->setUri("https://api.github.com/me?{$query}");
        $response = yield $this->client->request($request);
        $response = json_decode($response->getBody(), true);

        if (isset($response["items"][0]["user_id"], $response["items"][0]["display_name"])) {
            return [
                "id" => $response["items"][0]["user_id"],
                "name" => $response["items"][0]["display_name"],
            ];
        } else {
            return null;
        }
    }
}
