<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Http;

Http::dispatch(static function (): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        Http::json(['error' => \App\Translator::line('method_not_allowed')], 405);
    }

    $from = isset($_GET['from']) ? (string) $_GET['from'] : '';
    $to = isset($_GET['to']) ? (string) $_GET['to'] : '';
    if ($from === '' || $to === '') {
        throw new InvalidArgumentException('dates_required');
    }

    Http::json(Http::tracker()->report($from, $to));
});
