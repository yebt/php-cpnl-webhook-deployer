<?php

declare(strict_types=1);

namespace Deployer\Projects\Infrastructure;

use Deployer\Projects\Domain\Project;
use Deployer\Projects\Domain\ProjectRepository;
use PDO;

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

    public function create(string $slug, string $name, string $folder, string $instructionsFile = 'deployer.yml'): Project
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO projects (slug, name, folder, instructions_file, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$slug, $name, $folder, $instructionsFile, $now, $now]);

        return new Project((int) $this->pdo->lastInsertId(), $slug, $name, $folder, $instructionsFile);
    }

    /** @param list<mixed> $params */
    private function fetchOne(string $sql, array $params): ?Project
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return new Project((int) $row['id'], $row['slug'], $row['name'], $row['folder'], $row['instructions_file']);
    }
}
