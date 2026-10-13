<?php

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

function usage(): int
{
    fwrite(STDERR, "usage: deployer yaml <file>\n");
    return 2;
}

function main(int $argc, array $argv): void
{
    require_once __DIR__ . '/vendor/autoload.php';

    $cmd = $argv[1] ?? '';
    $code = 2;
    if ($cmd === 'yaml') {
        $code = cmdYaml($argv[2] ?? 'pipeline.yml');
    } else {
        $code = usage();
    }
    exit($code);
}
