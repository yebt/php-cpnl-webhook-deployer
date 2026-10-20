<?php

declare(strict_types=1);

namespace Deployer\Projects\Domain;

interface ProjectRepository
{
    public function find(int $id): ?Project;

    public function findBySlug(string $slug): ?Project;

    /** @return list<Project> ordered by name */
    public function all(): array;

    public function create(string $slug, string $name, string $folder, string $instructionsFile = Project::DEFAULT_INSTRUCTIONS_FILE, ?int $notifierId = null): Project;

    public function update(int $id, ProjectData $data): Project;

    public function delete(int $id): void;

    /** Stores the hash of a new token and records when it was generated. */
    public function setWebhookTokenHash(int $id, string $hash): void;

    /** Removes the token: the webhook rejects every request until a new one is generated. */
    public function clearWebhookToken(int $id): void;
}
