<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Application;

use Deployer\Pipeline\Domain\BuiltinVars;
use Deployer\Pipeline\Domain\Pipeline;
use Deployer\Pipeline\Domain\PipelineValidationError;

interface PipelineParser
{
    /** @throws PipelineValidationError */
    public function parse(string $source, BuiltinVars $builtins): Pipeline;
}
