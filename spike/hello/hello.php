<?php

function main(): void
{
    echo "Hello from TypePHP!\n";
    echo 'PHP_VERSION=' . phpversion() . ' ZTS=' . (PHP_ZTS ? '1' : '0') . "\n";
    echo 'uname=' . php_uname('s') . ' ' . php_uname('m') . "\n";
}
