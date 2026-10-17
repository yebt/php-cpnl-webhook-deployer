<?php

declare(strict_types=1);

namespace Deployer\Tests\Notifiers;

use DateTimeImmutable;
use Deployer\Runs\Application\Port\ProcessProbe;
use Deployer\Runs\Application\SweepRunners;
use Deployer\Runs\Infrastructure\FlockProjectLock;
use Deployer\Shared\Infrastructure\SystemClock;
use Deployer\Tests\Support\ApiTestCase;
use Deployer\Tests\Support\RecordingLauncher;

final class RunNotificationTest extends ApiTestCase
{
    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    private int $notifierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
        $response = $this->call('POST', '/api/notifiers', ['name' => 'Ops', 'type' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '-100123']);
        $this->notifierId = $this->json($response)['notifier']['id'];
    }

    public function testFailedRunSendsTheFailedStepAndLogTail(): void
    {
        $project = $this->createProjectViaApi('shop', "steps:\n  - name: Ok\n    run: echo fine\n  - name: Install\n    run: echo 'npm ERR! <boom>' >&2; exit 2\n", ['notifier_id' => $this->notifierId]);

        $this->runNow($project['id']);

        self::assertCount(1, $this->http->requests);
        $text = $this->http->requests[0]['body']['text'];
        self::assertStringStartsWith("\u{274C} <b>Shop</b>", $text);
        self::assertStringContainsString('Failed step: <b>Install</b>', $text);
        self::assertStringContainsString('npm ERR! &lt;boom&gt;', $text);
        self::assertStringContainsString('manual', $text);
    }

    public function testSuccessfulRunSendsASuccessMessage(): void
    {
        $project = $this->createProjectViaApi('shop', extra: ['notifier_id' => $this->notifierId]);

        $runId = $this->runNow($project['id']);

        self::assertStringStartsWith("\u{2705}", $this->http->requests[0]['body']['text']);
        self::assertContains('system: Notification sent via "Ops".', $this->logLines($runId));
    }

    public function testNotificationFailureNeverChangesTheRunStatus(): void
    {
        $project = $this->createProjectViaApi('shop', extra: ['notifier_id' => $this->notifierId]);
        $this->http->willFail('Connection timed out for https://api.telegram.org/bot' . self::TOKEN . '/sendMessage');

        $runId = $this->runNow($project['id']);

        self::assertSame('success', $this->json($this->call('GET', "/api/runs/{$runId}"))['run']['status']);
        $failure = array_values(array_filter($this->logLines($runId), static fn (string $l): bool => str_contains($l, 'Notification via "Ops" failed')));
        self::assertCount(1, $failure);
        self::assertStringContainsString('Connection timed out', $failure[0]);
        self::assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', implode("\n", $this->logLines($runId)));
    }

    public function testProjectsWithoutNotifierSendNothing(): void
    {
        $project = $this->createProjectViaApi('quiet');

        $this->runNow($project['id']);

        self::assertSame([], $this->http->requests);
    }

    public function testTestEndpointSendsAMessage(): void
    {
        $ok = $this->call('POST', "/api/notifiers/{$this->notifierId}/test");

        self::assertSame(200, $ok->status, $ok->body);
        self::assertSame(['sent' => true], $this->json($ok));
        self::assertStringContainsString('test message', $this->http->requests[0]['body']['text']);

        $this->http->willReturn(401, '{"ok":false,"error_code":401,"description":"Unauthorized"}');
        $failed = $this->call('POST', "/api/notifiers/{$this->notifierId}/test");

        self::assertSame(502, $failed->status);
        self::assertSame('notification_failed', $this->json($failed)['error']['code']);
        self::assertStringContainsString('Unauthorized', $this->json($failed)['error']['message']);
        self::assertSame(404, $this->call('POST', '/api/notifiers/999/test')->status);
    }

    public function testRunAndTestMessagesGoToTheConfiguredForumTopic(): void
    {
        $this->call('PUT', "/api/notifiers/{$this->notifierId}", ['thread_id' => 99]);
        $project = $this->createProjectViaApi('shop', extra: ['notifier_id' => $this->notifierId]);

        $this->runNow($project['id']);
        $this->call('POST', "/api/notifiers/{$this->notifierId}/test");

        self::assertCount(2, $this->http->requests);
        self::assertSame(99, $this->http->requests[0]['body']['message_thread_id']);
        self::assertSame(99, $this->http->requests[1]['body']['message_thread_id']);
    }

    public function testRunsFailedBySweepBecauseTheirRunnerDiedAreNotified(): void
    {
        $project = $this->createProjectViaApi('shop', extra: ['notifier_id' => $this->notifierId]);
        $runId = $this->deadRunnerRun($project['id']);

        $report = $this->sweep();

        self::assertSame([$runId], $report['failedRuns']);
        self::assertSame('failed', $this->json($this->call('GET', "/api/runs/{$runId}"))['run']['status']);
        self::assertCount(1, $this->http->requests);
        $text = $this->http->requests[0]['body']['text'];
        self::assertStringStartsWith("\u{274C} <b>Shop</b>", $text);
        self::assertStringContainsString('Runner process 4242 is gone', $text);
        self::assertContains('system: Notification sent via "Ops".', $this->logLines($runId));
    }

    public function testSweepNotificationFailureNeverChangesTheRunStatus(): void
    {
        $project = $this->createProjectViaApi('shop', extra: ['notifier_id' => $this->notifierId]);
        $runId = $this->deadRunnerRun($project['id']);
        $this->http->willFail('Connection refused');

        $this->sweep();

        self::assertSame('failed', $this->json($this->call('GET', "/api/runs/{$runId}"))['run']['status']);
        self::assertNotEmpty(array_filter($this->logLines($runId), static fn (string $l): bool => str_contains($l, 'Notification via "Ops" failed: Connection refused')));
    }

    public function testCancellingAQueuedRunSendsNothing(): void
    {
        $project = $this->createProjectViaApi('shop', extra: ['notifier_id' => $this->notifierId]);
        $runId = $this->json($this->call('POST', '/api/projects/' . $project['id'] . '/runs'))['run_id'];

        self::assertSame(200, $this->call('POST', "/api/runs/{$runId}/cancel")->status);
        self::assertSame([], $this->http->requests);
    }

    private function deadRunnerRun(int $projectId): int
    {
        $runId = $this->json($this->call('POST', '/api/projects/' . $projectId . '/runs'))['run_id'];
        $this->app->runs()->claimNextQueued($projectId, 4242, new DateTimeImmutable());

        return $runId;
    }

    /** @return array{failedRuns: list<int>, launchedProjects: list<int>} */
    private function sweep(): array
    {
        $deadProbe = new class () implements ProcessProbe {
            public function isAlive(int $pid): bool
            {
                return false;
            }
        };

        return (new SweepRunners(
            $this->app->runs(),
            $this->app->logs(),
            new FlockProjectLock($this->app->config()->locksDir()),
            new RecordingLauncher(),
            $deadProbe,
            new SystemClock(),
            $this->app->notifyRunFinished(),
        ))();
    }

    private function runNow(int $projectId): int
    {
        $runId = $this->json($this->call('POST', '/api/projects/' . $projectId . '/runs'))['run_id'];
        ($this->app->drainProjectQueue())($projectId);

        return $runId;
    }

    /** @return list<string> */
    private function logLines(int $runId): array
    {
        return array_map(static fn ($l): string => $l->stream->value . ': ' . $l->line, $this->app->logs()->since($runId, 0, 10_000));
    }
}
