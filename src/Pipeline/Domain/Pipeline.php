<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Domain;

final class Pipeline
{
    /** Applied when a step has no explicit timeout, so a hung step cannot block a project's queue forever. */
    public const DEFAULT_TIMEOUT = 3600;

    /**
     * @param list<Step>            $steps
     * @param array<string, string> $vars resolved user vars (built-ins excluded)
     */
    public function __construct(
        public readonly array $steps,
        public readonly array $vars,
    ) {
    }
}
