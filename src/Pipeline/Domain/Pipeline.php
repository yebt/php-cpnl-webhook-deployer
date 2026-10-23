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
     * @param array<string, string> $env     resolved top-level env (each step already merges it)
     * @param array<string, Action> $actions on-demand actions keyed by id, in declaration order
     */
    public function __construct(
        public readonly array $steps,
        public readonly array $vars,
        public readonly array $env = [],
        public readonly array $actions = [],
    ) {
    }

    public function action(string $id): ?Action
    {
        return $this->actions[$id] ?? null;
    }
}
