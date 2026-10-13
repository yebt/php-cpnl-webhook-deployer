<?php

declare(strict_types=1);

namespace Deployer\Tests\Api;

use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Tests\Support\ApiTestCase;

final class WebhookApiTest extends ApiTestCase
{
    /** @var array<string,mixed> */
    private array $project;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
        $this->project = $this->createProjectViaApi('shop');
        $response = $this->call('POST', '/api/projects/' . $this->project['id'] . '/token');
        self::assertSame(201, $response->status, $response->body);
        $this->token = $this->json($response)['token'];
        // Webhook callers are not browsers: no session, no CSRF.
        $this->cookies = [];
        $this->csrf = null;
    }

    public function testTokenIsShownOnceAndStoredHashed(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->token);
        $hash = $this->app->pdo()->query('SELECT webhook_token_hash FROM projects')->fetchColumn();
        self::assertSame(hash('sha256', $this->token), $hash);

        $this->setUpSession();
        $project = $this->call('GET', '/api/projects/' . $this->project['id']);
        self::assertTrue($this->json($project)['project']['has_webhook_token']);
        self::assertStringNotContainsString($this->token, $project->body);
        self::assertStringNotContainsString($hash, $project->body);
    }

    public function testValidTokenQueuesARunAndStartsTheRunner(): void
    {
        $response = $this->call('POST', '/hooks/shop', ['ref' => 'refs/heads/main', 'after' => 'abc123', 'head_commit' => ['message' => "Fix bug\n\nlong body", 'author' => ['name' => 'Ana']]], ['X-Deployer-Token' => $this->token, 'X-GitHub-Event' => 'push']);

        self::assertSame(202, $response->status, $response->body);
        $runId = $this->json($response)['run_id'];
        $run = $this->app->runs()->find($runId);
        self::assertSame('webhook', $run?->trigger);
        self::assertSame([
            'event' => 'push',
            'ref' => 'refs/heads/main',
            'branch' => 'main',
            'commit' => 'abc123',
            'message' => 'Fix bug',
            'author' => 'Ana',
        ], $run?->triggerMeta);
        self::assertSame([$this->project['id']], $this->launcher->launched);
    }

    public function testMissingWrongOrStaleTokensAreRejectedWithoutARun(): void
    {
        self::assertSame(401, $this->call('POST', '/hooks/shop')->status);
        self::assertSame(401, $this->call('POST', '/hooks/shop', headers: ['X-Deployer-Token' => 'wrong'])->status);
        self::assertSame(401, $this->call('POST', '/hooks/unknown', headers: ['X-Deployer-Token' => $this->token])->status);

        $this->setUpSession();
        $this->call('POST', '/api/projects/' . $this->project['id'] . '/token');
        $this->cookies = [];
        $stale = $this->call('POST', '/hooks/shop', headers: ['X-Deployer-Token' => $this->token]);

        self::assertSame(401, $stale->status);
        self::assertSame('invalid_token', $this->json($stale)['error']['code']);
        self::assertSame(0, (int) $this->app->pdo()->query('SELECT COUNT(*) FROM runs')->fetchColumn());
        self::assertSame([], $this->launcher->launched);
    }

    public function testProjectWithoutTokenRejectsEverything(): void
    {
        $this->setUpSession();
        $this->createProjectViaApi('fresh');
        $this->cookies = [];

        self::assertSame(401, $this->call('POST', '/hooks/fresh', headers: ['X-Deployer-Token' => ''])->status);
    }

    public function testOnlyPostIsAccepted(): void
    {
        self::assertSame(405, $this->call('GET', '/hooks/shop', headers: ['X-Deployer-Token' => $this->token])->status);
    }

    public function testNonJsonOrOversizedBodiesStillTriggerWithoutMetadata(): void
    {
        $plain = $this->call('POST', '/hooks/shop', headers: ['X-Deployer-Token' => $this->token], rawBody: 'not json');
        $oversized = $this->app->handle(new Request('POST', '/hooks/shop', headers: ['x-deployer-token' => $this->token], bodyTooLarge: true));

        self::assertSame(202, $plain->status);
        self::assertSame(202, $oversized->status);
        self::assertNull($this->app->runs()->find($this->json($plain)['run_id'])?->triggerMeta);
    }

    private function setUpSession(): void
    {
        $response = $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'correct horse battery']);
        $this->csrf = $this->json($response)['csrf_token'];
    }
}
