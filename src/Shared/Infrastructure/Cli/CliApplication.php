<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Cli;

use Throwable;

/**
 * Minimal command registry and dispatcher: `deployer <command> [args...]`.
 */
final class CliApplication
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function add(Command $command): self
    {
        $this->commands[$command->name()] = $command;

        return $this;
    }

    /** @param list<string> $argv full argv including the script name */
    public function run(array $argv, Console $console): int
    {
        $name = $argv[1] ?? 'list';
        $args = array_values(array_slice($argv, 2));

        if ($name === 'list' || $name === 'help' || $name === '--help' || $name === '-h') {
            $this->printList($console);

            return 0;
        }

        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $console->error(sprintf('Unknown command "%s".', $name));
            $this->printList($console);

            return 1;
        }

        try {
            return $command->run($args, $console);
        } catch (Throwable $e) {
            $console->error(sprintf('Error: %s', $e->getMessage()));

            return 1;
        }
    }

    private function printList(Console $console): void
    {
        $console->line('Usage: deployer <command> [arguments]');
        $console->line();
        $console->line('Commands:');
        $names = array_keys($this->commands);
        sort($names);
        foreach ($names as $name) {
            $console->line(sprintf('  %-14s %s', $name, $this->commands[$name]->description()));
        }
    }
}
