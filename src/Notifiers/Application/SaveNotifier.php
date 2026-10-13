<?php

declare(strict_types=1);

namespace Deployer\Notifiers\Application;

use Deployer\Notifiers\Domain\NotifierConfig;
use Deployer\Notifiers\Domain\NotifierRepository;
use Deployer\Shared\Domain\ValidationFailed;

final class SaveNotifier
{
    private const BOT_TOKEN_PATTERN = '/^\d{3,20}:[A-Za-z0-9_-]{20,100}$/';
    private const CHAT_ID_PATTERN = '/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/';

    public function __construct(private readonly NotifierRepository $notifiers)
    {
    }

    /**
     * Creates ($existing null) or updates a notifier. On update, missing fields keep
     * their value; an empty or missing bot_token keeps the stored token.
     *
     * @param array<string,mixed> $input
     * @throws ValidationFailed
     */
    public function __invoke(array $input, ?NotifierConfig $existing = null): NotifierConfig
    {
        $name = self::string($input, 'name') ?? $existing?->name ?? '';
        $type = self::string($input, 'type') ?? $existing?->type ?? '';
        $token = self::string($input, 'bot_token');
        $token = $token === '' || $token === null ? ($existing?->botToken ?? '') : $token;
        $chatId = self::string($input, 'chat_id') ?? $existing?->chatId ?? '';

        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Use 1-100 characters.';
        }
        if (!in_array($type, NotifierConfig::TYPES, true)) {
            $errors['type'] = sprintf('Supported types: %s.', implode(', ', NotifierConfig::TYPES));
        }
        if (!preg_match(self::BOT_TOKEN_PATTERN, $token)) {
            $errors['bot_token'] = 'Expected a Telegram bot token like 123456:ABC-DEF...';
        }
        if (!preg_match(self::CHAT_ID_PATTERN, $chatId)) {
            $errors['chat_id'] = 'Expected a numeric chat id (e.g. -100123) or @channel_name.';
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        if ($existing === null) {
            return $this->notifiers->create($name, $type, $token, $chatId);
        }
        $updated = new NotifierConfig($existing->id, $name, $type, $token, $chatId);
        $this->notifiers->update($updated);

        return $updated;
    }

    /** @param array<string,mixed> $input */
    private static function string(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }

        return is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
    }
}
