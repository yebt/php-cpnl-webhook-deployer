<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use Deployer\Runs\Domain\LogRepository;
use Deployer\Runs\Domain\RunRepository;
use InvalidArgumentException;

/** Incremental log reads by cursor, for polling clients. */
final class ReadRunLogs
{
    public const MAX_LIMIT = 1000;

    public function __construct(
        private readonly RunRepository $runs,
        private readonly LogRepository $logs,
    ) {
    }

    public function __invoke(int $runId, int $afterId = 0, int $limit = 500): LogChunk
    {
        // Read status first: if it is final, every line was already written.
        $run = $this->runs->find($runId) ?? throw new InvalidArgumentException(sprintf('Run %d not found.', $runId));
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $lines = $this->logs->since($runId, max(0, $afterId), $limit);
        $next = $lines === [] ? max(0, $afterId) : $lines[count($lines) - 1]->id;

        return new LogChunk($lines, $next, $run->status);
    }
}
