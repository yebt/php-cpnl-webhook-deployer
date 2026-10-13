<?php

declare(strict_types=1);

namespace Deployer\Auth\Application;

use DomainException;

final class AlreadySetUp extends DomainException
{
    public function __construct()
    {
        parent::__construct('The administrator account already exists.');
    }
}
