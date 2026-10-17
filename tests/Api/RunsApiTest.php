<?php

declare(strict_types=1);

namespace Deployer\Tests\Api;

use Deployer\Tests\Support\ApiTestCase;

final class RunsApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    public function testManualTriggerQueuesAndLaunches(): void
    {
        $project = $this->createProjectViaApi('shop');

        $response = $this->call('POST', '/api/projects/' . $project['id'] . '/runs');

        self::assertSame(202, $response->status);
        $run = $this->json($this->call('GET', '/api/runs/' . $this->json($response)['run_id']))['run'];
        self::assertSame('queued', $run['status']);
        self::assertSame('manual', $run['trigger']);
        self::assertSame([$project['id']], $this->launcher->launched);
        self::assertSame(404, $this->call('POST', '/api/projects/999/runs')->status);
    }

    public function testDetailIncludesStepsWithDurationsAndExitCodes(): void
    {
        $project = $this->createProjectViaApi('shop', "steps:\n  - name: Hello\n    run: echo hi \${PROJECT}\n  - name: Boom\n    run: exit 4\n  - name: Never\n    run: echo never\n");
        $runId = $this->runNow($project['id']);

        $run = $this->json($this->call('GET', '/api/runs/' . $runId))['run'];

        self::assertSame('failed', $run['status']);
        self::assertSame(['id' => $project['id'], 'slug' => 'shop', 'name' => 'Shop'], $run['project']);
        self::assertIsInt($run['duration_ms']);
        self::assertStringContainsString('Boom', (string) $run['error']);
        self::assertSame(['Hello', 'Boom', 'Never'], array_column($run['steps'], 'name'));
        self::assertSame('echo hi shop', $run['steps'][0]['command']);
        self::assertSame(['success', 'failed', 'skipped'], array_column($run['steps'], 'status'));
        self::assertSame([0, 4, null], array_column($run['steps'], 'exit_code'));
        self::assertIsInt($run['steps'][0]['duration_ms']);
        self::assertNull($run['steps'][2]['duration_ms']);
        self::assertNotNull($run['steps'][0]['started_at']);
    }

    public function testPipelineSnapshotIsVerbatimWithoutEnv(): void
    {
        $yaml = "# deploy\nsteps:\n  - name: Hello\n    run: echo hi\n";
        $project = $this->createProjectViaApi('plain', $yaml);
        $runId = $this->runNow($project['id']);

        self::assertSame($yaml, $this->json($this->call('GET', '/api/runs/' . $runId))['run']['pipeline_yaml']);
    }

    public function testPipelineSnapshotRedactsEnvValues(): void
    {
        $project = $this->createProjectViaApi('secret', <<<'YAML'
            env:
              API_TOKEN: top-secret-1
            steps:
              - name: Hello
                run: echo hi
                env:
                  DB_PASSWORD: top-secret-2
            YAML);
        $runId = $this->runNow($project['id']);

        $snapshot = (string) $this->json($this->call('GET', '/api/runs/' . $runId))['run']['pipeline_yaml'];

        self::assertStringNotContainsString('top-secret', $snapshot);
        self::assertStringContainsString('API_TOKEN', $snapshot);
        self::assertStringContainsString('DB_PASSWORD', $snapshot);
        self::assertStringContainsString('echo hi', $snapshot);
    }

    public function testLogsAreReadIncrementallyByCursor(): void
    {
        $project = $this->createProjectViaApi('shop', "steps:\n  - name: Count\n    run: echo one; echo two >&2\n");
        $runId = $this->runNow($project['id']);

        $first = $this->json($this->call('GET', "/api/runs/{$runId}/logs", query: ['limit' => '2']));
        self::assertCount(2, $first['lines']);
        self::assertFalse($first['complete']);
        self::assertSame(['id', 'step', 'stream', 'line', 'at'], array_keys($first['lines'][0]));

        $rest = $this->json($this->call('GET', "/api/runs/{$runId}/logs", query: ['after' => (string) $first['next_after']]));
        self::assertTrue($rest['complete']);
        self::assertSame('success', $rest['run_status']);
        $lines = array_map(static fn (array $l): string => $l['stream'] . ': ' . $l['line'], [...$first['lines'], ...$rest['lines']]);
        self::assertContains('stdout: one', $lines);
        self::assertContains('stderr: two', $lines);
        self::assertGreaterThan($first['next_after'], $rest['next_after']);

        $empty = $this->json($this->call('GET', "/api/runs/{$runId}/logs", query: ['after' => (string) $rest['next_after']]));
        self::assertSame([], $empty['lines']);
        self::assertSame($rest['next_after'], $empty['next_after']);
    }

    public function testListFiltersByProjectAndStatus(): void
    {
        $a = $this->createProjectViaApi('a');
        $b = $this->createProjectViaApi('b');
        $doneA = $this->runNow($a['id']);
        $queuedA = $this->json($this->call('POST', '/api/projects/' . $a['id'] . '/runs'))['run_id'];
        $queuedB = $this->json($this->call('POST', '/api/projects/' . $b['id'] . '/runs'))['run_id'];

        $ids = fn (array $query): array => array_column($this->json($this->call('GET', '/api/runs', query: $query))['runs'], 'id');

        self::assertSame([$queuedB, $queuedA, $doneA], $ids([]));
        self::assertSame([$queuedA, $doneA], $ids(['project' => (string) $a['id']]));
        self::assertSame([$queuedB, $queuedA], $ids(['status' => 'queued']));
        self::assertSame([$queuedB], $ids(['limit' => '1']));
        self::assertSame([$queuedA, $doneA], $ids(['before' => (string) $queuedB]));
        self::assertSame(422, $this->call('GET', '/api/runs', query: ['status' => 'bogus'])->status);
    }

    public function testCancel(): void
    {
        $project = $this->createProjectViaApi('shop');
        $queued = $this->json($this->call('POST', '/api/projects/' . $project['id'] . '/runs'))['run_id'];

        $cancel = $this->call('POST', "/api/runs/{$queued}/cancel");

        self::assertSame(200, $cancel->status);
        self::assertSame('cancelled', $this->json($cancel)['result']);
        self::assertSame('cancelled', $this->json($this->call('GET', "/api/runs/{$queued}"))['run']['status']);
        self::assertSame(409, $this->call('POST', "/api/runs/{$queued}/cancel")->status);
        self::assertSame(404, $this->call('POST', '/api/runs/999/cancel')->status);
    }

    public function testCancellingARunningRunIsARequest(): void
    {
        $project = $this->createProjectViaApi('shop');
        $runId = $this->json($this->call('POST', '/api/projects/' . $project['id'] . '/runs'))['run_id'];
        $this->app->runs()->claimNextQueued($project['id'], 1, $this->clock->now());

        $cancel = $this->call('POST', "/api/runs/{$runId}/cancel");

        self::assertSame(202, $cancel->status);
        self::assertSame('requested', $this->json($cancel)['result']);
        self::assertTrue($this->json($this->call('GET', "/api/runs/{$runId}"))['run']['cancel_requested']);
    }

    public function testRunEndpointsRequireAuthentication(): void
    {
        $this->cookies = [];

        self::assertSame(401, $this->call('GET', '/api/runs')->status);
        self::assertSame(401, $this->call('GET', '/api/runs/1/logs')->status);
    }

    private function runNow(int $projectId): int
    {
        $runId = $this->json($this->call('POST', '/api/projects/' . $projectId . '/runs'))['run_id'];
        ($this->app->drainProjectQueue())($projectId);

        return $runId;
    }
}
