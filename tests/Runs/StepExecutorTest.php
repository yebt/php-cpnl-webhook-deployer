<?php

declare(strict_types=1);

namespace Deployer\Tests\Runs;

use Deployer\Runs\Application\Port\StepOutcome;
use Deployer\Runs\Domain\LogStream;
use Deployer\Runs\Infrastructure\PosixProcessProbe;
use Deployer\Runs\Infrastructure\SymfonyProcessStepExecutor;
use Deployer\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class StepExecutorTest extends TestCase
{
    use TempDir;

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    /** @return array{StepOutcome, list<string>} */
    private function execute(string $command, int $timeout = 60, ?callable $shouldCancel = null, float $grace = 1.0): array
    {
        $lines = [];
        $outcome = (new SymfonyProcessStepExecutor($grace))->execute(
            $command,
            $this->makeTempDir(),
            $timeout,
            static function (LogStream $stream, string $line) use (&$lines): void {
                $lines[] = $stream->value . ': ' . $line;
            },
            $shouldCancel ?? static fn (): bool => false,
        );

        return [$outcome, $lines];
    }

    private function waitForPid(string $file): int
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (is_file($file) && ($pid = (int) trim((string) file_get_contents($file))) > 0) {
                return $pid;
            }
            usleep(20_000);
        }
        self::fail('Child process did not write its pid');
    }

    private function assertProcessGone(int $pid): void
    {
        $probe = new PosixProcessProbe();
        $deadline = microtime(true) + 3;
        while ($probe->isAlive($pid) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        self::assertFalse($probe->isAlive($pid), sprintf('Process %d should have been killed', $pid));
    }

    public function testSplitsStdoutAndStderrIntoLinesIncludingTrailingPartialLine(): void
    {
        [$outcome, $lines] = $this->execute('printf "a\nb\r\n"; echo err >&2; printf "tail"');

        self::assertSame(StepOutcome::SUCCEEDED, $outcome->result);
        self::assertSame(0, $outcome->exitCode);
        self::assertSame(['stdout: a', 'stdout: b', 'stdout: tail'], array_values(array_filter($lines, static fn ($l) => str_starts_with($l, 'stdout'))));
        self::assertContains('stderr: err', $lines);
    }

    public function testReportsNonZeroExitCode(): void
    {
        [$outcome] = $this->execute('exit 7');

        self::assertSame(StepOutcome::FAILED, $outcome->result);
        self::assertSame(7, $outcome->exitCode);
    }

    public function testCancelKillsTheWholeProcessTree(): void
    {
        $pidFile = $this->makeTempDir() . '/child.pid';
        $cancelAfterPid = fn (): bool => is_file($pidFile);

        [$outcome] = $this->execute(sprintf('sleep 300 & echo $! > %s; wait', escapeshellarg($pidFile)), 60, $cancelAfterPid);

        self::assertSame(StepOutcome::CANCELLED, $outcome->result);
        self::assertNull($outcome->exitCode);
        self::assertLessThan(5, $outcome->durationSeconds);
        $this->assertProcessGone($this->waitForPid($pidFile));
    }

    public function testProcessesIgnoringSigtermAreKilledAfterTheGracePeriod(): void
    {
        $pidFile = $this->makeTempDir() . '/child.pid';

        [$outcome, $lines] = $this->execute(
            sprintf('trap "" TERM; sleep 300 & echo $! > %s; while true; do sleep 0.2; done', escapeshellarg($pidFile)),
            60,
            fn (): bool => is_file($pidFile),
            0.5,
        );

        self::assertSame(StepOutcome::CANCELLED, $outcome->result, implode("\n", $lines));
        self::assertGreaterThanOrEqual(0.5, $outcome->durationSeconds);
        $this->assertProcessGone($this->waitForPid($pidFile));
    }

    public function testTimeoutKillsTheWholeProcessTree(): void
    {
        $pidFile = $this->makeTempDir() . '/child.pid';

        [$outcome] = $this->execute(sprintf('sleep 300 & echo $! > %s; wait', escapeshellarg($pidFile)), 1);

        self::assertSame(StepOutcome::TIMED_OUT, $outcome->result);
        self::assertLessThan(5, $outcome->durationSeconds);
        $this->assertProcessGone($this->waitForPid($pidFile));
    }
}
