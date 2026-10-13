<?php

declare(strict_types=1);

namespace Deployer\Runs\Application\Port;

final class StepOutcome
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const TIMED_OUT = 'timed_out';
    public const CANCELLED = 'cancelled';

    private function __construct(
        public readonly string $result,
        /** Null when the process was signalled (Symfony reports -1 after SIGKILL). */
        public readonly ?int $exitCode,
        public readonly float $durationSeconds,
    ) {
    }

    public static function finished(int $exitCode, float $duration): self
    {
        return new self($exitCode === 0 ? self::SUCCEEDED : self::FAILED, $exitCode, $duration);
    }

    public static function timedOut(float $duration): self
    {
        return new self(self::TIMED_OUT, null, $duration);
    }

    public static function cancelled(float $duration): self
    {
        return new self(self::CANCELLED, null, $duration);
    }
}
