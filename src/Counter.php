<?php

namespace Kelunik\ChatMain;

use Amp\Deferred;
use Amp\Promise;
use Amp\Redis\Client;
use function Amp\pipe;

class Counter {
    private $redis;

    public function __construct(Client $redis) {
        $this->redis = $redis;
    }

    public function update(string $key, int $user, int $change): Promise {
        $promisor = new Deferred;

        $this->redis->hIncrBy($key, $user, $change)->when(function ($error, $result) use ($key, $user, $promisor) {
            if ($error) {
                $promisor->fail($error);
            } else {
                if ($result) {
                    $promisor->succeed($result);
                } else {
                    $promisor->succeed(pipe($this->redis->hDel($key, $user), function () use ($result) {
                        return $result;
                    }));
                }
            }
        });

        return $promisor->promise();
    }
}