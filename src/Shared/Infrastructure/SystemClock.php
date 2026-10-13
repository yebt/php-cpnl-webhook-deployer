<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Deployer\Shared\Domain\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
