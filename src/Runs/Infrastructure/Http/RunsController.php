<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Http;

use Deployer\Projects\Domain\Project;
use Deployer\Projects\Domain\ProjectRepository;
use Deployer\Runs\Application\CancelRun;
use Deployer\Runs\Application\EnqueueRun;
use Deployer\Runs\Application\ReadRunLogs;
use Deployer\Runs\Domain\Run;
use Deployer\Runs\Domain\RunFilter;
use Deployer\Runs\Domain\RunRepository;
use Deployer\Runs\Domain\RunStatus;
use Deployer\Shared\Infrastructure\Http\ApiError;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Http\Response;
use Deployer\Shared\Infrastructure\Http\RouteParams;
use Deployer\Shared\Infrastructure\Http\Router;

final class RunsController
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly RunRepository $runs,
        private readonly EnqueueRun $enqueue,
        private readonly CancelRun $cancel,
        private readonly ReadRunLogs $logs,
    ) {
    }

    public function register(Router $router): void
    {
        $router
            ->add('POST', '/api/projects/{id}/runs', function (Request $r, array $p): Response {
                $project = $this->projects->find(RouteParams::id($p)) ?? throw ApiError::notFound('Project not found.');
                $runId = ($this->enqueue)($project->id, 'manual');

                return Response::json(['run_id' => $runId, 'status' => RunStatus::Queued->value], 202);
            })
            ->add('GET', '/api/runs', $this->list(...))
            ->add('GET', '/api/runs/{id}', function (Request $r, array $p): Response {
                $run = $this->load($p);
                $data = RunPresenter::run($run, $this->projects->find($run->projectId));
                $data['steps'] = array_map(RunPresenter::step(...), $this->runs->steps($run->id));
                $data['pipeline_yaml'] = $run->pipelineYaml;

                return Response::json(['run' => $data]);
            })
            ->add('GET', '/api/runs/{id}/logs', $this->readLogs(...))
            ->add('POST', '/api/runs/{id}/cancel', function (Request $r, array $p): Response {
                $result = ($this->cancel)($this->load($p)->id);

                return match ($result) {
                    CancelRun::CANCELLED => Response::json(['result' => $result]),
                    CancelRun::REQUESTED => Response::json(['result' => $result], 202),
                    CancelRun::NOT_FOUND => throw ApiError::notFound('Run not found.'),
                    default => throw new ApiError(409, 'already_finished', 'The run has already finished.'),
                };
            });
    }

    private function list(Request $request): Response
    {
        $errors = [];
        $projectId = self::optionalId($request->queryValue('project'), 'project', $errors);
        $beforeId = self::optionalId($request->queryValue('before'), 'before', $errors);
        $status = null;
        if (($raw = $request->queryValue('status')) !== null) {
            $status = RunStatus::tryFrom($raw);
            if ($status === null) {
                $errors['status'] = sprintf('One of: %s.', implode(', ', array_map(static fn (RunStatus $s): string => $s->value, RunStatus::cases())));
            }
        }
        $limit = $request->queryValue('limit') ?? '50';
        if (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > RunFilter::MAX_LIMIT) {
            $errors['limit'] = sprintf('Between 1 and %d.', RunFilter::MAX_LIMIT);
        }
        if ($errors !== []) {
            throw ApiError::validation($errors);
        }

        $runs = $this->runs->search(new RunFilter($projectId, $status, (int) $limit, $beforeId));
        /** @var array<int, ?Project> $projects */
        $projects = [];
        $items = [];
        foreach ($runs as $run) {
            $projects[$run->projectId] ??= $this->projects->find($run->projectId);
            $items[] = RunPresenter::run($run, $projects[$run->projectId]);
        }

        return Response::json(['runs' => $items]);
    }

    /** @param array<string,string> $params */
    private function readLogs(Request $request, array $params): Response
    {
        $run = $this->load($params);
        $after = $request->queryValue('after') ?? '0';
        $limit = $request->queryValue('limit') ?? '500';
        if (!ctype_digit($after) || !ctype_digit($limit)) {
            throw ApiError::validation(['after' => 'Use non-negative integers for after and limit.']);
        }
        $limit = max(1, min((int) $limit, ReadRunLogs::MAX_LIMIT));
        $chunk = ($this->logs)($run->id, (int) $after, $limit);

        return Response::json([
            'lines' => array_map(RunPresenter::logLine(...), $chunk->lines),
            'next_after' => $chunk->nextAfterId,
            'run_status' => $chunk->runStatus->value,
            'complete' => $chunk->isComplete($limit),
        ]);
    }

    /** @param array<string,string> $params */
    private function load(array $params): Run
    {
        return $this->runs->find(RouteParams::id($params)) ?? throw ApiError::notFound('Run not found.');
    }

    /** @param array<string,string> $errors */
    private static function optionalId(?string $value, string $field, array &$errors): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!preg_match('/^[1-9]\d{0,17}$/', $value)) {
            $errors[$field] = 'Expected a positive integer id.';

            return null;
        }

        return (int) $value;
    }
}
