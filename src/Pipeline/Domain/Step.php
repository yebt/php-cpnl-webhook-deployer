<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Domain;

final class Step
{
    public function __construct(
        public readonly string $name,
        /** Shell script after `${VAR}` interpolation. */
        public readonly string $command,
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
    }
}
