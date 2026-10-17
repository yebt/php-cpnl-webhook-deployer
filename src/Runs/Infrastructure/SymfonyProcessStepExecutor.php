<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure;

use Deployer\Pipeline\Domain\Step;
use Deployer\Runs\Application\Port\StepExecutor;
use Deployer\Runs\Application\Port\StepOutcome;
use Deployer\Runs\Domain\LogStream;
use Deployer\Runs\Infrastructure\Process\ProcessGroup;
use Deployer\Runs\Infrastructure\Process\Shell;
use Symfony\Component\Process\Process;

/**
 * Runs the step script with bash as the leader of a new session/process group (via
 * setsid), so cancel and timeout can signal the whole tree: SIGTERM, a grace
 * period, then SIGKILL. Signals are sent and polled instead of using the blocking
 * Process::stop(), so output produced during the grace period is still streamed.
 *
 * Strict steps run as `bash --noprofile --norc -eo pipefail -c <script>` (the
 * GitHub Actions default); non-strict steps keep plain `bash -c <script>`.
 */
final class SymfonyProcessStepExecutor implements StepExecutor
{
    private const SIGTERM = 15;
    private const SIGKILL = 9;
    private const POLL_MICROSECONDS = 50_000;
    private const CANCEL_CHECK_SECONDS = 0.5;
    private const MAX_PARTIAL_LINE = 16384;

    public function __construct(private readonly float $killGraceSeconds = 5.0)
    {
    }

    private const STRICT_BASH = 'exec bash --noprofile --norc -eo pipefail -c "$1"';
    private const PLAIN_BASH = 'exec bash -c "$1"';

    public function execute(Step $step, string $workingDir, callable $onLine, callable $shouldCancel): StepOutcome
    {
        $timeoutSeconds = $step->timeout;
        $script = Shell::CLOSE_INHERITED_FDS . '; ' . ($step->strict ? self::STRICT_BASH : self::PLAIN_BASH);
        $argv = ['bash', '-c', $script, 'deployer-step', $step->command];
        $setsid = Shell::setsid();
        if ($setsid !== '') {
            array_unshift($argv, $setsid);
        }

        $process = new Process($argv, $workingDir, Shell::environment(), null, null);
        $process->disableOutput();

        $buffers = [Process::OUT => '', Process::ERR => ''];
        $emit = static function (string $type, string $data) use (&$buffers, $onLine): void {
            $buffers[$type] .= $data;
            $stream = $type === Process::ERR ? LogStream::Stderr : LogStream::Stdout;
            while (($pos = strpos($buffers[$type], "\n")) !== false) {
                $onLine($stream, rtrim(substr($buffers[$type], 0, $pos), "\r"));
                $buffers[$type] = substr($buffers[$type], $pos + 1);
            }
            if (strlen($buffers[$type]) > self::MAX_PARTIAL_LINE) {
                $onLine($stream, $buffers[$type]);
                $buffers[$type] = '';
            }
        };

        $started = microtime(true);
        $process->start($emit);
        $pgid = (int) $process->getPid();
        $stopReason = null;
        $termSentAt = null;
        $killed = false;
        $lastCancelCheck = $started;

        while ($process->isRunning()) {
            $now = microtime(true);
            if ($termSentAt === null) {
                if ($now - $started >= $timeoutSeconds) {
                    $stopReason = StepOutcome::TIMED_OUT;
                } elseif ($now - $lastCancelCheck >= self::CANCEL_CHECK_SECONDS) {
                    $lastCancelCheck = $now;
                    if ($shouldCancel()) {
                        $stopReason = StepOutcome::CANCELLED;
                    }
                }
                if ($stopReason !== null) {
                    $this->terminate($process, $pgid, self::SIGTERM);
                    $termSentAt = $now;
                }
            } elseif (!$killed && $now - $termSentAt >= $this->killGraceSeconds) {
                $this->terminate($process, $pgid, self::SIGKILL);
                $killed = true;
            }
            usleep(self::POLL_MICROSECONDS);
        }

        if ($stopReason !== null && $setsid !== '') {
            // The leader may exit on SIGTERM while other group members ignore it.
            $deadline = ($termSentAt ?? microtime(true)) + $this->killGraceSeconds;
            while (ProcessGroup::isAlive($pgid) && microtime(true) < $deadline) {
                usleep(self::POLL_MICROSECONDS);
            }
            if (ProcessGroup::isAlive($pgid)) {
                ProcessGroup::signal($pgid, self::SIGKILL);
            }
        }

        foreach ($buffers as $type => $rest) {
            if ($rest !== '') {
                $onLine($type === Process::ERR ? LogStream::Stderr : LogStream::Stdout, rtrim($rest, "\r"));
            }
        }

        $duration = microtime(true) - $started;

        return match ($stopReason) {
            StepOutcome::TIMED_OUT => StepOutcome::timedOut($duration),
            StepOutcome::CANCELLED => StepOutcome::cancelled($duration),
            default => StepOutcome::finished((int) $process->getExitCode(), $duration),
        };
    }

    private function terminate(Process $process, int $pgid, int $signal): void
    {
        if (Shell::setsid() !== '') {
            ProcessGroup::signal($pgid, $signal);

            return;
        }
        // Without setsid only the leader can be targeted.
        if ($process->isRunning()) {
            $process->signal($signal);
        }
    }
}
