<?php

declare(strict_types=1);

namespace Deployer\Projects\Domain;

final class Project
{
    public const DEFAULT_INSTRUCTIONS_FILE = 'deployer.yml';

    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        /** Absolute path; used as PROJECT_DIR and as the working directory of steps. */
        public readonly string $folder,
        /** Absolute path, or relative to the project folder (must stay inside it). */
        public readonly string $instructionsFile = self::DEFAULT_INSTRUCTIONS_FILE,
        public readonly ?int $notifierId = null,
        /** sha256 of the webhook token; the token itself is never stored. */
        public readonly ?string $webhookTokenHash = null,
        /** UTC `Y-m-d H:i:s`; null without a token or for tokens older than the column. */
        public readonly ?string $webhookTokenCreatedAt = null,
    ) {
    }

    public function instructionsPath(): string
    {
        if ($this->hasAbsoluteInstructionsPath()) {
            return $this->instructionsFile;
        }

        return rtrim($this->folder, '/') . '/' . $this->instructionsFile;
    }

    public function hasAbsoluteInstructionsPath(): bool
    {
        return str_starts_with($this->instructionsFile, '/');
    }
}
