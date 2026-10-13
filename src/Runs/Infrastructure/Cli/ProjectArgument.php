<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Cli;

use Deployer\Projects\Domain\Project;
use Deployer\Projects\Domain\ProjectRepository;
use InvalidArgumentException;

final class ProjectArgument
{
    /** Resolves a project by numeric id or slug. */
    public static function resolve(ProjectRepository $projects, ?string $value): Project
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException('Missing <project> argument (id or slug).');
        }
        $project = ctype_digit($value) ? $projects->find((int) $value) : $projects->findBySlug($value);

        return $project ?? throw new InvalidArgumentException(sprintf('Project "%s" not found.', $value));
    }
}
