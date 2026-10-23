<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Domain;

/**
 * A named, on-demand instruction set declared under `actions:` (e.g. clearing
 * a cache). It runs like a deploy, but with its own steps instead of `steps`.
 */
final class Action
{
    /** Action ids are used in URLs and on the command line. */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** @param non-empty-list<Step> $steps */
    public function __construct(
        public readonly string $id,
        /** Display name; defaults to the id. */
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $steps,
    ) {
    }
}
