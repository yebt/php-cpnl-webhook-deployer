<?php

declare(strict_types=1);

namespace Deployer\Auth\Application;

use DomainException;

final class TooManyAttempts extends DomainException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many failed login attempts. Try again later.');
    }
}
