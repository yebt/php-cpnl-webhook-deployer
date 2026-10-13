<?php

declare(strict_types=1);

namespace Deployer\Tests\Support;

trait TempDir
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/deployer-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function removeTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
        $this->tempDirs = [];
    }
}
