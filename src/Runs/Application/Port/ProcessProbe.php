<?php

declare(strict_types=1);

namespace Deployer\Runs\Application\Port;

interface ProcessProbe
{
    public function isAlive(int $pid): bool;
}
