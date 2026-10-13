<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Config;

/**
 * Resolved runtime configuration. Built once per process by ConfigLoader.
 */
final class AppConfig
{
    public function __construct(
        public readonly string $dataDir,
        public readonly string $phpCli,
        public readonly string $entryScript,
        public readonly bool $isPhar,
        public readonly float $killGraceSeconds = 5.0,
        public readonly ?string $configFile = null,
    ) {
    }

    public function databasePath(): string
    {
        return $this->dataDir . '/deployer.sqlite';
    }

    public function locksDir(): string
    {
        return $this->dataDir . '/locks';
    }
}
