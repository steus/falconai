<?php

declare(strict_types=1);

namespace App;

interface Clock
{
    public function now(): int;
}

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
