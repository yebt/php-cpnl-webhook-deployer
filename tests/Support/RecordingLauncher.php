<?php

declare(strict_types=1);

namespace Deployer\Tests\Support;

use Deployer\Runs\Application\Port\RunnerLauncher;

final class RecordingLauncher implements RunnerLauncher
{
    /** @var list<int> */
    public array $launched = [];

    public function launch(int $projectId): void
    {
        $this->launched[] = $projectId;
    }
}
