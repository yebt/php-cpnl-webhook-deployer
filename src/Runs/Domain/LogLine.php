<?php

declare(strict_types=1);

namespace Deployer\Runs\Domain;

final class LogLine
{
    public function __construct(
        /** Monotonic cursor for incremental reads. */
        public readonly int $id,
        public readonly int $runId,
        public readonly ?int $stepPosition,
        public readonly LogStream $stream,
        public readonly string $line,
        public readonly string $loggedAt,
    ) {
    }
}
