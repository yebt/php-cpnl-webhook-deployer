<?php

declare(strict_types=1);

namespace Deployer\Runs\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class Duration
{
    /** Milliseconds between two stored UTC timestamps; null when either is missing. */
    public static function millis(?string $start, ?string $end): ?int
    {
        $from = self::parse($start);
        $to = self::parse($end);
        if ($from === null || $to === null) {
            return null;
        }

        return max(0, (int) round(((float) $to->format('U.u') - (float) $from->format('U.u')) * 1000));
    }

    /** Human form such as "1m 05s" or "0.42s". */
    public static function human(?int $millis): string
    {
        if ($millis === null) {
            return '-';
        }
        if ($millis < 60_000) {
            return sprintf('%.1fs', $millis / 1000);
        }
        $seconds = intdiv($millis, 1000);

        return $seconds >= 3600
            ? sprintf('%dh %02dm %02ds', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    private static function parse(?string $at): ?DateTimeImmutable
    {
        if ($at === null || $at === '') {
            return null;
        }
        $utc = new DateTimeZone('UTC');

        return DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $at, $utc)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $at, $utc)
            ?: null;
    }
}
