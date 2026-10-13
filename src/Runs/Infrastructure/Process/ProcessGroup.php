<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Process;

/**
 * Signals a whole process group (negative PID), falling back to kill(1) when
 * ext-posix is not available.
 */
final class ProcessGroup
{
    public static function signal(int $pgid, int $signal): void
    {
        if ($pgid <= 1) {
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill(-$pgid, $signal);

            return;
        }
        exec(sprintf('kill -%d -- -%d 2>/dev/null', $signal, $pgid));
    }

    public static function isAlive(int $pgid): bool
    {
        if ($pgid <= 1) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill(-$pgid, 0) || posix_get_last_error() === 1; // EPERM: exists
        }
        exec(sprintf('kill -0 -- -%d 2>/dev/null', $pgid), $out, $code);

        return $code === 0;
    }
}
