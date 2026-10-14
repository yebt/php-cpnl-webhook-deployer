<?php

declare(strict_types=1);

namespace Deployer\Updates\Domain;

/** A published release of the deployer artifact. */
final class Release
{
    public const PHAR_ASSET = 'deployer.phar';
    public const CHECKSUM_ASSET = 'deployer.phar.sha256';

    public function __construct(
        public readonly string $tag,
        public readonly SemVer $version,
        public readonly string $name,
        public readonly string $htmlUrl,
        public readonly string $notes,
        public readonly ?string $publishedAt,
        public readonly ?ReleaseAsset $phar,
        public readonly ?ReleaseAsset $checksum,
    ) {
    }
}
