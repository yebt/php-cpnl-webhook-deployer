<?php

declare(strict_types=1);

namespace Deployer\Projects\Application;

use DomainException;

final class InvalidInstructions extends DomainException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The pipeline file is invalid.');
    }
}
