<?php

declare(strict_types=1);

namespace Deployer\Tests\Api;

use Deployer\Shared\Infrastructure\Cli\Console;
use Deployer\Tests\Support\ApiTestCase;

final class ActionsApiTest extends ApiTestCase
{
    private const YAML = <<<'YAML'
        vars:
          GREETING: hello
        steps:
          - name: Deploy
            run: echo deploying
        actions:
          clear-cache:
            name: Clear cache
            description: Empties the cache.
            steps:
              - name: Clear
                run: echo "${GREETING} clearing"
              - name: Report
                run: echo done
          boom:
            steps:
              - name: Explode
                run: exit 5
        YAML;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    public function testListsTheActionsOfTheCurrentFile(): void
    {
        $project = $this->createProjectViaApi('shop', self::YAML);

        $response = $this->call('GET', '/api/projects/' . $project['id'] . '/actions');

        self::assertSame(200, $response->status, $response->body);
        self::assertSame([
            'actions' => [
                ['id' => 'clear-cache', 'name' => 'Clear cache', 'description' => 'Empties the cache.', 'steps' => ['Clear', 'Report']],
                ['id' => 'boom', 'name' => 'boom', 'description' => null, 'steps' => ['Explode']],
            ],
            'error' => null,
        ], $this->json($response));
    }

    public function testListingReportsAnInvalidOrMissingFile(): void
    {
        $none = $this->createProjectViaApi('plain');
        self::assertSame(['actions' => [], 'error' => null], $this->json($this->call('GET', '/api/projects/' . $none['id'] . '/actions')));

        $broken = $this->createProjectViaApi('broken', "steps: []\nactions:\n  x:\n    steps: [{name: a, run: b}]\n");
        $body = $this->json($this->call('GET', '/api/projects/' . $broken['id'] . '/actions'));
        self::assertSame([], $body['actions']);
        self::assertStringContainsString('"steps" must be a non-empty list', (string) $body['error']);

        $missing = $this->createProjectViaApi('missing', null);
        $body = $this->json($this->call('GET', '/api/projects/' . $missing['id'] . '/actions'));
        self::assertSame([], $body['actions']);
        self::assertStringContainsString('Instructions file not found', (string) $body['error']);

        self::assertSame(404, $this->call('GET', '/api/projects/999/actions')->status);
    }

