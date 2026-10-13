<?php

declare(strict_types=1);

namespace Deployer\Auth\Application;

use DomainException;

final class InvalidCredentials extends DomainException
{
    public function __construct()
    {
        parent::__construct('Invalid username or password.');
    }
}
