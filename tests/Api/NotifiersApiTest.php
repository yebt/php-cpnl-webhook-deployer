<?php

declare(strict_types=1);

namespace Deployer\Tests\Api;

use Deployer\Tests\Support\ApiTestCase;

final class NotifiersApiTest extends ApiTestCase
{
    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    public function testCreateListAndGetNeverExposeTheBotToken(): void
    {
        $created = $this->call('POST', '/api/notifiers', ['name' => 'Ops chat', 'type' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '-100123']);

        self::assertSame(201, $created->status, $created->body);
        $notifier = $this->json($created)['notifier'];
        self::assertSame('Ops chat', $notifier['name']);
        self::assertSame('telegram', $notifier['type']);
        self::assertSame('-100123', $notifier['chat_id']);
        self::assertSame('****Dsaw', $notifier['bot_token_masked']);

        $list = $this->call('GET', '/api/notifiers');
        $get = $this->call('GET', '/api/notifiers/' . $notifier['id']);
        foreach ([$created, $list, $get] as $response) {
            self::assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', $response->body);
        }
        self::assertCount(1, $this->json($list)['notifiers']);
        self::assertSame($notifier, $this->json($get)['notifier']);
    }

    public function testValidation(): void
    {
        $response = $this->call('POST', '/api/notifiers', ['name' => '', 'type' => 'slack', 'bot_token' => 'x', 'chat_id' => 'abc']);

        self::assertSame(422, $response->status);
        $details = $this->json($response)['error']['details'];
        self::assertSame(['name', 'type', 'bot_token', 'chat_id'], array_keys($details));
    }

    public function testUpdateKeepsTheTokenWhenOmitted(): void
    {
        $id = $this->json($this->call('POST', '/api/notifiers', ['name' => 'A', 'type' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '42']))['notifier']['id'];

        $updated = $this->call('PUT', '/api/notifiers/' . $id, ['name' => 'B', 'chat_id' => '@my_channel']);

        self::assertSame(200, $updated->status, $updated->body);
        self::assertSame('B', $this->json($updated)['notifier']['name']);
        self::assertSame('@my_channel', $this->json($updated)['notifier']['chat_id']);
        self::assertSame('****Dsaw', $this->json($updated)['notifier']['bot_token_masked']);
        $notifier = $this->app->notifiers()->find($id);
        self::assertSame(self::TOKEN, $notifier?->botToken);
    }

    public function testDeleteDetachesProjects(): void
    {
        $id = $this->json($this->call('POST', '/api/notifiers', ['name' => 'A', 'type' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '42']))['notifier']['id'];
        $project = $this->createProjectViaApi('shop', extra: ['notifier_id' => $id]);
        self::assertSame($id, $project['notifier_id']);

        self::assertSame(204, $this->call('DELETE', '/api/notifiers/' . $id)->status);

        self::assertSame(404, $this->call('GET', '/api/notifiers/' . $id)->status);
        self::assertNull($this->json($this->call('GET', '/api/projects/' . $project['id']))['project']['notifier_id']);
    }

    public function testUnknownNotifierIs404(): void
    {
        $response = $this->call('PUT', '/api/notifiers/999', ['name' => 'x']);

        self::assertSame(404, $response->status);
        self::assertSame('not_found', $this->json($response)['error']['code']);
    }
}
