<?php

declare(strict_types=1);

namespace Deployer\Runs\Domain;

final class RunFilter
{
    public const MAX_LIMIT = 200;

    public function __construct(
        public readonly ?int $projectId = null,
        public readonly ?RunStatus $status = null,
        public readonly int $limit = 50,
        /** Keyset pagination: only runs with id < beforeId. */
        public readonly ?int $beforeId = null,
    ) {
    }
}
