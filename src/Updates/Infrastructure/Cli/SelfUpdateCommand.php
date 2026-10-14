<?php

declare(strict_types=1);

namespace Deployer\Updates\Infrastructure\Cli;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Command;
use Deployer\Shared\Infrastructure\Cli\Console;
use Deployer\Updates\Application\UpdateFailed;
use Deployer\Updates\Application\UpdateRefused;

/**
 * `self-update [--check] [--force]`
 *
 * Exit codes: 0 updated or already up to date; 1 refused or failed;
 * 2 (--check only) an update is available.
 */
final class SelfUpdateCommand implements Command
{
    public const EXIT_UPDATE_AVAILABLE = 2;

    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'self-update';
    }

    public function description(): string
    {
        return 'Update this phar to the latest release [--check] [--force]';
    }

    public function run(array $args, Console $console): int
    {
        $unknown = array_diff($args, ['--check', '--force']);
        if ($unknown !== []) {
            $console->error(sprintf('Unknown option %s. Usage: self-update [--check] [--force]', implode(', ', $unknown)));

            return 1;
        }

        try {
            return in_array('--check', $args, true)
                ? $this->check($console)
                : $this->apply(in_array('--force', $args, true), $console);
        } catch (UpdateRefused $e) {
            $console->error('Update refused: ' . $e->getMessage());
        } catch (UpdateFailed $e) {
            $console->error('Update failed: ' . $e->getMessage());
        }

        return 1;
    }

    private function check(Console $console): int
    {
        $check = ($this->app->checkForUpdate())();
        if ($check->latest === null) {
            $console->line(sprintf('Running %s; no published release found.', $check->current));

            return 0;
        }
        if (!$check->available) {
            $console->line(sprintf('Deployer %s is up to date (latest release: %s).', $check->current, $check->latest->version));

            return 0;
        }
        $console->line(sprintf('Update available: %s -> %s', $check->current, $check->latest->version));
        if ($check->latest->htmlUrl !== '') {
            $console->line('Release notes: ' . $check->latest->htmlUrl);
        }
        if ($check->currentIsDev) {
            $console->line('This is a development build: applying the release needs --force.');
        }

        return self::EXIT_UPDATE_AVAILABLE;
    }

    private function apply(bool $force, Console $console): int
    {
        $result = ($this->app->applyUpdate())($force);
        if (!$result->updated) {
            $console->line(sprintf('Deployer %s is already up to date.', $result->from));

            return 0;
        }
        $console->line(sprintf('Updated %s -> %s.', $result->from, $result->to));
        $console->line(sprintf('Previous version kept at %s (rename it back to roll back).', $result->backupPath));
        $console->line('Database migrations run automatically on the next request or command.');

        return 0;
    }
}
