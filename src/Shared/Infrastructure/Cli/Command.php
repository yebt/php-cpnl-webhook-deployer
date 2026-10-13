<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Cli;

interface Command
{
    public function name(): string;

    public function description(): string;

    /** @param list<string> $args positional arguments after the command name */
    public function run(array $args, Console $console): int;
}
