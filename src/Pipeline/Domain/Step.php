<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Domain;

final class Step
{
    /** The commands joined with newlines, as stored with the run and shown in the API. */
    public readonly string $command;

    /**
     * @param non-empty-list<string> $commands shell commands after `${VAR}` interpolation;
     *                                         run in order in the same shell
     */
    public function __construct(
        public readonly string $name,
        public readonly array $commands,
        /** Seconds. */
        public readonly int $timeout,
        public readonly bool $continueOnError,
        /**
         * Strict mode (default) runs the script like GitHub Actions' bash:
         * `bash --noprofile --norc -eo pipefail`, so the first failing command
         * fails the step. When false, plain `bash -c` (only the last command's
         * exit code counts).
         */
        public readonly bool $strict = true,
    ) {
        $this->command = implode("\n", $commands);
    }
}
