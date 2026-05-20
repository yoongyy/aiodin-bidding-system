<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function travelTo(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
