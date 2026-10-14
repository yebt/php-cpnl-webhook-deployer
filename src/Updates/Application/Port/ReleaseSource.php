<?php

declare(strict_types=1);

namespace Deployer\Updates\Application\Port;

use Deployer\Updates\Application\UpdateFailed;
use Deployer\Updates\Domain\Release;
use Deployer\Updates\Domain\ReleaseAsset;

/** Where published releases come from (GitHub Releases in production). */
interface ReleaseSource
{
    /**
     * The newest published release, or null when none is published.
     *
     * @throws UpdateFailed on transport or API errors
     */
    public function latest(bool $includePrereleases): ?Release;

    /**
     * Downloads an asset's bytes (at most $maxBytes).
     *
     * @throws UpdateFailed
     */
    public function download(ReleaseAsset $asset, int $maxBytes): string;
}
