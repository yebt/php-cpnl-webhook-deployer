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

/** In-memory state of one run (single Swoole worker, so plain objects suffice). */
final class Run
{
    public array $lines = [];
    public bool $done = false;
    public bool $cancelRequested = false;
    public string $result = '';

    public function __construct(public string $id, public int $count)
    {
    }

    public function push(string $tag, string $line): void
    {
        $this->lines[] = '[' . ts() . '] [' . $tag . '] ' . $line;
    }
}

final class Registry
{
    /** @var array<string, Run> */
    public static array $runs = [];
}

/** Coroutine body: run the command, buffer its lines, honour cancellation. */
function runJob(Run $run): void
{
    $script = 'for i in $(seq 1 ' . $run->count . '); do echo "' . $run->id . ' out $i"; echo "' . $run->id . ' err $i" >&2; sleep 0.5; done';
    $process = new Process(['bash', '-c', $script]);
    $process->setTimeout(null);
    $process->start();
    $run->push('meta', 'started pid=' . $process->getPid());
    $bufs = ['stdout' => '', 'stderr' => ''];
    $cancelled = false;
    while ($process->isRunning()) {
        foreach (['stdout' => $process->getIncrementalOutput(), 'stderr' => $process->getIncrementalErrorOutput()] as $tag => $chunk) {
            $bufs[$tag] .= $chunk;
            while (($pos = strpos($bufs[$tag], "\n")) !== false) {
                $run->push($tag, substr($bufs[$tag], 0, $pos));
                $bufs[$tag] = substr($bufs[$tag], $pos + 1);
            }
        }
        if ($run->cancelRequested && !$cancelled) {
            $cancelled = true;
            $run->push('meta', 'cancel requested: SIGTERM, SIGKILL after 2s');
            $process->stop(2.0, SIGKILL);
        }
        usleep(50000);
    }
    $run->result = sprintf(
        'exitCode=%s signaled=%s termSignal=%s cancelled=%s',
        var_export($process->getExitCode(), true),
        $process->hasBeenSignaled() ? 'yes' : 'no',
        $process->hasBeenSignaled() ? (string) $process->getTermSignal() : '-',
        $cancelled ? 'yes' : 'no'
    );
    $run->push('meta', 'finished ' . $run->result);
    $run->done = true;
}

/** Replay buffered lines, then follow the run live as Server-Sent Events. */
function streamRun(Run $run, Swoole\Http\Response $res): void
{
    $res->header('Content-Type', 'text/event-stream');
    $res->header('Cache-Control', 'no-cache');
    $sent = 0;
    while (true) {
        $total = count($run->lines);
        while ($sent < $total) {
            $res->write('data: ' . $run->lines[$sent] . "\n\n");
            $sent++;
        }
        if ($run->done && $sent >= count($run->lines)) {
            $res->write("event: end\ndata: {$run->result}\n\n");
            break;
        }
        Swoole\Coroutine::sleep(0.05);
    }
    $res->end();
}

function handleRequest(Swoole\Http\Request $req, Swoole\Http\Response $res): void
{
    $method = $req->server['request_method'];
    $path = $req->server['request_uri'];
    $m = [];
    if ($method === 'POST' && $path === '/runs') {
        $id = 'run' . (count(Registry::$runs) + 1);
        $run = new Run($id, (int) ($req->get['count'] ?? 10));
        Registry::$runs[$id] = $run;
        Swoole\Coroutine::create(function () use ($run): void {
            runJob($run);
        });
        $res->status(201);
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode(['id' => $id]) . "\n");
        return;
    }
    if (preg_match('#^/runs/([a-z0-9]+)/(stream|cancel)$#', $path, $m) && isset(Registry::$runs[$m[1]])) {
        $run = Registry::$runs[$m[1]];
        if ($method === 'POST' && $m[2] === 'cancel') {
            $run->cancelRequested = true;
            $res->status(202);
            $res->end("cancel requested for {$run->id}\n");
            return;
        }
        if ($method === 'GET' && $m[2] === 'stream') {
            // Workaround: keeping this loop inline fails in tpc's LoopVarOptimizer
            // ("The variable `$run` is undefined"), so it lives in its own function.
            streamRun($run, $res);
            return;
        }
    }
    $res->status(404);
    $res->end("not found\n");
}

function cmdServe(int $port): int
{
    echo '[' . ts() . "] swoole loaded=" . (extension_loaded('swoole') ? 'yes ' . phpversion('swoole') : 'no') . "\n";
    $server = new Swoole\Http\Server('0.0.0.0', $port, SWOOLE_BASE);
    $server->set([
        'worker_num' => 1,
        'enable_coroutine' => true,
        'hook_flags' => SWOOLE_HOOK_ALL,
        'http_compression' => false,
        'log_level' => SWOOLE_LOG_WARNING,
    ]);
    // TypePHP enforces strict arity: callbacks must declare every argument Swoole passes.
    $server->on('start', function (Swoole\Http\Server $srv) use ($port): void {
        echo '[' . ts() . "] listening on :{$port}\n";
    });
    $server->on('request', function (Swoole\Http\Request $req, Swoole\Http\Response $res): void {
        handleRequest($req, $res);
    });
    $server->start();
    return 0;
}

function usage(): int
{
    fwrite(STDERR, "usage: deployer yaml <file> | process <cancelAfterSec> <ignoreTerm 0|1> | serve [port]\n");
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
    } elseif ($cmd === 'serve') {
        $code = cmdServe((int) ($argv[2] ?? '8080'));
    } else {
        $code = usage();
    }
    exit($code);
}
