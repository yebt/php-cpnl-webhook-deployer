<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure;

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

    public function cli(): CliApplication
    {
        return (new CliApplication())
            ->add(new AboutCommand($this))
            ->add(new MigrateCommand($this));
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
