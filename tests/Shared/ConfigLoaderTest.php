<?php

declare(strict_types=1);

namespace Deployer\Tests\Shared;

use Deployer\Shared\Infrastructure\Config\ConfigLoader;
use Deployer\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigLoaderTest extends TestCase
{
    use TempDir;

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testPharDefaultsPutDataDirOutsideTheArtifactDirectory(): void
    {
        $root = $this->makeTempDir();
        mkdir($root . '/public_html');

        $config = ConfigLoader::load($root . '/public_html/index.php', true, [], 'fpm-fcgi', '/usr/sbin/php-fpm');

        self::assertSame($root . '/deployer-data', $config->dataDir);
        self::assertSame('php', $config->phpCli, 'php-fpm binary must never be used as the CLI');
        self::assertNull($config->configFile);
    }

    public function testSourceDefaultsUseRepositoryDataDirAndCliBinary(): void
    {
        $root = $this->makeTempDir();

        $config = ConfigLoader::load($root . '/bin/deployer', false, [], 'cli', '/usr/bin/php8.2');

        self::assertSame($root . '/data', $config->dataDir);
        self::assertSame('/usr/bin/php8.2', $config->phpCli);
    }

    public function testConfigFileNextToArtifactIsApplied(): void
    {
        $root = $this->makeTempDir();
        file_put_contents($root . '/deployer.config.php', "<?php return ['data_dir' => 'storage', 'php_cli' => '/opt/cpanel/ea-php82/root/usr/bin/php', 'kill_grace_seconds' => 2];");

        $config = ConfigLoader::load($root . '/index.php', true, [], 'fpm-fcgi', '/usr/sbin/php-fpm');

        self::assertSame($root . '/storage', $config->dataDir);
        self::assertSame('/opt/cpanel/ea-php82/root/usr/bin/php', $config->phpCli);
        self::assertSame(2.0, $config->killGraceSeconds);
        self::assertSame($root . '/deployer.config.php', $config->configFile);
    }

    public function testEnvironmentOverridesConfigFile(): void
    {
        $root = $this->makeTempDir();
        file_put_contents($root . '/deployer.config.php', "<?php return ['data_dir' => '/from/file', 'php_cli' => '/file/php'];");

        $config = ConfigLoader::load($root . '/index.php', true, [
            'DEPLOYER_DATA_DIR' => '/from/env/',
            'DEPLOYER_PHP_CLI' => '/env/php',
        ], 'cli', '/usr/bin/php');

        self::assertSame('/from/env', $config->dataDir);
        self::assertSame('/env/php', $config->phpCli);
    }

    public function testConfigFileMustReturnArray(): void
    {
        $root = $this->makeTempDir();
        file_put_contents($root . '/deployer.config.php', '<?php return "nope";');

        $this->expectException(RuntimeException::class);
        ConfigLoader::load($root . '/index.php', true, [], 'cli', '/usr/bin/php');
    }
}
