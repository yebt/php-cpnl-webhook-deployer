<?php

declare(strict_types=1);

namespace Deployer\Notifiers\Infrastructure;

use Deployer\Notifiers\Application\NotificationFailed;
use Deployer\Notifiers\Application\Port\Notifier;
use Deployer\Notifiers\Domain\NotifierConfig;
use Deployer\Shared\Application\Port\HttpClient;
use Throwable;

/** Telegram Bot API `sendMessage` adapter (HTML parse mode). */
final class TelegramNotifier implements Notifier
{
    private const API = 'https://api.telegram.org';
    private const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function send(NotifierConfig $channel, string $html): void
    {
        // The bot token is part of the URL: redact it from every error we surface.
        $url = sprintf('%s/bot%s/sendMessage', self::API, $channel->botToken);
        try {
            $payload = [
                'chat_id' => $channel->chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];
            if ($channel->threadId !== null) {
                $payload['message_thread_id'] = $channel->threadId;
            }
            $response = $this->http->postJson($url, $payload, self::TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            throw new NotificationFailed($this->redact($e->getMessage(), $channel));
        }

        $data = json_decode($response->body, true);
        if (is_array($data) && ($data['ok'] ?? false) === true) {
            return;
        }
        $description = is_array($data) && is_string($data['description'] ?? null) ? $data['description'] : 'unexpected response';

        throw new NotificationFailed($this->redact(sprintf('Telegram API error %d: %s', $response->status, $description), $channel));
    }

    private function redact(string $message, NotifierConfig $channel): string
    {
        $secret = $channel->botToken;
        $parts = array_filter([$secret, explode(':', $secret, 2)[1] ?? ''], static fn (string $s): bool => strlen($s) >= 8);

        return str_replace($parts, '***', $message);
    }
}
