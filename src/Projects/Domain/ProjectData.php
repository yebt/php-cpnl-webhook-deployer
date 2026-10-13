<?php

declare(strict_types=1);

namespace Deployer\Projects\Domain;

/** Editable project fields, already validated. */
final class ProjectData
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $folder,
        public readonly string $instructionsFile,
        public readonly ?int $notifierId,
    ) {
    }
}
