<?php

declare(strict_types=1);

namespace Deployer\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Deployer\Shared\Domain\Clock;

final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $at = '2026-01-01 12:00:00')
    {
        $this->now = new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
