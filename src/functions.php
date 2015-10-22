<?php

use JsonSchema\Uri\UriRetriever;
use JsonSchema\Validator;

function isOriginAllowed(string $origin): bool {
    $origin = str_replace(["http://", "https://"], "", $origin);
    return strtok($origin, ":") === config("app.host");
}

function config(string $key, $default = null) {
    static $config;

    if (!$config) {
        $config = loadConfig();
    }

    return $config[$key] ?? $default;
}

function loadConfig() {
    $config = json_decode(file_get_contents(__DIR__ . "/../etc/config/config.json"));

    $retriever = new UriRetriever;

    $path = realpath(__DIR__ . "/../res/schema/config.json");
    $schema = $retriever->retrieve("file://$path");

    $validator = new Validator;
    $validator->check($config, $schema);

    if (!$validator->isValid()) {
        $errors = implode(",\n", array_map(function ($error) {
            if ($error["property"]) {
                return sprintf("[%s] %s", $error["property"], $error["message"]);
            } else {
                return $error["message"];
            }
        }, $validator->getErrors()));

        throw new RuntimeException("config file not valid\n\n$errors");
    }

    $config = collapseConfig($config);

    return $config;
}

function collapseConfig($obj) {
    return iterator_to_array(collapseConfigHelper($obj));
}

function collapseConfigHelper($obj, $prefix = "") {
    foreach ($obj as $key => $value) {
        if ($value instanceof stdClass) {
            yield from collapseConfigHelper($value, $prefix ? $prefix . "." . $key : $key);
        } else {
            yield $prefix . "." . $key => $value;
        }
    }
}