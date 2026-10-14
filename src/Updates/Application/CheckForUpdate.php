<?php

declare(strict_types=1);

namespace Deployer\Updates\Application;

use Deployer\Updates\Application\Port\ReleaseSource;
use Deployer\Updates\Domain\SemVer;

/** Compares the running version with the newest published release. */
final class CheckForUpdate
{
    public function __construct(
        private readonly ReleaseSource $source,
        private readonly string $currentVersion,
        private readonly bool $includePrereleases = false,
    ) {
    }

    /** @throws UpdateFailed */
    public function __invoke(): UpdateCheck
    {
        $current = SemVer::tryParse($this->currentVersion);
        $latest = $this->source->latest($this->includePrereleases);
        $available = $latest !== null && ($current === null || $latest->version->compare($current) > 0);

        return new UpdateCheck($this->currentVersion, $current === null, $latest, $available);
    }

    public function currentVersion(): ?SemVer
    {
        return SemVer::tryParse($this->currentVersion);
    }
}
