<?php

declare(strict_types=1);

/*
 * Builds dist/deployer.phar.
 *
 * Usage: php -d phar.readonly=0 build/build-phar.php
 *
 * Production dependencies are installed into a clean staging directory
 * (composer install --no-dev) so dev packages and their autoload entries never
 * reach the artifact. The stub has no shebang on purpose: the phar is served by
 * PHP-FPM as index.php, and CLI use is `php deployer.phar <command>`.
 */

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is enabled; run with: php -d phar.readonly=0 build/build-phar.php\n");
    exit(1);
}

$root = dirname(__DIR__);
$staging = $root . '/build/.staging';
$dist = $root . '/dist';
$target = $dist . '/deployer.phar';
$composer = getenv('COMPOSER_BIN') ?: 'composer';

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function copyTree(string $from, string $to): void
{
    if (!is_dir($to)) {
        mkdir($to, 0755, true);
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    ) as $item) {
        $dest = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        $item->isDir() ? (is_dir($dest) || mkdir($dest, 0755, true)) : copy($item->getPathname(), $dest);
    }
}

removeTree($staging);
mkdir($staging, 0755, true);
foreach (['composer.json', 'composer.lock'] as $file) {
    copy($root . '/' . $file, $staging . '/' . $file);
}
foreach (['src', 'public'] as $dir) {
    copyTree($root . '/' . $dir, $staging . '/' . $dir);
}

$cmd = sprintf(
    '%s install --no-dev --classmap-authoritative --no-interaction --no-progress --quiet --working-dir=%s',
    escapeshellcmd($composer),
    escapeshellarg($staging),
);
passthru($cmd, $code);
if ($code !== 0) {
    fwrite(STDERR, "composer install failed ($code)\n");
    exit(1);
}

if (!is_dir($dist)) {
    mkdir($dist, 0755, true);
}
if (is_file($target)) {
    unlink($target);
}

$phar = new Phar($target, 0, 'deployer.phar');
$phar->startBuffering();
$phar->buildFromDirectory($staging, '#^' . preg_quote($staging, '#') . '/(src|public|vendor)/(?!.*/(tests?|Tests?|docs?)/).*\.php$#');
$phar->setStub(<<<'STUB'
<?php
Phar::mapPhar('deployer.phar');
require 'phar://deployer.phar/public/index.php';
__HALT_COMPILER();
STUB);
$phar->stopBuffering();
unset($phar);

removeTree($staging);

printf("Built %s (%s KiB)\n", $target, number_format(filesize($target) / 1024, 1));
