<?php

declare(strict_types=1);

namespace Deployer\Updates\Application;

use RuntimeException;

/** The update was deliberately not applied; $reason is a stable machine-readable code. */
final class UpdateRefused extends RuntimeException
{
    public const NOT_PHAR = 'not_running_from_phar';
    public const NO_RELEASE = 'no_release';
    public const DEV_BUILD = 'dev_build_requires_force';
    public const DOWNGRADE = 'would_downgrade';
    public const NOT_WRITABLE = 'not_writable';
    public const MISSING_ASSET = 'missing_asset';
    public const CHECKSUM = 'checksum_mismatch';
    public const INVALID_PHAR = 'invalid_phar';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
