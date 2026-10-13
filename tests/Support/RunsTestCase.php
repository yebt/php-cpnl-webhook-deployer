<?php

declare(strict_types=1);

namespace Deployer\Tests\Support;

use Deployer\Projects\Domain\Project;
use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Config\AppConfig;
use PHPUnit\Framework\TestCase;

abstract class RunsTestCase extends TestCase
{
    use TempDir;

    protected Application $app;
    protected string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = $this->makeTempDir();
        $this->app = new Application(new AppConfig(
            dataDir: $this->dataDir,
            phpCli: PHP_BINARY,
            entryScript: dirname(__DIR__, 2) . '/bin/deployer',
            isPhar: false,
            killGraceSeconds: 1.0,
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    protected function createProject(string $slug, string $yaml): Project
    {
        $folder = $this->makeTempDir();
        file_put_contents($folder . '/deployer.yml', $yaml);

        return $this->app->projects()->create($slug, ucfirst($slug), $folder);
    }

    protected function recordingLauncher(): RecordingLauncher
    {
        return new RecordingLauncher();
    }

    /** Enqueues without spawning a runner and executes the queue in-process. */
    protected function runInProcess(Project $project): int
    {
        $runId = ($this->app->enqueueRun($this->recordingLauncher()))($project->id, 'test');
        ($this->app->drainProjectQueue())($project->id);

        return $runId;
    }

    /** @return list<string> "stream: line" entries */
    protected function logLines(int $runId): array
    {
        return array_map(
            static fn ($l): string => $l->stream->value . ': ' . $l->line,
            $this->app->logs()->since($runId, 0, 10_000),
        );
    }
}
