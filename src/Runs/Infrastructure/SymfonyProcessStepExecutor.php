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
 *
 * A list `run` executes as ONE script so cwd and shell variables persist between
 * items. Before each item the script prints a marker line to stdout made of a
 * per-execution random nonce and the item index between \x1F (unit separator)
 * bytes. Markers travel on the same pipe as the items' stdout, so the engine can
 * announce each item (`$onCommand`) exactly where it starts relative to stdout;
 * marker lines are never logged, and output cannot spoof them without knowing
 * the nonce. Ordering against stderr is best effort, as it is for any
 * stdout/stderr interleaving. A single command needs no marker.
 */
final class SymfonyProcessStepExecutor implements StepExecutor
{
    private const SIGTERM = 15;
    private const SIGKILL = 9;
    private const POLL_MICROSECONDS = 50_000;
    private const CANCEL_CHECK_SECONDS = 0.5;
    private const MAX_PARTIAL_LINE = 16384;
    /** Unit separator around item markers, as a regex escape and as a byte. */
    private const MARK = '\x1F';
    private const MARK_BYTE = "\x1F";

    public function __construct(private readonly float $killGraceSeconds = 5.0)
    {
    }

    private const STRICT_BASH = 'exec bash --noprofile --norc -eo pipefail -c "$1"';
    private const PLAIN_BASH = 'exec bash -c "$1"';

    public function execute(Step $step, string $workingDir, callable $onLine, callable $onCommand, callable $shouldCancel): StepOutcome
    {
        $timeoutSeconds = $step->timeout;
        $wrapper = Shell::CLOSE_INHERITED_FDS . '; ' . ($step->strict ? self::STRICT_BASH : self::PLAIN_BASH);
        $nonce = bin2hex(random_bytes(8));
        $argv = ['bash', '-c', $wrapper, 'deployer-step', $this->script($step->commands, $nonce)];
        $setsid = Shell::setsid();
        if ($setsid !== '') {
            array_unshift($argv, $setsid);
        }

        $process = new Process($argv, $workingDir, Shell::environment(), null, null);
        $process->disableOutput();

        $marker = '/' . self::MARK . preg_quote($nonce, '/') . ':(\d+)' . self::MARK . '$/';
        $current = 0;
        $buffers = [Process::OUT => '', Process::ERR => ''];
        $emit = static function (string $type, string $data) use (&$buffers, &$current, $onLine, $onCommand, $marker): void {
            $buffers[$type] .= $data;
            $stream = $type === Process::ERR ? LogStream::Stderr : LogStream::Stdout;
            while (($pos = strpos($buffers[$type], "\n")) !== false) {
                $line = rtrim(substr($buffers[$type], 0, $pos), "\r");
                $buffers[$type] = substr($buffers[$type], $pos + 1);
                if ($type === Process::OUT && preg_match($marker, $line, $m, PREG_OFFSET_CAPTURE)) {
                    // Output of the previous item without a trailing newline precedes the marker.
                    if ($m[0][1] > 0) {
                        $onLine($stream, substr($line, 0, $m[0][1]));
                    }
                    $current = (int) $m[1][0];
                    $onCommand($current);
                    continue;
                }
                $onLine($stream, $line);
            }
            if (strlen($buffers[$type]) > self::MAX_PARTIAL_LINE) {
                // Keep a possibly incomplete marker for the next chunk.
                $cut = strrpos($buffers[$type], self::MARK_BYTE);
                $cut = $cut !== false && strlen($buffers[$type]) - $cut < 64 ? $cut : strlen($buffers[$type]);
                if ($cut > 0) {
                    $onLine($stream, substr($buffers[$type], 0, $cut));
                    $buffers[$type] = substr($buffers[$type], $cut);
                }
            }
        };

        $started = microtime(true);
        if (count($step->commands) === 1) {
            $onCommand(0);
        }
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
            StepOutcome::TIMED_OUT => StepOutcome::timedOut($duration, $current),
            StepOutcome::CANCELLED => StepOutcome::cancelled($duration, $current),
            default => StepOutcome::finished((int) $process->getExitCode(), $duration, $current),
        };
    }

    /** @param non-empty-list<string> $commands */
    private function script(array $commands, string $nonce): string
    {
        if (count($commands) === 1) {
            return $commands[0];
        }
        $script = '';
        foreach ($commands as $index => $command) {
            $script .= sprintf("printf '\\037%s:%d\\037\\n'\n%s\n", $nonce, $index, $command);
        }

        return $script;
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
