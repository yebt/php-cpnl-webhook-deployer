<?php

declare(strict_types=1);

namespace Deployer\Updates\Application;

use RuntimeException;

/** The update could not be checked or applied because of an external error (network, API, disk). */
final class UpdateFailed extends RuntimeException
{
}
