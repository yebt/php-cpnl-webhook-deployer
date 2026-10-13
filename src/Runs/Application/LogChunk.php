<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use Deployer\Runs\Domain\LogLine;
use Deployer\Runs\Domain\RunStatus;

final class LogChunk
{
    /** @param list<LogLine> $lines */
    public function __construct(
        public readonly array $lines,
        /** Pass back as `afterId` on the next poll. */
        public readonly int $nextAfterId,
        public readonly RunStatus $runStatus,
    ) {
    }

    /** True when the run is final and every line has been returned. */
    public function isComplete(int $limit): bool
    {
        return $this->runStatus->isFinal() && count($this->lines) < $limit;
    }
}
