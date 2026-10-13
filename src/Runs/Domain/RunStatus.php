<?php

declare(strict_types=1);

namespace Deployer\Runs\Domain;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Success, self::Failed, self::Cancelled => true,
            self::Queued, self::Running => false,
        };
    }
}
