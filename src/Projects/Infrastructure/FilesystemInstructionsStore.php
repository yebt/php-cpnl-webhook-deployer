<?php

declare(strict_types=1);

namespace Deployer\Projects\Infrastructure;

use Deployer\Projects\Domain\InstructionsStore;
use Deployer\Projects\Domain\Project;
use RuntimeException;

final class FilesystemInstructionsStore implements InstructionsStore
{
    public function read(Project $project): string
    {
        $path = $project->instructionsPath();
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Instructions file not found or not readable: %s', $path));
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Cannot read instructions file: %s', $path));
        }

        return $contents;
    }
}
