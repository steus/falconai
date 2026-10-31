<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Http;

Http::dispatch(static function (): void {
    $tracker = Http::tracker();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        Http::json(['employees' => $tracker->listEmployees()]);
    }

    if ($method === 'POST') {
        $body = Http::body();
        $name = isset($body['name']) ? (string) $body['name'] : '';
        Http::json(['employee' => $tracker->addEmployee($name)], 201);
    }

    Http::json(['error' => \App\Translator::line('method_not_allowed')], 405);
});
