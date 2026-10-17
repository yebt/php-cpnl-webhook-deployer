<?php

declare(strict_types=1);

namespace Deployer\Notifiers\Infrastructure;

use Deployer\Notifiers\Domain\NotifierConfig;
use Deployer\Notifiers\Domain\NotifierRepository;
use PDO;

final class SqliteNotifierRepository implements NotifierRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $id): ?NotifierConfig
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notifiers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), $this->pdo->query('SELECT * FROM notifiers ORDER BY name COLLATE NOCASE, id')->fetchAll());
    }

    public function create(string $name, string $type, string $botToken, string $chatId, ?int $threadId = null): NotifierConfig
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare('INSERT INTO notifiers (name, type, config, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $type, $this->config($botToken, $chatId, $threadId), $now, $now]);

        return new NotifierConfig((int) $this->pdo->lastInsertId(), $name, $type, $botToken, $chatId, $threadId);
    }

    public function update(NotifierConfig $notifier): void
    {
        $this->pdo->prepare('UPDATE notifiers SET name = ?, type = ?, config = ?, updated_at = ? WHERE id = ?')
            ->execute([$notifier->name, $notifier->type, $this->config($notifier->botToken, $notifier->chatId, $notifier->threadId), gmdate('Y-m-d H:i:s'), $notifier->id]);
    }

    public function delete(int $id): void
    {
        // Explicit detach as well as the FK's ON DELETE SET NULL, in case foreign keys are off.
        $this->pdo->prepare('UPDATE projects SET notifier_id = NULL WHERE notifier_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM notifiers WHERE id = ?')->execute([$id]);
    }

    /** Type-specific settings live in the JSON `config` column, so new optional keys need no schema migration. */
    private function config(string $botToken, string $chatId, ?int $threadId): string
    {
        $config = ['bot_token' => $botToken, 'chat_id' => $chatId];
        if ($threadId !== null) {
            $config['thread_id'] = $threadId;
        }

        return json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): NotifierConfig
    {
        $config = json_decode((string) $row['config'], true) ?: [];

        return new NotifierConfig(
            (int) $row['id'],
            $row['name'],
            $row['type'],
            (string) ($config['bot_token'] ?? ''),
            (string) ($config['chat_id'] ?? ''),
            is_int($config['thread_id'] ?? null) && $config['thread_id'] > 0 ? $config['thread_id'] : null,
        );
    }
}
