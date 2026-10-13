<?php

declare(strict_types=1);

namespace Deployer\Runs\Domain;

enum LogStream: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';
    /** Lines written by the deployer itself (step headers, executed command, exit codes). */
    case System = 'system';
}
