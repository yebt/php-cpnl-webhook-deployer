<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Domain;

final class BuiltinVars
{
    public const NAMES = ['PROJECT', 'RUN_ID', 'PROJECT_DIR'];

    public function __construct(
        public readonly string $project,
        public readonly int $runId,
        public readonly string $projectDir,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'PROJECT' => $this->project,
            'RUN_ID' => (string) $this->runId,
            'PROJECT_DIR' => $this->projectDir,
        ];
    }
}
