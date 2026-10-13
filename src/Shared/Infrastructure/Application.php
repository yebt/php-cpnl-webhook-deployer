<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure;

use Deployer\Pipeline\Infrastructure\YamlPipelineParser;
use Deployer\Projects\Domain\ProjectRepository;
use Deployer\Projects\Infrastructure\FilesystemInstructionsStore;
use Deployer\Projects\Infrastructure\SqliteProjectRepository;
use Deployer\Runs\Application\CancelRun;
use Deployer\Runs\Application\DrainProjectQueue;
use Deployer\Runs\Application\EnqueueRun;
use Deployer\Runs\Application\ExecuteRun;
use Deployer\Runs\Application\Port\RunnerLauncher;
use Deployer\Runs\Application\ReadRunLogs;
use Deployer\Runs\Application\SweepRunners;
use Deployer\Runs\Domain\LogRepository;
use Deployer\Runs\Domain\RunRepository;
use Deployer\Runs\Infrastructure\Cli\CancelRunCommand;
use Deployer\Runs\Infrastructure\Cli\RunnerCommand;
use Deployer\Runs\Infrastructure\Cli\RunnerSweepCommand;
use Deployer\Runs\Infrastructure\Cli\TriggerRunCommand;
use Deployer\Runs\Infrastructure\DetachedRunnerLauncher;
use Deployer\Runs\Infrastructure\FlockProjectLock;
use Deployer\Runs\Infrastructure\PosixProcessProbe;
use Deployer\Runs\Infrastructure\SqliteLogRepository;
use Deployer\Runs\Infrastructure\SqliteRunRepository;
use Deployer\Runs\Infrastructure\SymfonyProcessStepExecutor;
use Deployer\Shared\Domain\Clock;
use Deployer\Shared\Infrastructure\Cli\CliApplication;
use Deployer\Shared\Infrastructure\Cli\Command\AboutCommand;
use Deployer\Shared\Infrastructure\Cli\Command\MigrateCommand;
use Deployer\Shared\Infrastructure\Cli\Console;
use Deployer\Shared\Infrastructure\Config\AppConfig;
use Deployer\Shared\Infrastructure\Config\ConfigLoader;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Http\Response;
use Deployer\Shared\Infrastructure\Http\Router;
use Deployer\Shared\Infrastructure\Persistence\Database;
use Deployer\Shared\Infrastructure\Persistence\Migrator;
use Phar;
use PDO;
use Throwable;

/**
 * Composition root: wires adapters and exposes the CLI and HTTP entry points.
 * The same artifact serves both, selected by SAPI in public/index.php.
 */
final class Application
{
    public const VERSION = '0.1.0-dev';

    private ?PDO $pdo = null;

    public function __construct(private readonly AppConfig $config, private readonly Clock $clock = new SystemClock())
    {
    }

    public static function fromEnvironment(): self
    {
        $phar = Phar::running(false);
        $isPhar = $phar !== '';
        $entry = $isPhar ? $phar : dirname(__DIR__, 3) . '/bin/deployer';

        /** @var array<string,string> $env */
        $env = array_filter(getenv(), 'is_string');

        return new self(ConfigLoader::load($entry, $isPhar, $env, PHP_SAPI, PHP_BINARY));
    }

    public function config(): AppConfig
    {
        return $this->config;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    /** Lazily connects and applies pending migrations. */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::connect($this->config->databasePath());
            (new Migrator($this->pdo))->migrate();
        }

        return $this->pdo;
    }

    public function projects(): ProjectRepository
    {
        return new SqliteProjectRepository($this->pdo());
    }

    public function runs(): RunRepository
    {
        return new SqliteRunRepository($this->pdo());
    }

    public function logs(): LogRepository
    {
        return new SqliteLogRepository($this->pdo());
    }

    public function runnerLauncher(): RunnerLauncher
    {
        return new DetachedRunnerLauncher($this->config->phpCli, $this->config->entryScript, $this->config->dataDir);
    }

    public function enqueueRun(?RunnerLauncher $launcher = null): EnqueueRun
    {
        return new EnqueueRun($this->projects(), $this->runs(), $launcher ?? $this->runnerLauncher(), $this->clock);
    }

    public function cancelRun(): CancelRun
    {
        return new CancelRun($this->runs(), $this->logs(), $this->clock);
    }

    public function readRunLogs(): ReadRunLogs
    {
        return new ReadRunLogs($this->runs(), $this->logs());
    }

    public function executeRun(): ExecuteRun
    {
        return new ExecuteRun(
            $this->projects(),
            new FilesystemInstructionsStore(),
            new YamlPipelineParser(),
            $this->runs(),
            $this->logs(),
            new SymfonyProcessStepExecutor($this->config->killGraceSeconds),
            $this->clock,
        );
    }

    public function drainProjectQueue(): DrainProjectQueue
    {
        return new DrainProjectQueue(
            new FlockProjectLock($this->config->locksDir()),
            $this->runs(),
            $this->executeRun(),
            $this->clock,
            (int) getmypid(),
        );
    }

    public function sweepRunners(?RunnerLauncher $launcher = null): SweepRunners
    {
        return new SweepRunners(
            $this->runs(),
            $this->logs(),
            new FlockProjectLock($this->config->locksDir()),
            $launcher ?? $this->runnerLauncher(),
            new PosixProcessProbe(),
            $this->clock,
        );
    }

    public function cli(): CliApplication
    {
        return (new CliApplication())
            ->add(new AboutCommand($this))
            ->add(new MigrateCommand($this))
            ->add(new RunnerCommand($this))
            ->add(new RunnerSweepCommand($this))
            ->add(new TriggerRunCommand($this))
            ->add(new CancelRunCommand($this));
    }

    public function router(): Router
    {
        return (new Router())
            ->add('GET', '/api/health', fn (): Response => Response::json([
                'status' => 'ok',
                'version' => self::VERSION,
            ]));
    }

    /** @param list<string> $argv */
    public function runCli(array $argv, ?Console $console = null): int
    {
        return $this->cli()->run($argv, $console ?? Console::std());
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router()->dispatch($request);
        } catch (Throwable $e) {
            error_log('[deployer] ' . $e);

            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
