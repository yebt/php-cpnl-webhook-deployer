<?php

declare(strict_types=1);

namespace Deployer\Tests\Shared;

use Deployer\Shared\Infrastructure\AppVersion;
use Deployer\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class AppVersionTest extends TestCase
{
    use TempDir;

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testReadsTheStampedVersionFile(): void
    {
        $file = $this->makeTempDir() . '/VERSION';
        file_put_contents($file, "1.4.2\n");

        self::assertSame('1.4.2', AppVersion::fromFile($file));
    }

    public function testAcceptsPrereleaseVersions(): void
    {
        $file = $this->makeTempDir() . '/VERSION';
        file_put_contents($file, '2.0.0-rc.1');

        self::assertSame('2.0.0-rc.1', AppVersion::fromFile($file));
    }

    public function testMissingOrInvalidFileMeansDevBuild(): void
    {
        $dir = $this->makeTempDir();
        self::assertSame(AppVersion::DEV, AppVersion::fromFile($dir . '/VERSION'));

        file_put_contents($dir . '/VERSION', 'not a version');
        self::assertSame(AppVersion::DEV, AppVersion::fromFile($dir . '/VERSION'));
    }

    public function testValidatesVersionStrings(): void
    {
        self::assertTrue(AppVersion::isValid('0.1.0'));
        self::assertTrue(AppVersion::isValid('10.20.30-beta.2'));
        self::assertTrue(AppVersion::isValid(AppVersion::DEV));
        self::assertFalse(AppVersion::isValid('v1.0.0'));
        self::assertFalse(AppVersion::isValid('1.0'));
        self::assertFalse(AppVersion::isValid("1.0.0\nX"));
    }

    public function testSourceCheckoutHasNoStampAndReportsDev(): void
    {
        self::assertSame(AppVersion::DEV, AppVersion::current());
    }
}
