<?php

declare(strict_types=1);

namespace Deployer\Projects\Domain;

use RuntimeException;

/** A relative instructions path that resolves (e.g. through a symlink) outside the project folder. */
final class InstructionsPathOutsideFolder extends RuntimeException
{
    public function __construct(string $path)
    {
        parent::__construct(sprintf('Instructions path resolves outside the project folder: %s', $path));
    }
}
