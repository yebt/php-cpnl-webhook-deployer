<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Config;

/**
 * Resolved runtime configuration. Built once per process by ConfigLoader.
 */
final class AppConfig
{
    public const DEFAULT_UPDATE_REPOSITORY = 'yebt/php-cpnl-webhook-deployer';

    public function __construct(
        public readonly string $dataDir,
        public readonly string $phpCli,
        public readonly string $entryScript,
        public readonly bool $isPhar,
        public readonly float $killGraceSeconds = 5.0,
        public readonly ?string $configFile = null,
        /** GitHub repository ("owner/name") whose releases feed self-update. */
        public readonly string $updateRepository = self::DEFAULT_UPDATE_REPOSITORY,
        /** Optional GitHub token for private repositories. Never logged or returned by the API. */
        #[\SensitiveParameter] public readonly ?string $updateToken = null,
        public readonly bool $updatePrereleases = false,
        /** GitHub API origin; only overridable to a loopback origin for local simulations. */
        public readonly string $updateApiUrl = 'https://api.github.com',
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
