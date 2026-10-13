<?php

declare(strict_types=1);

namespace Deployer\Projects\Domain;

use RuntimeException;

/** Port for the per-project pipeline file (YAML) kept on disk. */
interface InstructionsStore
{
    /** @throws RuntimeException when the file cannot be read */
    public function read(Project $project): string;
}
