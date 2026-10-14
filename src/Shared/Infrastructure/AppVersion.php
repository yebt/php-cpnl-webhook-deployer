<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure;

/**
 * The running build's version. Release builds stamp a `VERSION` file at the
 * artifact root (see build/build-phar.php); a source checkout or an unstamped
 * build reports `dev`.
 */
final class AppVersion
{
    public const DEV = 'dev';
    /** Strict semver core with an optional prerelease suffix (build metadata is not used). */
    public const PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/D';

    private static ?string $current = null;

    /** Version of the code currently loaded (cached per process). */
    public static function current(): string
    {
        return self::$current ??= self::fromFile(dirname(__DIR__, 3) . '/VERSION');
    }

    public static function fromFile(string $path): string
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $version = is_string($raw) ? trim($raw) : '';

        return self::isValid($version) ? $version : self::DEV;
    }

    public static function isValid(string $version): bool
    {
        return $version === self::DEV || preg_match(self::PATTERN, $version) === 1;
    }
}
