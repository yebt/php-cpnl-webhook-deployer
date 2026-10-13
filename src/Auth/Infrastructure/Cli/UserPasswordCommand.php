<?php

declare(strict_types=1);

namespace Deployer\Auth\Infrastructure\Cli;

use Deployer\Shared\Domain\ValidationFailed;
use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Command;
use Deployer\Shared\Infrastructure\Cli\Console;

/**
 * `user:password [username]`: sets the administrator password (creating the
 * administrator when none exists) and revokes all sessions. The password is read
 * from STDIN twice, never from argv, so it does not leak into the process list.
 */
final class UserPasswordCommand implements Command
{
    /** @var resource */
    private $input;

    /** @param resource|null $input */
    public function __construct(private readonly Application $app, $input = null)
    {
        $this->input = $input ?? STDIN;
    }

    public function name(): string
    {
        return 'user:password';
    }

    public function description(): string
    {
        return 'Set the admin password from STDIN (creates the admin if missing)';
    }

    public function run(array $args, Console $console): int
    {
        $password = $this->prompt('New password: ');
        $confirm = $this->prompt('Repeat password: ');
        if ($password === null || $confirm === null || !hash_equals($password, $confirm)) {
            $console->error('Passwords do not match.');

            return 1;
        }

        try {
            [$user, $created] = ($this->app->setPassword())($args[0] ?? null, $password);
        } catch (ValidationFailed $e) {
            foreach ($e->errors as $field => $message) {
                $console->error(sprintf('%s: %s', $field, is_array($message) ? implode(' ', $message) : $message));
            }

            return 1;
        }

        $console->line($created
            ? sprintf('Administrator "%s" created.', $user->username)
            : sprintf('Password updated for "%s"; all sessions were signed out.', $user->username));

        return 0;
    }

    private function prompt(string $label): ?string
    {
        $interactive = stream_isatty($this->input);
        if ($interactive) {
            fwrite(STDERR, $label);
            @shell_exec('stty -echo 2>/dev/null');
        }
        $line = fgets($this->input);
        if ($interactive) {
            @shell_exec('stty echo 2>/dev/null');
            fwrite(STDERR, PHP_EOL);
        }

        return $line === false ? null : rtrim($line, "\r\n");
    }
}
