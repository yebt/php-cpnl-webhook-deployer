<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Replace ${NAME} placeholders with values from $vars; unknown names are kept.
 */
function interpolate(string $text, array $vars): string
{
    return preg_replace_callback(
        '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
        function (array $m) use ($vars): string {
            return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0];
        },
        $text
    );
}

function cmdYaml(string $file): int
{
    $doc = Yaml::parseFile($file);
    $vars = $doc['vars'] ?? [];
    $steps = $doc['steps'] ?? [];
    echo 'vars: ' . json_encode($vars) . "\n";
    foreach ($steps as $i => $step) {
        echo sprintf("step %d [%s]: %s\n", $i + 1, $step['name'], interpolate($step['run'], $vars));
    }
    return 0;
}

function ts(): string
{
    $t = microtime(true);
    return date('H:i:s', (int) $t) . sprintf('.%03d', (int) (($t - floor($t)) * 1000));
}

/**
 * Split a chunk into complete lines, keeping the trailing partial line in $buf.
 */
function emitLines(string $tag, string &$buf, string $chunk): void
{
    $buf .= $chunk;
    while (($pos = strpos($buf, "\n")) !== false) {
        echo '[' . ts() . '] [' . $tag . '] ' . substr($buf, 0, $pos) . "\n";
        $buf = substr($buf, $pos + 1);
    }
}

/**
 * Run a noisy bash loop, stream stdout/stderr live, optionally cancel it.
 *
 * @param float $cancelAfter seconds before cancelling (0 = never)
 * @param bool  $ignoreTerm  child traps SIGTERM, forcing the SIGKILL escalation
 */
function cmdProcess(float $cancelAfter, bool $ignoreTerm): int
{
    $script = ($ignoreTerm ? 'trap "echo child: got SIGTERM, ignoring >&2" TERM; ' : '')
        . 'for i in 1 2 3 4 5 6 7 8 9 10; do echo "out line $i"; echo "err line $i" >&2; sleep 0.5; done; exit 3';
    $process = new Process(['bash', '-c', $script]);
    $process->setTimeout(null);
    $started = microtime(true);
    $process->start();
    echo '[' . ts() . '] [meta] started pid=' . $process->getPid() . "\n";

    $outBuf = '';
    $errBuf = '';
    $cancelled = false;
    while ($process->isRunning()) {
        emitLines('stdout', $outBuf, $process->getIncrementalOutput());
        emitLines('stderr', $errBuf, $process->getIncrementalErrorOutput());
        if (!$cancelled && $cancelAfter > 0 && microtime(true) - $started >= $cancelAfter) {
            $cancelled = true;
            echo '[' . ts() . "] [meta] cancelling: SIGTERM, SIGKILL after 2s grace\n";
            // Symfony stop(): always sends SIGTERM first; the 2nd arg is the signal sent after the grace period.
            $process->stop(2.0, SIGKILL);
        }
        usleep(50000);
    }
    emitLines('stdout', $outBuf, $process->getIncrementalOutput() . ($outBuf !== '' ? "\n" : ''));
    emitLines('stderr', $errBuf, $process->getIncrementalErrorOutput() . ($errBuf !== '' ? "\n" : ''));

    echo sprintf(
        "[%s] [meta] finished after %.2fs exitCode=%s signaled=%s termSignal=%s\n",
        ts(),
        microtime(true) - $started,
        var_export($process->getExitCode(), true),
        $process->hasBeenSignaled() ? 'yes' : 'no',
        $process->hasBeenSignaled() ? (string) $process->getTermSignal() : '-'
    );
    return 0;
}

function usage(): int
{
    fwrite(STDERR, "usage: deployer yaml <file> | process <cancelAfterSec> <ignoreTerm 0|1>\n");
    return 2;
}

function main(int $argc, array $argv): void
{
    require_once __DIR__ . '/vendor/autoload.php';

    $cmd = $argv[1] ?? '';
    $code = 2;
    if ($cmd === 'yaml') {
        $code = cmdYaml($argv[2] ?? 'pipeline.yml');
    } elseif ($cmd === 'process') {
        $code = cmdProcess((float) ($argv[2] ?? '0'), ($argv[3] ?? '0') === '1');
    } else {
        $code = usage();
    }
    exit($code);
}
