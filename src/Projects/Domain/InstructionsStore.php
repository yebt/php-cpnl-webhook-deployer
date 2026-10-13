<?php

declare(strict_types=1);

namespace Deployer\Projects\Domain;

use RuntimeException;

/** Port for the per-project pipeline file (YAML) kept on disk. */
interface InstructionsStore
{
    /**
     * @throws InstructionsPathOutsideFolder
     * @throws RuntimeException when the file cannot be read
     */
    public function read(Project $project): string;

    /** @throws InstructionsPathOutsideFolder */
    public function exists(Project $project): bool;

    /**
     * Atomically replaces the file contents. The parent directory must exist.
     *
     * @throws InstructionsPathOutsideFolder
     * @throws RuntimeException when the file cannot be written
     */
    public function write(Project $project, string $contents): void;
}
