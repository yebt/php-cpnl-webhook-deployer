<?php

declare(strict_types=1);

namespace Deployer\Updates\Domain;

final class ReleaseAsset
{
    public function __construct(
        public readonly string $name,
        /** API URL of the asset (downloaded with Accept: application/octet-stream). */
        public readonly string $url,
        public readonly int $size,
    ) {
    }
}
