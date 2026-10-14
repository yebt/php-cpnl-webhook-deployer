<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure;

use Deployer\Auth\Application\Login;
use Deployer\Auth\Application\Sessions;
use Deployer\Auth\Application\SetPassword;
use Deployer\Auth\Application\SetupAdmin;
use Deployer\Auth\Domain\UserRepository;
use Deployer\Auth\Infrastructure\Cli\UserPasswordCommand;
use Deployer\Auth\Infrastructure\Http\AuthController;
use Deployer\Auth\Infrastructure\Http\AuthGuard;
use Deployer\Auth\Infrastructure\SqliteLoginAttempts;
use Deployer\Auth\Infrastructure\SqliteSessionRepository;
use Deployer\Auth\Infrastructure\SqliteUserRepository;
use Deployer\Notifiers\Application\NotifyRunFinished;
use Deployer\Notifiers\Application\Port\Notifier;
use Deployer\Notifiers\Application\SaveNotifier;
use Deployer\Notifiers\Domain\NotifierRepository;
use Deployer\Notifiers\Infrastructure\Http\NotifiersController;
use Deployer\Notifiers\Infrastructure\SqliteNotifierRepository;
use Deployer\Notifiers\Infrastructure\TelegramNotifier;
use Deployer\Pipeline\Infrastructure\YamlPipelineParser;
use Deployer\Projects\Application\ProjectInstructions;
use Deployer\Projects\Application\SaveProject;
use Deployer\Projects\Application\WebhookTokens;
use Deployer\Projects\Infrastructure\Http\ProjectsController;
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
use Deployer\Runs\Infrastructure\Http\RunsController;
use Deployer\Runs\Infrastructure\Http\WebhookController;
use Deployer\Runs\Infrastructure\PosixProcessProbe;
use Deployer\Runs\Infrastructure\SqliteLogRepository;
use Deployer\Runs\Infrastructure\SqliteRunRepository;
use Deployer\Runs\Infrastructure\SymfonyProcessStepExecutor;
use Deployer\Shared\Application\Port\HttpClient;
use Deployer\Shared\Domain\Clock;
use Deployer\Shared\Domain\ValidationFailed;
use Deployer\Shared\Infrastructure\Cli\CliApplication;
use Deployer\Shared\Infrastructure\Cli\Command\AboutCommand;
use Deployer\Shared\Infrastructure\Cli\Command\MigrateCommand;
use Deployer\Shared\Infrastructure\Cli\Console;
use Deployer\Shared\Infrastructure\Config\AppConfig;
use Deployer\Shared\Infrastructure\Config\ConfigLoader;
use Deployer\Shared\Infrastructure\Http\ApiError;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Http\Response;
use Deployer\Shared\Infrastructure\Http\Router;
use Deployer\Shared\Infrastructure\Http\StaticSite;
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
    private ?PDO $pdo = null;

    public function __construct(
        private readonly AppConfig $config,
        private readonly Clock $clock = new SystemClock(),
        /** Overrides the detached launcher (tests). */
        private readonly ?RunnerLauncher $launcher = null,
        /** Overrides the curl client (tests). */
        private readonly ?HttpClient $httpClient = null,
        /** Directory holding the built SPA; defaults to frontend/dist next to src/ (inside the phar when packaged). */
        private readonly ?string $webRoot = null,
        /** Overrides the stamped build version (tests). */
        private readonly ?string $version = null,
    ) {
    }

    public function version(): string
    {
        return $this->version ?? AppVersion::current();
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

    public function users(): UserRepository
    {
        return new SqliteUserRepository($this->pdo());
    }

    public function sessions(): Sessions
    {
        return new Sessions(new SqliteSessionRepository($this->pdo()), $this->clock);
    }

    public function setPassword(): SetPassword
    {
        return new SetPassword($this->users(), $this->sessions(), $this->clock);
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

    public function notifiers(): NotifierRepository
    {
        return new SqliteNotifierRepository($this->pdo());
    }

    public function notifier(): Notifier
    {
        return new TelegramNotifier($this->httpClient ?? new CurlHttpClient());
    }

    public function runnerLauncher(): RunnerLauncher
    {
        return $this->launcher ?? new DetachedRunnerLauncher($this->config->phpCli, $this->config->entryScript, $this->config->dataDir);
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
            new NotifyRunFinished($this->projects(), $this->notifiers(), $this->runs(), $this->logs(), $this->notifier(), $this->clock),
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
            ->add(new CancelRunCommand($this))
            ->add(new UserPasswordCommand($this));
    }

    public function router(): Router
    {
        $router = (new Router())
            // Public: deliberately does not disclose the version (it is in /api/me).
            ->add('GET', '/api/health', fn (): Response => Response::json(['status' => 'ok']));

        (new AuthController(
            $this->users(),
            new SetupAdmin($this->users(), $this->clock),
            new Login($this->users(), new SqliteLoginAttempts($this->pdo()), $this->sessions(), $this->clock),
            $this->sessions(),
            $this->version(),
        ))->register($router);
        (new NotifiersController($this->notifiers(), new SaveNotifier($this->notifiers()), $this->notifier()))->register($router);
        (new ProjectsController(
            $this->projects(),
            new SaveProject($this->projects(), $this->notifiers()),
            new ProjectInstructions(new FilesystemInstructionsStore(), new YamlPipelineParser()),
            new WebhookTokens($this->projects()),
            $this->runs(),
        ))->register($router);
        (new RunsController($this->projects(), $this->runs(), $this->enqueueRun(), $this->cancelRun(), $this->readRunLogs()))->register($router);
        (new WebhookController(new WebhookTokens($this->projects()), $this->enqueueRun()))->register($router);

        return $router;
    }

    /** @param list<string> $argv */
    public function runCli(array $argv, ?Console $console = null): int
    {
        return $this->cli()->run($argv, $console ?? Console::std());
    }

    public function handle(Request $request): Response
    {
        if (StaticSite::handles($request)) {
            return (new StaticSite($this->webRoot ?? dirname(__DIR__, 3) . '/frontend/dist'))->respond($request);
        }

        try {
            // Webhooks still trigger with an oversized body (e.g. a huge push payload); it is just ignored.
            if ($request->bodyTooLarge && !str_starts_with($request->path, '/hooks/')) {
                throw new ApiError(413, 'payload_too_large', 'Request body is too large.');
            }
            $request = (new AuthGuard($this->sessions()))($request);
            $response = $this->router()->dispatch($request);
        } catch (ApiError $e) {
            $response = $e->toResponse();
        } catch (ValidationFailed $e) {
            $response = ApiError::validation($e->errors)->toResponse();
        } catch (Throwable $e) {
            error_log('[deployer] ' . $e);
            $response = (new ApiError(500, 'internal_error', 'Internal server error.'))->toResponse();
        }

        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
