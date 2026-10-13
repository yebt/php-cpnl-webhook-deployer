<?php

declare(strict_types=1);

/*
 * Single front controller for both SAPIs:
 *  - web (Apache + PHP-FPM): routes the HTTP request;
 *  - cli: `php index.php <command>` dispatches to the command registry.
 */

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Application::fromEnvironment();

if (PHP_SAPI === 'cli') {
    exit($app->runCli(array_values($_SERVER['argv'] ?? [])));
}

$app->handle(Request::fromGlobals())->send();
