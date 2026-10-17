<?php

declare(strict_types=1);

namespace Deployer\Tests\Notifiers;

use Deployer\Notifiers\Application\NotificationFailed;
use Deployer\Notifiers\Domain\NotifierConfig;
use Deployer\Notifiers\Infrastructure\TelegramNotifier;
use Deployer\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class TelegramNotifierTest extends TestCase
{
    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    private FakeHttpClient $http;
    private NotifierConfig $channel;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->channel = new NotifierConfig(1, 'Ops', 'telegram', self::TOKEN, '-100123');
    }

    public function testPostsAnHtmlSendMessageRequest(): void
    {
        (new TelegramNotifier($this->http))->send($this->channel, '<b>hi</b>');

        self::assertSame('https://api.telegram.org/bot' . self::TOKEN . '/sendMessage', $this->http->requests[0]['url']);
        self::assertSame([
            'chat_id' => '-100123',
            'text' => '<b>hi</b>',
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $this->http->requests[0]['body']);
    }

    public function testSendsTheForumTopicThreadIdWhenConfigured(): void
    {
        $channel = new NotifierConfig(1, 'Ops', 'telegram', self::TOKEN, '-100123', 42);

        (new TelegramNotifier($this->http))->send($channel, '<b>hi</b>');

        self::assertSame(42, $this->http->requests[0]['body']['message_thread_id']);
    }

    public function testOmitsTheThreadIdWhenNotConfigured(): void
    {
        (new TelegramNotifier($this->http))->send($this->channel, 'x');

        self::assertArrayNotHasKey('message_thread_id', $this->http->requests[0]['body']);
    }

    public function testApiErrorsFailWithTheTelegramDescription(): void
    {
        $this->http->willReturn(400, '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}');

        $this->expectException(NotificationFailed::class);
        $this->expectExceptionMessage('Telegram API error 400: Bad Request: chat not found');

        (new TelegramNotifier($this->http))->send($this->channel, 'x');
    }

    public function testTransportErrorsNeverLeakTheBotToken(): void
    {
        $this->http->willFail('Could not resolve host for https://api.telegram.org/bot' . self::TOKEN . '/sendMessage');

        try {
            (new TelegramNotifier($this->http))->send($this->channel, 'x');
            self::fail('Expected NotificationFailed');
        } catch (NotificationFailed $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', $e->getMessage());
            self::assertStringContainsString('Could not resolve host', $e->getMessage());
        }
    }

    public function testNonJsonResponsesFail(): void
    {
        $this->http->willReturn(502, '<html>Bad gateway</html>');

        $this->expectException(NotificationFailed::class);

        (new TelegramNotifier($this->http))->send($this->channel, 'x');
    }
}
