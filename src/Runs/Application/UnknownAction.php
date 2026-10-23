<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use DomainException;

/** The requested action is not defined in the project's current instructions file. */
final class UnknownAction extends DomainException
{
}
