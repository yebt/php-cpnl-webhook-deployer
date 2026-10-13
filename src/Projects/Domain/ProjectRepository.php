<?php

declare(strict_types=1);

namespace Deployer\Projects\Domain;

interface ProjectRepository
{
    public function find(int $id): ?Project;

    public function findBySlug(string $slug): ?Project;

    public function create(string $slug, string $name, string $folder, string $instructionsFile = 'deployer.yml'): Project;
}
