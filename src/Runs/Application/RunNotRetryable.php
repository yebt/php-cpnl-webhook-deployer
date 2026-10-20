<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use RuntimeException;

final class RunNotRetryable extends RuntimeException
{
}
