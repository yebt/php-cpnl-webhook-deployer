<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure;

use Deployer\Runs\Application\Port\ProcessProbe;

final class PosixProcessProbe implements ProcessProbe
{
    public function isAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0) || posix_get_last_error() === 1; // EPERM: exists
        }

        return is_dir('/proc/' . $pid);
    }
}
