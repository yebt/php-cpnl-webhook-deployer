<?php

declare(strict_types=1);

namespace Deployer\Projects\Domain;

final class Project
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        /** Absolute path; used as PROJECT_DIR and as the working directory of steps. */
        public readonly string $folder,
        /** Absolute path, or relative to the project folder. */
        public readonly string $instructionsFile = 'deployer.yml',
    ) {
    }

    public function instructionsPath(): string
    {
        if (str_starts_with($this->instructionsFile, '/')) {
            return $this->instructionsFile;
        }

        return rtrim($this->folder, '/') . '/' . $this->instructionsFile;
    }
}
