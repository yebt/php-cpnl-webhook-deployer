<?php

declare(strict_types=1);

namespace Deployer\Notifiers\Domain;

/** A configured notification channel. v1 only supports Telegram. */
final class NotifierConfig
{
    public const TYPE_TELEGRAM = 'telegram';
    public const TYPES = [self::TYPE_TELEGRAM];

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        /** Secret: never returned by the API, never logged. */
        public readonly string $botToken,
        public readonly string $chatId,
        /** Telegram forum topic (`message_thread_id`); null posts to the chat's general thread. */
        public readonly ?int $threadId = null,
    ) {
    }

    public function maskedBotToken(): string
    {
        return '****' . substr($this->botToken, -4);
    }
}
