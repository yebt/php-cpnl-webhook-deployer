<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Domain;

final class Step
{
    public function __construct(
        public readonly string $name,
        /** Shell command after `${VAR}` interpolation, executed with `bash -c`. */
        public readonly string $command,
        /** Seconds. */
        public readonly int $timeout,
        public readonly bool $continueOnError,
    ) {
    }
}
