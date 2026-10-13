<?php

declare(strict_types=1);

namespace Deployer\Projects\Infrastructure;

use Deployer\Projects\Domain\Project;
use Deployer\Projects\Domain\ProjectData;
use Deployer\Projects\Domain\ProjectRepository;
use PDO;
use RuntimeException;

final class SqliteProjectRepository implements ProjectRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $id): ?Project
    {
        return $this->fetchOne('SELECT * FROM projects WHERE id = ?', [$id]);
    }

    public function findBySlug(string $slug): ?Project
    {
        return $this->fetchOne('SELECT * FROM projects WHERE slug = ?', [$slug]);
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM projects ORDER BY name COLLATE NOCASE, id')->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function create(string $slug, string $name, string $folder, string $instructionsFile = Project::DEFAULT_INSTRUCTIONS_FILE, ?int $notifierId = null): Project
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO projects (slug, name, folder, instructions_file, notifier_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$slug, $name, $folder, $instructionsFile, $notifierId, $now, $now]);

        return new Project((int) $this->pdo->lastInsertId(), $slug, $name, $folder, $instructionsFile, $notifierId);
    }

    public function update(int $id, ProjectData $data): Project
    {
        $stmt = $this->pdo->prepare('UPDATE projects SET slug = ?, name = ?, folder = ?, instructions_file = ?, notifier_id = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$data->slug, $data->name, $data->folder, $data->instructionsFile, $data->notifierId, gmdate('Y-m-d H:i:s'), $id]);

        return $this->find($id) ?? throw new RuntimeException(sprintf('Project %d not found.', $id));
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
    }

    public function setWebhookTokenHash(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE projects SET webhook_token_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$hash, gmdate('Y-m-d H:i:s'), $id]);
    }

    /** @param list<mixed> $params */
    private function fetchOne(string $sql, array $params): ?Project
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Project
    {
        return new Project(
            (int) $row['id'],
            $row['slug'],
            $row['name'],
            $row['folder'],
            $row['instructions_file'],
            $row['notifier_id'] === null ? null : (int) $row['notifier_id'],
            $row['webhook_token_hash'],
        );
    }
}
