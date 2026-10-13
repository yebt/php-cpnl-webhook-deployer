<?php

declare(strict_types=1);

namespace Deployer\Projects\Application;

use DomainException;

final class SlugTaken extends DomainException
{
    public function __construct(string $slug)
    {
        parent::__construct(sprintf('A project with slug "%s" already exists.', $slug));
    }
}
