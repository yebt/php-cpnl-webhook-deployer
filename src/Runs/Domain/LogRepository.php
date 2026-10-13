<?php

declare(strict_types=1);

namespace Deployer\Runs\Domain;

use DateTimeImmutable;

interface LogRepository
{
    public function append(int $runId, ?int $stepPosition, LogStream $stream, string $line, DateTimeImmutable $at): void;

    /** @return list<LogLine> lines with id > $afterId, oldest first */
    public function since(int $runId, int $afterId, int $limit): array;
}
