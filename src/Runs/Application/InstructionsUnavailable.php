<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use RuntimeException;

/** The project's instructions file is missing, unreadable or invalid. */
final class InstructionsUnavailable extends RuntimeException
{
}
