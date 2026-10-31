<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, 'PHP 8.3 or newer is required. This is PHP ' . PHP_VERSION . ".\n");
    exit(1);
}

require __DIR__ . '/Clock.php';
require __DIR__ . '/ConflictException.php';
require __DIR__ . '/Translator.php';
require __DIR__ . '/TimeTracker.php';
require __DIR__ . '/Http.php';
