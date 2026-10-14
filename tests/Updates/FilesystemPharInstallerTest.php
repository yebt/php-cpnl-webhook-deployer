<?php

declare(strict_types=1);

namespace Deployer\Tests\Updates;

use Deployer\Tests\Support\TempDir;
use Deployer\Tests\Support\TinyPhar;
use Deployer\Updates\Application\UpdateRefused;
use Deployer\Updates\Infrastructure\FilesystemPharInstaller;
use PHPUnit\Framework\TestCase;

final class FilesystemPharInstallerTest extends TestCase
{
    use TempDir;

    private string $dir;
    private string $target;

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
        $this->target = $this->dir . '/index.php';
        TinyPhar::build($this->target, '1.0.0');
        chmod($this->target, 0640);
    }

    protected function tearDown(): void
    {
        chmod($this->dir, 0700);
        $this->removeTempDirs();
    }

    private function newPharBytes(string $version): string
    {
        $path = $this->makeTempDir() . '/release.phar';
        TinyPhar::build($path, $version);

        return (string) file_get_contents($path);
    }

    private function execute(string $path): string
    {
        return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path));
    }

    public function testSwapsTheArtifactKeepingABackupAndPermissions(): void
    {
        $old = (string) file_get_contents($this->target);

        $backup = (new FilesystemPharInstaller())->install($this->target, $this->newPharBytes('1.1.0'), '1.1.0');

        self::assertSame($this->target . '.bak', $backup);
        self::assertSame($old, file_get_contents($backup));
        self::assertSame("tiny deployer 1.1.0\n", $this->execute($this->target));
        self::assertSame("tiny deployer 1.0.0\n", $this->execute($backup), 'the backup is a runnable rollback');
        self::assertSame(0640, fileperms($this->target) & 0777);
        self::assertSame(['index.php', 'index.php.bak'], $this->files(), 'no temp file is left behind');
    }

    public function testRejectsBytesThatAreNotAPhar(): void
    {
        $this->assertRefusedAndUntouched(UpdateRefused::INVALID_PHAR, "<?php echo 'pwned';");
    }

    public function testRejectsATamperedPhar(): void
    {
        $bytes = $this->newPharBytes('1.1.0');
        $pos = strpos($bytes, 'tiny deployer');
        self::assertIsInt($pos);
        $bytes[$pos] = 'T';

        $this->assertRefusedAndUntouched(UpdateRefused::INVALID_PHAR, $bytes);
    }

    public function testRejectsAPharStampedWithAnotherVersion(): void
    {
        $this->assertRefusedAndUntouched(UpdateRefused::INVALID_PHAR, $this->newPharBytes('1.2.0'), '1.1.0');
    }

    public function testNotWritableDirectoryIsRefused(): void
    {
        chmod($this->dir, 0500);
        if (is_writable($this->dir)) {
            self::markTestSkipped('running as a user that ignores permissions');
        }

        try {
            (new FilesystemPharInstaller())->assertWritable($this->target);
            self::fail('expected UpdateRefused');
        } catch (UpdateRefused $e) {
            self::assertSame(UpdateRefused::NOT_WRITABLE, $e->reason);
            self::assertStringContainsString($this->dir, $e->getMessage());
        }
    }

    public function testWritableArtifactPasses(): void
    {
        (new FilesystemPharInstaller())->assertWritable($this->target);
        $this->addToAssertionCount(1);
    }

    private function assertRefusedAndUntouched(string $reason, string $bytes, string $version = '1.1.0'): void
    {
        $before = (string) file_get_contents($this->target);
        try {
            (new FilesystemPharInstaller())->install($this->target, $bytes, $version);
            self::fail('expected UpdateRefused');
        } catch (UpdateRefused $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
        self::assertSame($before, file_get_contents($this->target));
        self::assertSame(['index.php'], $this->files(), 'no backup or temp file on refusal');
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_values(array_diff((array) scandir($this->dir), ['.', '..']));
        sort($files);

        return $files;
    }
}
