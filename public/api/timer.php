<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Http;

Http::dispatch(static function (): void {
    $tracker = Http::tracker();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        Http::json(['timers' => $tracker->activeTimers()]);
    }

    if ($method !== 'POST') {
        Http::json(['error' => \App\Translator::line('method_not_allowed')], 405);
    }

    $body = Http::body();
    $employeeId = filter_var($body['employee_id'] ?? null, FILTER_VALIDATE_INT);
    if ($employeeId === false || $employeeId <= 0) {
        throw new InvalidArgumentException('employee_not_selected');
    }

    $action = $body['action'] ?? '';
    if ($action === 'start') {
        $description = isset($body['description']) ? (string) $body['description'] : '';
        Http::json([
            'entry' => $tracker->startTimer((int) $employeeId, $description),
        ], 201);
    }

    if ($action === 'stop') {
        Http::json(['entry' => $tracker->stopTimer((int) $employeeId)]);
    }

    throw new InvalidArgumentException('unknown_action');
});
