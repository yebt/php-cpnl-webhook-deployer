<?php

declare(strict_types=1);

namespace Deployer\Updates\Application;

final class UpdateResult
{
    public function __construct(
        public readonly bool $updated,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $backupPath = null,
    ) {
    }
}
