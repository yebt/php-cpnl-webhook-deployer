<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Http;

use Deployer\Projects\Application\WebhookTokens;
use Deployer\Projects\Domain\Project;
use Deployer\Runs\Application\EnqueueRun;
use Deployer\Runs\Application\ProjectActions;
use Deployer\Runs\Domain\RunStatus;
use Deployer\Shared\Infrastructure\Http\ApiError;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Http\Response;
use Deployer\Shared\Infrastructure\Http\Router;

/**
 * `POST /hooks/{slug}` with `X-Deployer-Token`: queues a deploy run and starts its runner.
 * `POST /hooks/{slug}/actions/{action}`: same, for one of the project's actions.
 */
final class WebhookController
{
    public function __construct(
        private readonly WebhookTokens $tokens,
        private readonly EnqueueRun $enqueue,
        private readonly ProjectActions $actions,
    ) {
    }

    public function register(Router $router): void
    {
        $router
            ->add('POST', '/hooks/{slug}', function (Request $request, array $params): Response {
                $project = $this->verify($request, $params['slug']);
                $runId = ($this->enqueue)($project->id, 'webhook', WebhookPayloadSummary::fromRequest($request));

                return Response::json(['run_id' => $runId, 'status' => RunStatus::Queued->value], 202);
            })
            ->add('POST', '/hooks/{slug}/actions/{action}', function (Request $request, array $params): Response {
                // The token is verified before the action is even looked up.
                $project = $this->verify($request, $params['slug']);

                return ActionPresenter::queued($this->actions, $project, $params['action'], 'webhook', WebhookPayloadSummary::fromRequest($request));
            });
    }

    private function verify(Request $request, string $slug): Project
    {
        $project = $this->tokens->verify($slug, $request->header('x-deployer-token'));
        if ($project === null) {
            // Same answer for unknown project, missing and wrong token: no enumeration.
            throw new ApiError(401, 'invalid_token', 'Missing or invalid X-Deployer-Token.');
        }

        return $project;
    }
}