    public function testRunningAnActionQueuesARunThatExecutesOnlyItsSteps(): void
    {
        $project = $this->createProjectViaApi('shop', self::YAML);

        $response = $this->call('POST', '/api/projects/' . $project['id'] . '/actions/clear-cache/runs');

        self::assertSame(202, $response->status, $response->body);
        $body = $this->json($response);
        self::assertSame(['run_id' => $body['run_id'], 'status' => 'queued', 'action' => 'clear-cache'], $body);
        self::assertSame([$project['id']], $this->launcher->launched);

        $run = $this->json($this->call('GET', '/api/runs/' . $body['run_id']))['run'];
        self::assertSame('manual', $run['trigger']);
        self::assertSame('clear-cache', $run['action']);
        self::assertSame('Clear cache', $run['action_name']);

        ($this->app->drainProjectQueue())($project['id']);
        $run = $this->json($this->call('GET', '/api/runs/' . $body['run_id']))['run'];
        self::assertSame('success', $run['status']);
        self::assertSame(['Clear', 'Report'], array_column($run['steps'], 'name'));

        $lines = array_column($this->json($this->call('GET', '/api/runs/' . $body['run_id'] . '/logs'))['lines'], 'line');
        self::assertContains('hello clearing', $lines);
        self::assertNotContains('deploying', $lines);
        self::assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_contains($l, 'action: clear-cache')));

        $listed = $this->json($this->call('GET', '/api/runs', query: ['project' => (string) $project['id']]))['runs'];
        self::assertSame(['clear-cache'], array_column($listed, 'action'));
    }

    public function testDeployRunsHaveNoAction(): void
    {
        $project = $this->createProjectViaApi('shop', self::YAML);
        $runId = $this->json($this->call('POST', '/api/projects/' . $project['id'] . '/runs'))['run_id'];

        $run = $this->json($this->call('GET', '/api/runs/' . $runId))['run'];

        self::assertNull($run['action']);
        self::assertNull($run['action_name']);
    }

    public function testUnknownActionOrInvalidFileIsRejectedWithoutARun(): void
    {
        $project = $this->createProjectViaApi('shop', self::YAML);

        $unknown = $this->call('POST', '/api/projects/' . $project['id'] . '/actions/nope/runs');
        self::assertSame(404, $unknown->status);
        self::assertSame('unknown_action', $this->json($unknown)['error']['code']);

        file_put_contents($project['folder'] . '/deployer.yml', "steps: oops\n");
        $invalid = $this->call('POST', '/api/projects/' . $project['id'] . '/actions/clear-cache/runs');
        self::assertSame(422, $invalid->status);
        self::assertStringContainsString('invalid_instructions', $invalid->body);

        self::assertSame(404, $this->call('POST', '/api/projects/999/actions/clear-cache/runs')->status);
        self::assertSame([], $this->launcher->launched);
        self::assertSame(0, (int) $this->app->pdo()->query('SELECT COUNT(*) FROM runs')->fetchColumn());
    }

    public function testRunningAnActionNeedsASessionAndCsrf(): void
    {
        $project = $this->createProjectViaApi('shop', self::YAML);
        $path = '/api/projects/' . $project['id'] . '/actions/clear-cache/runs';

        self::assertSame(403, $this->call('POST', $path, headers: ['x-csrf-token' => 'nope'])->status);
        $this->cookies = [];
        self::assertSame(401, $this->call('POST', $path)->status);
        self::assertSame(401, $this->call('GET', '/api/projects/' . $project['id'] . '/actions')->status);
    }

    public function testRetryKeepsTheAction(): void
    {
        $project = $this->createProjectViaApi('shop', self::YAML);
        $failed = $this->json($this->call('POST', '/api/projects/' . $project['id'] . '/actions/boom/runs'))['run_id'];
        ($this->app->drainProjectQueue())($project['id']);
        self::assertSame('failed', $this->json($this->call('GET', "/api/runs/{$failed}"))['run']['status']);

        $retryId = $this->json($this->call('POST', "/api/runs/{$failed}/retry"))['run_id'];
        ($this->app->drainProjectQueue())($project['id']);

        $retry = $this->json($this->call('GET', "/api/runs/{$retryId}"))['run'];
        self::assertSame('retry', $retry['trigger']);
        self::assertSame('boom', $retry['action']);
        self::assertSame('boom', $retry['action_name']);
        self::assertSame(['Explode'], array_column($retry['steps'], 'name'));
    }

    public function testAnActionRemovedBeforeTheRunStartsFailsClearly(): void
    {
        $project = $this->createProjectViaApi('shop', self::YAML);
        $runId = $this->json($this->call('POST', '/api/projects/' . $project['id'] . '/actions/clear-cache/runs'))['run_id'];
        file_put_contents($project['folder'] . '/deployer.yml', "steps:\n  - {name: Deploy, run: echo deploying}\n");

        ($this->app->drainProjectQueue())($project['id']);

        $run = $this->json($this->call('GET', "/api/runs/{$runId}"))['run'];
        self::assertSame('failed', $run['status']);
        self::assertStringContainsString('Action "clear-cache" is not defined', (string) $run['error']);
        self::assertSame([], $run['steps']);
    }

    public function testWebhookTriggersAnActionAfterVerifyingTheToken(): void
    {
        $project = $this->createProjectViaApi('shop', self::YAML);
        $token = $this->json($this->call('POST', '/api/projects/' . $project['id'] . '/token'))['token'];
        $this->cookies = [];
        $this->csrf = null;

        $ok = $this->call('POST', '/hooks/shop/actions/clear-cache', headers: ['X-Deployer-Token' => $token]);
        self::assertSame(202, $ok->status, $ok->body);
        $body = $this->json($ok);
        self::assertSame('clear-cache', $body['action']);
        $run = $this->app->runs()->find($body['run_id']);
        self::assertSame('webhook', $run?->trigger);
        self::assertSame('clear-cache', $run?->action);

        // The token is checked first: same 401 whatever the action or project.
        self::assertSame(401, $this->call('POST', '/hooks/shop/actions/nope', headers: ['X-Deployer-Token' => 'wrong'])->status);
        self::assertSame(401, $this->call('POST', '/hooks/shop/actions/clear-cache')->status);
        self::assertSame(401, $this->call('POST', '/hooks/other/actions/clear-cache', headers: ['X-Deployer-Token' => $token])->status);

        $unknown = $this->call('POST', '/hooks/shop/actions/nope', headers: ['X-Deployer-Token' => $token]);
        self::assertSame(404, $unknown->status);
        self::assertStringContainsString('unknown_action', $unknown->body);

        file_put_contents($project['folder'] . '/deployer.yml', "steps: oops\n");
        $invalid = $this->call('POST', '/hooks/shop/actions/clear-cache', headers: ['X-Deployer-Token' => $token]);
        self::assertSame(422, $invalid->status);
        self::assertStringContainsString('invalid_instructions', $invalid->body);
    }

    public function testCliTriggerAcceptsAnAction(): void
    {
        $project = $this->createProjectViaApi('shop', self::YAML);

        [$code, $out, $err] = $this->cli(['run:trigger', 'shop', '--action=clear-cache']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('action "clear-cache"', $out);
        $run = $this->app->runs()->search(new \Deployer\Runs\Domain\RunFilter($project['id']))[0];
        self::assertSame('cli', $run->trigger);
        self::assertSame('clear-cache', $run->action);
        self::assertSame('Clear cache', $run->actionName);

        [$code, , $err] = $this->cli(['run:trigger', 'shop', '--action=nope']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown action "nope"', $err);

        [$code, , $err] = $this->cli(['run:trigger', 'shop', '--bogus']);
        self::assertSame(1, $code);
        self::assertStringContainsString('--bogus', $err);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function cli(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = $this->app->runCli(['deployer', ...$args], new Console($out, $err));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
