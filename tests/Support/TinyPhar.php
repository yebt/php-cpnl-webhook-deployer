<?php

declare(strict_types=1);

namespace Deployer\Tests\Support;

use RuntimeException;

/**
 * Builds a minimal phar shaped like the real artifact (alias deployer.phar,
 * VERSION at the root, public/index.php as entry) in a subprocess, because
 * phar.readonly cannot be disabled at runtime.
 */
final class TinyPhar
{
    public static function build(string $path, string $version, ?string $entry = null): void
    {
        $entry ??= "<?php echo 'tiny deployer " . $version . "', PHP_EOL;";
        $script = <<<'PHP'
            [$path, $version, $entry] = array_slice($argv, 1);
            // ext/phar only creates files with a .phar name; the installed artifact is often index.php.
            $build = $path . '.build.phar';
            @unlink($build);
            $phar = new Phar($build, 0, 'deployer.phar');
            $phar->startBuffering();
            $phar->addFromString('VERSION', $version . "\n");
            $phar->addFromString('public/index.php', $entry);
            $phar->setStub("<?php\nPhar::mapPhar('deployer.phar');\nrequire 'phar://deployer.phar/public/index.php';\n__HALT_COMPILER();");
            $phar->stopBuffering();
            unset($phar);
            rename($build, $path);
            PHP;
        $cmd = sprintf(
            '%s -d phar.readonly=0 -r %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($path),
            escapeshellarg($version),
            escapeshellarg($entry),
        );
        exec($cmd, $output, $code);
        if ($code !== 0 || !is_file($path)) {
            throw new RuntimeException('Cannot build test phar: ' . implode("\n", $output));
        }
    }
}
