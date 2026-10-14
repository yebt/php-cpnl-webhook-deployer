<?php

declare(strict_types=1);

namespace Deployer\Updates\Application;

use Deployer\Updates\Domain\Release;

final class UpdateCheck
{
    public function __construct(
        public readonly string $current,
        public readonly bool $currentIsDev,
        public readonly ?Release $latest,
        /** A newer release exists (always true for dev builds when a release exists). */
        public readonly bool $available,
    ) {
    }
}
