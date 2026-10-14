<?php

declare(strict_types=1);

namespace Deployer\Updates\Application\Port;

use Deployer\Updates\Application\UpdateFailed;
use Deployer\Updates\Application\UpdateRefused;

/** Replaces the installed artifact on disk. */
interface PharInstaller
{
    /** @throws UpdateRefused (NOT_WRITABLE) when the artifact cannot be replaced */
    public function assertWritable(string $target): void;

    /**
     * Validates $bytes as a phar stamped with $expectedVersion, then swaps it in
     * atomically, keeping the previous file as a backup.
     *
     * @return string path of the backup
     * @throws UpdateRefused (INVALID_PHAR) when the bytes are not an acceptable phar
     * @throws UpdateFailed on I/O errors
     */
    public function install(string $target, string $bytes, string $expectedVersion): string;
}
