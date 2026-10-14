<?php

declare(strict_types=1);

namespace Deployer\Updates\Application;

use Deployer\Updates\Application\Port\PharInstaller;
use Deployer\Updates\Application\Port\ReleaseSource;
use Deployer\Updates\Domain\Release;

/**
 * Replaces the running phar with the newest release after verifying its
 * published sha256 and that it opens as a phar of the expected version.
 * Nothing downloaded is executed before it is installed.
 */
final class ApplyUpdate
{
    public const MAX_PHAR_BYTES = 64 * 1024 * 1024;
    private const MAX_CHECKSUM_BYTES = 4096;

    public function __construct(
        private readonly CheckForUpdate $check,
        private readonly ReleaseSource $source,
        private readonly PharInstaller $installer,
        /** Path of the running phar; '' when running from source. */
        private readonly string $runningPhar,
    ) {
    }

    /**
     * @param bool $force allow dev builds to update and reinstall the same version; never downgrades
     * @throws UpdateRefused|UpdateFailed
     */
    public function __invoke(bool $force): UpdateResult
    {
        if ($this->runningPhar === '') {
            throw new UpdateRefused(UpdateRefused::NOT_PHAR, 'Self-update only works for the packaged phar; this copy runs from source (use git to update it).');
        }

        $check = ($this->check)();
        $latest = $check->latest ?? throw new UpdateRefused(UpdateRefused::NO_RELEASE, 'No published release was found.');
        $to = (string) $latest->version;

        $current = $this->check->currentVersion();
        if ($current === null) {
            if (!$force) {
                throw new UpdateRefused(UpdateRefused::DEV_BUILD, sprintf('This is a "%s" build; run with --force to replace it with release %s.', $check->current, $to));
            }
        } else {
            $cmp = $latest->version->compare($current);
            if ($cmp < 0) {
                throw new UpdateRefused(UpdateRefused::DOWNGRADE, sprintf('The latest release %s is older than the running version %s; refusing to downgrade.', $to, $check->current));
            }
            if ($cmp === 0 && !$force) {
                return new UpdateResult(false, $check->current, $to);
            }
        }

        if ($latest->phar === null || $latest->checksum === null) {
            throw new UpdateRefused(UpdateRefused::MISSING_ASSET, sprintf('Release %s does not provide both %s and %s.', $latest->tag, Release::PHAR_ASSET, Release::CHECKSUM_ASSET));
        }

        $this->installer->assertWritable($this->runningPhar);

        $expected = $this->expectedSha256($this->source->download($latest->checksum, self::MAX_CHECKSUM_BYTES));
        $bytes = $this->source->download($latest->phar, self::MAX_PHAR_BYTES);
        if (!hash_equals($expected, hash('sha256', $bytes))) {
            throw new UpdateRefused(UpdateRefused::CHECKSUM, sprintf('Checksum mismatch for %s %s; the installed file was not touched.', Release::PHAR_ASSET, $latest->tag));
        }

        $backup = $this->installer->install($this->runningPhar, $bytes, $to);

        return new UpdateResult(true, $check->current, $to, $backup);
    }

    /** Parses `sha256sum` output: `<hex>  deployer.phar` (or ` *deployer.phar`, or a bare hash). */
    private function expectedSha256(string $file): string
    {
        $line = trim(strtok($file, "\n") ?: '');
        if (preg_match('/^([0-9a-fA-F]{64})(?:\s+\*?(\S+))?$/D', $line, $m) !== 1
            || (isset($m[2]) && basename($m[2]) !== Release::PHAR_ASSET)) {
            throw new UpdateRefused(UpdateRefused::CHECKSUM, sprintf('The published %s is malformed.', Release::CHECKSUM_ASSET));
        }

        return strtolower($m[1]);
    }
}
