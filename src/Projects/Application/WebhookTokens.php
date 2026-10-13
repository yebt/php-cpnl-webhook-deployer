<?php

declare(strict_types=1);

namespace Deployer\Projects\Application;

use Deployer\Projects\Domain\Project;
use Deployer\Projects\Domain\ProjectRepository;

/** Per-project webhook tokens: shown once, stored as sha256, compared in constant time. */
final class WebhookTokens
{
    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    /** @return string the new raw token; the previous token stops working immediately */
    public function regenerate(Project $project): string
    {
        $token = bin2hex(random_bytes(32));
        $this->projects->setWebhookTokenHash($project->id, hash('sha256', $token));

        return $token;
    }

    /** Returns the project when the token matches; null for unknown slug, no token or mismatch. */
    public function verify(string $slug, ?string $token): ?Project
    {
        $project = $this->projects->findBySlug($slug);
        $expected = $project?->webhookTokenHash;
        // Hash even when there is nothing to compare, so timing does not reveal which case failed.
        $given = hash('sha256', (string) $token);
        if ($expected === null || $token === null || $token === '') {
            return null;
        }

        return hash_equals($expected, $given) ? $project : null;
    }
}
