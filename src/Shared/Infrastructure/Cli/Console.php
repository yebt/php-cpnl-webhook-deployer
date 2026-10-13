<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Cli;

/**
 * Thin wrapper over output streams so commands are testable.
 */
final class Console
{
    /** @param resource $out @param resource $err */
    public function __construct(private $out, private $err)
    {
    }

    public static function std(): self
    {
        return new self(STDOUT, STDERR);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->err, $text . PHP_EOL);
    }
}
