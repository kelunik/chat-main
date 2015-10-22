<?php

namespace Kelunik\ChatMain\OAuth;

use Amp\Artax\Client;
use Amp\Artax\Request;

class GitHub extends Provider {
    public function __construct(Client $client, string $scope = "") {
        parent::__construct($client, $scope);

        $this->authorizeUrl = "https://github.com/login/oauth/authorize";
        $this->accessTokenUrl = "https://github.com/login/oauth/access_token";
        $this->clientId = config("api.github.id");
        $this->clientSecret = config("api.github.secret");
        $this->client = $client;
        $this->scope = $scope;
    }

    public function getIdentity(string $token) {
        $request = (new Request)->setUri("https://api.github.com/user")->setHeader("authorization", "token {$token}");
        $response = yield $this->client->request($request);
        $response = json_decode($response->getBody(), true);

        if (isset($response["id"], $response["login"], $response["avatar_url"])) {
            return [
                "id" => $response["id"],
                "name" => $response["login"],
                "avatar" => $response["avatar_url"],
            ];
        } else {
            return null;
        }
    }
}
