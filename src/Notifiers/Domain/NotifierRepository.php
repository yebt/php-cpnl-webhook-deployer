<?php

declare(strict_types=1);

namespace Deployer\Notifiers\Domain;

interface NotifierRepository
{
    public function find(int $id): ?NotifierConfig;

    /** @return list<NotifierConfig> ordered by name */
    public function all(): array;

    public function create(string $name, string $type, string $botToken, string $chatId): NotifierConfig;

    public function update(NotifierConfig $notifier): void;

    /** Projects using it are detached (notifier_id set to NULL). */
    public function delete(int $id): void;
}
