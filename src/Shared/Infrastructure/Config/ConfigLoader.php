<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Config;

use RuntimeException;

/**
 * Resolves configuration with the precedence: environment > config file > defaults.
 *
 * The config file is `deployer.config.php` placed next to the artifact (the phar,
 * or the repository root when running from source). It must return an array with
 * any of the keys `data_dir`, `php_cli`, `kill_grace_seconds`, `update_repository`,
 * `update_token`, `update_prereleases`. A PHP file is used
 * on purpose: when the artifact lives in a web root, Apache executes it instead of
 * serving its contents.
 */
final class ConfigLoader
{
    public const CONFIG_FILE = 'deployer.config.php';

    /**
     * @param string               $entryScript absolute path of the running phar or of bin/deployer
     * @param array<string,string> $env
     */
    public static function load(
        string $entryScript,
        bool $isPhar,
        array $env,
        string $sapi,
        string $phpBinary,
    ): AppConfig {
        $baseDir = $isPhar ? dirname($entryScript) : dirname($entryScript, 2);
        $configFile = $baseDir . '/' . self::CONFIG_FILE;
        $file = [];
        if (is_file($configFile)) {
            $loaded = (static fn (string $path): mixed => require $path)($configFile);
            if (!is_array($loaded)) {
                throw new RuntimeException(sprintf('%s must return an array.', $configFile));
            }
            $file = $loaded;
        } else {
            $configFile = null;
        }

        $dataDir = self::nonEmpty($env['DEPLOYER_DATA_DIR'] ?? null)
            ?? self::nonEmpty($file['data_dir'] ?? null)
            ?? ($isPhar ? dirname($baseDir) . '/deployer-data' : $baseDir . '/data');
        if (!str_starts_with($dataDir, '/')) {
            $dataDir = $baseDir . '/' . $dataDir;
        }

        // Under PHP-FPM, PHP_BINARY points to php-fpm, which cannot run scripts.
        $defaultCli = $sapi === 'cli' ? $phpBinary : 'php';
        $phpCli = self::nonEmpty($env['DEPLOYER_PHP_CLI'] ?? null)
            ?? self::nonEmpty($file['php_cli'] ?? null)
            ?? $defaultCli;

        $grace = $file['kill_grace_seconds'] ?? 5.0;
        if ((!is_int($grace) && !is_float($grace)) || $grace < 0) {
            throw new RuntimeException('kill_grace_seconds must be a non-negative number.');
        }

        $prereleases = $file['update_prereleases'] ?? false;
        if (!is_bool($prereleases)) {
            throw new RuntimeException('update_prereleases must be true or false.');
        }

        return new AppConfig(
            dataDir: rtrim($dataDir, '/'),
            phpCli: $phpCli,
            entryScript: $entryScript,
            isPhar: $isPhar,
            killGraceSeconds: (float) $grace,
            configFile: $configFile,
            updateRepository: self::nonEmpty($env['DEPLOYER_UPDATE_REPOSITORY'] ?? null)
                ?? self::nonEmpty($file['update_repository'] ?? null)
                ?? AppConfig::DEFAULT_UPDATE_REPOSITORY,
            updateToken: self::nonEmpty($env['DEPLOYER_UPDATE_TOKEN'] ?? null) ?? self::nonEmpty($file['update_token'] ?? null),
            updatePrereleases: $prereleases,
            // Environment only (never the config file): local self-update simulations against a loopback server.
            updateApiUrl: self::nonEmpty($env['DEPLOYER_UPDATE_API'] ?? null) ?? 'https://api.github.com',
        );
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
