<?php

declare(strict_types=1);

namespace Deployer\Runs\Domain;

final class RunStepRecord
{
    public function __construct(
        public readonly int $position,
        public readonly string $name,
        public readonly string $command,
        public readonly int $timeout,
        public readonly bool $continueOnError,
        public readonly StepStatus $status,
        public readonly ?int $exitCode,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
    ) {
    }
}
