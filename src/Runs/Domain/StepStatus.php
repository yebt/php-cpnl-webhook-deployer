<?php

declare(strict_types=1);

namespace Deployer\Runs\Domain;

enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
}
