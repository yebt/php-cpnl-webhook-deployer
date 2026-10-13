<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Http;

use Deployer\Projects\Application\WebhookTokens;
use Deployer\Runs\Application\EnqueueRun;
use Deployer\Runs\Domain\RunStatus;
use Deployer\Shared\Infrastructure\Http\ApiError;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Http\Response;
use Deployer\Shared\Infrastructure\Http\Router;

/** `POST /hooks/{slug}` with `X-Deployer-Token`: queues a run and starts its runner. */
final class WebhookController
{
    public function __construct(
        private readonly WebhookTokens $tokens,
        private readonly EnqueueRun $enqueue,
    ) {
    }

    public function register(Router $router): void
    {
        $router->add('POST', '/hooks/{slug}', function (Request $request, array $params): Response {
            $project = $this->tokens->verify($params['slug'], $request->header('x-deployer-token'));
            if ($project === null) {
                // Same answer for unknown project, missing and wrong token: no enumeration.
                throw new ApiError(401, 'invalid_token', 'Missing or invalid X-Deployer-Token.');
            }
            $runId = ($this->enqueue)($project->id, 'webhook', WebhookPayloadSummary::fromRequest($request));

            return Response::json(['run_id' => $runId, 'status' => RunStatus::Queued->value], 202);
        });
    }
}
