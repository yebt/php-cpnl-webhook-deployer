<?php

declare(strict_types=1);

namespace Deployer\Runs\Domain;

final class Run
{
    public function __construct(
        public readonly int $id,
        public readonly int $projectId,
        public readonly RunStatus $status,
        public readonly string $trigger,
        public readonly bool $cancelRequested,
        public readonly ?int $runnerPid,
        public readonly string $createdAt,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $error,
        public readonly ?string $pipelineYaml,
        /** @var array<string,string>|null informational trigger summary (e.g. webhook ref/commit) */
        public readonly ?array $triggerMeta = null,
    ) {
    }
}
