<?php

declare(strict_types=1);

namespace Deployer\Updates\Infrastructure;

use Deployer\Updates\Application\Port\PharInstaller;
use Deployer\Updates\Application\UpdateFailed;
use Deployer\Updates\Application\UpdateRefused;
use Phar;
use Throwable;

/**
 * Swaps the artifact in place:
 *  1. write the new bytes to a temp file in the same directory (same filesystem,
 *     so the final rename is atomic) with a `.phar` name so ext/phar opens it;
 *  2. open it with `new Phar()` — this parses the manifest and verifies the phar
 *     signature without running any of its code — and check the stamped VERSION
 *     and the entry point;
 *  3. copy the current file to `<file>.bak`, copy its permissions onto the temp
 *     file, then rename() the temp file over the artifact;
 *  4. drop the old code from OPcache.
 *
 * Processes already running keep the old inode open, so in-flight runs finish on
 * the old code; the next request or CLI call loads the new one.
 */
final class FilesystemPharInstaller implements PharInstaller
{
    public function assertWritable(string $target): void
    {
        $dir = dirname($target);
        if (!is_file($target) || !is_writable($target) || !is_dir($dir) || !is_writable($dir)) {
            throw new UpdateRefused(UpdateRefused::NOT_WRITABLE, sprintf(
                'Cannot replace %s: the file and its directory %s must be writable by this PHP user.',
                $target,
                $dir,
            ));
        }
    }

    public function install(string $target, string $bytes, string $expectedVersion): string
    {
        $this->assertWritable($target);
        $dir = dirname($target);
        $temp = sprintf('%s/.deployer-update-%s.phar', $dir, bin2hex(random_bytes(6)));
        $backup = $target . '.bak';

        try {
            if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new UpdateFailed(sprintf('Cannot write the downloaded artifact to %s.', $dir));
            }
            $this->validate($temp, $expectedVersion);

            $mode = @fileperms($target);
            if (!@copy($target, $backup)) {
                throw new UpdateFailed(sprintf('Cannot write the backup %s.', $backup));
            }
            if ($mode !== false) {
                @chmod($backup, $mode & 0777);
                @chmod($temp, $mode & 0777);
            }
            if (!@rename($temp, $target)) {
                throw new UpdateFailed(sprintf('Cannot move the new artifact over %s.', $target));
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }

        clearstatcache(true, $target);
        $this->invalidateOpcache($target);

        return $backup;
    }

    private function validate(string $path, string $expectedVersion): void
    {
        try {
            $phar = new Phar($path);
            $version = isset($phar['VERSION']) ? trim($phar['VERSION']->getContent()) : null;
            $hasEntry = isset($phar['public/index.php']);
            $signed = $phar->getSignature() !== false;
            unset($phar);
        } catch (Throwable $e) {
            throw new UpdateRefused(UpdateRefused::INVALID_PHAR, 'The downloaded file is not a valid phar: ' . $e->getMessage());
        } finally {
            unset($phar);
        }
        if (!$signed || !$hasEntry) {
            throw new UpdateRefused(UpdateRefused::INVALID_PHAR, 'The downloaded phar is not a signed deployer artifact.');
        }
        if ($version !== $expectedVersion) {
            throw new UpdateRefused(UpdateRefused::INVALID_PHAR, sprintf('The downloaded phar reports version "%s", expected "%s".', $version ?? 'none', $expectedVersion));
        }
    }

    private function invalidateOpcache(string $target): void
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        @opcache_invalidate($target, true);
        // Scripts inside the phar are cached under phar://<path>/...; drop them too when the API allows it.
        $status = function_exists('opcache_get_status') ? @opcache_get_status(true) : false;
        if (is_array($status) && is_array($status['scripts'] ?? null)) {
            $prefix = 'phar://' . $target . '/';
            foreach (array_keys($status['scripts']) as $script) {
                if (is_string($script) && str_starts_with($script, $prefix)) {
                    @opcache_invalidate($script, true);
                }
            }
        }
    }
}
