<?php

declare(strict_types=1);

namespace Deployer\Tests\Runs;

use Deployer\Pipeline\Domain\Step;
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

    /**
     * @param string|list<string>  $commands
     * @param array<string, string> $env
     *
     * @return array{StepOutcome, list<string>}
     */
    private function execute(string|array $commands, int $timeout = 60, ?callable $shouldCancel = null, float $grace = 1.0, bool $strict = true, array $env = []): array
    {
        $lines = [];
        $outcome = (new SymfonyProcessStepExecutor($grace))->execute(
            new Step('Test', (array) $commands, $timeout, false, $strict, $env),
            $this->makeTempDir(),
            static function (LogStream $stream, string $line) use (&$lines): void {
                $lines[] = $stream->value . ': ' . $line;
            },
            static function (int $index) use (&$lines): void {
                $lines[] = 'command: ' . $index;
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

    public function testStrictModeStopsAtTheFirstFailingCommand(): void
    {
        [$outcome, $lines] = $this->execute("echo before\nfalse\necho after");

        self::assertSame(StepOutcome::FAILED, $outcome->result);
        self::assertSame(1, $outcome->exitCode);
        self::assertSame(['command: 0', 'stdout: before'], $lines);
    }

    public function testStrictModeFailsOnAFailingCommandInsideAPipe(): void
    {
        [$outcome] = $this->execute('false | cat');

        self::assertSame(StepOutcome::FAILED, $outcome->result);
    }

    public function testStrictModeKeepsConditionsAndOrTrueWorking(): void
    {
        [$outcome, $lines] = $this->execute(<<<'SH'
            if ! grep -q needle /dev/null; then echo "not found"; fi
            false || true
            test -f /definitely/not/here && echo never
            echo end
            SH);

        self::assertSame(StepOutcome::SUCCEEDED, $outcome->result, implode("\n", $lines));
        self::assertSame(['command: 0', 'stdout: not found', 'stdout: end'], $lines);
    }

    public function testNonStrictModeOnlyHonoursTheLastCommand(): void
    {
        [$outcome, $lines] = $this->execute("false\nfalse | true\necho after", strict: false);

        self::assertSame(StepOutcome::SUCCEEDED, $outcome->result);
        self::assertSame(['command: 0', 'stdout: after'], $lines);
    }

    public function testStepsDoNotReadBashStartupFiles(): void
    {
        $home = $this->makeTempDir();
        file_put_contents($home . '/.bashrc', 'echo from-bashrc');
        file_put_contents($home . '/.bash_profile', 'echo from-profile');
        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            [$outcome, $lines] = $this->execute('echo ok');
        } finally {
            putenv($previous === false ? 'HOME' : 'HOME=' . $previous);
        }

        self::assertSame(StepOutcome::SUCCEEDED, $outcome->result);
        self::assertSame(['command: 0', 'stdout: ok'], $lines);
    }

    public function testListItemsRunInOneShellWithEachCommandAnnouncedInOrder(): void
    {
        [$outcome, $lines] = $this->execute([
            'mkdir sub && cd sub && GREETING=hi',
            'printf "no newline"',
            'echo "$GREETING from ${PWD##*/}"',
        ]);

        self::assertSame(StepOutcome::SUCCEEDED, $outcome->result, implode("\n", $lines));
        self::assertSame(['command: 0', 'command: 1', 'stdout: no newline', 'command: 2', 'stdout: hi from sub'], $lines);
        self::assertSame(2, $outcome->command);
    }

    public function testStrictModeStopsAtTheFirstFailingListItem(): void
    {
        [$outcome, $lines] = $this->execute(['echo one', 'false', 'echo three']);

        self::assertSame(StepOutcome::FAILED, $outcome->result);
        self::assertSame(1, $outcome->command, 'the failing item is reported');
        self::assertSame(['command: 0', 'stdout: one', 'command: 1'], $lines);
    }

    public function testNonStrictListRunsEveryItem(): void
    {
        [$outcome, $lines] = $this->execute(['false', 'echo two'], strict: false);

        self::assertSame(StepOutcome::SUCCEEDED, $outcome->result);
        self::assertSame(['command: 0', 'command: 1', 'stdout: two'], $lines);
    }

    public function testOutputThatLooksLikeAMarkerIsNotTreatedAsOne(): void
    {
        [$outcome, $lines] = $this->execute(['printf "\\037deployer:0000:1\\037\\n"', 'echo b']);

        self::assertSame(StepOutcome::SUCCEEDED, $outcome->result);
        self::assertSame(['command: 0', "stdout: \x1Fdeployer:0000:1\x1F", 'command: 1', 'stdout: b'], $lines);
    }

    public function testAStepPathWithoutBashStillRunsTheStep(): void
    {
        $bin = $this->makeTempDir();
        [$outcome, $lines] = $this->execute('echo "$PATH"', env: ['PATH' => $bin]);

        self::assertSame(StepOutcome::SUCCEEDED, $outcome->result, implode("\n", $lines));
        self::assertSame(['command: 0', 'stdout: ' . $bin], $lines);
    }
}
