<?php

declare(strict_types=1);

namespace Deployer\Projects\Infrastructure\Http;

use Deployer\Projects\Application\InvalidInstructions;
use Deployer\Projects\Application\ProjectInstructions;
use Deployer\Projects\Application\SaveProject;
use Deployer\Projects\Application\SlugTaken;
use Deployer\Projects\Application\WebhookTokens;
use Deployer\Projects\Domain\InstructionsPathOutsideFolder;
use Deployer\Projects\Domain\Project;
use Deployer\Projects\Domain\ProjectRepository;
use Deployer\Runs\Domain\RunRepository;
use Deployer\Shared\Infrastructure\Http\ApiError;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Http\Response;
use Deployer\Shared\Infrastructure\Http\RouteParams;
use Deployer\Shared\Infrastructure\Http\Router;
use RuntimeException;

final class ProjectsController
{
    public const MAX_INSTRUCTIONS_BYTES = 262144;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly SaveProject $save,
        private readonly ProjectInstructions $instructions,
        private readonly WebhookTokens $tokens,
        private readonly RunRepository $runs,
    ) {
    }

    public function register(Router $router): void
    {
        $router
            ->add('GET', '/api/projects', fn (): Response => Response::json([
                'projects' => array_map(self::present(...), $this->projects->all()),
            ]))
            ->add('POST', '/api/projects', fn (Request $r): Response => Response::json(
                ['project' => self::present($this->save($r->json(), null))],
                201,
            ))
            ->add('GET', '/api/projects/{id}', fn (Request $r, array $p): Response => Response::json(
                ['project' => self::present($this->load($p))],
            ))
            ->add('PUT', '/api/projects/{id}', fn (Request $r, array $p): Response => Response::json(
                ['project' => self::present($this->save($r->json(), $this->load($p)))],
            ))
            ->add('DELETE', '/api/projects/{id}', $this->delete(...))
            ->add('GET', '/api/projects/{id}/instructions', $this->readInstructions(...))
            ->add('PUT', '/api/projects/{id}/instructions', $this->writeInstructions(...))
            ->add('POST', '/api/projects/{id}/token', function (Request $r, array $p): Response {
                $project = $this->load($p);

                return Response::json([
                    'token' => $this->tokens->regenerate($project),
                    'webhook_path' => self::webhookPath($project),
                    'header' => 'X-Deployer-Token',
                ], 201);
            });
    }

    /** @param array<string,string> $params */
    public function load(array $params): Project
    {
        return $this->projects->find(RouteParams::id($params)) ?? throw ApiError::notFound('Project not found.');
    }

    /** @return array<string,mixed> */
    public static function present(Project $project): array
    {
        return [
            'id' => $project->id,
            'slug' => $project->slug,
            'name' => $project->name,
            'folder' => $project->folder,
            'instructions_file' => $project->instructionsFile,
            'instructions_path' => $project->instructionsPath(),
            'notifier_id' => $project->notifierId,
            'has_webhook_token' => $project->webhookTokenHash !== null,
            'webhook_path' => self::webhookPath($project),
        ];
    }

    private static function webhookPath(Project $project): string
    {
        return '/hooks/' . rawurlencode($project->slug);
    }

    /** @param array<string,mixed> $input */
    private function save(array $input, ?Project $existing): Project
    {
        try {
            return ($this->save)($input, $existing);
        } catch (SlugTaken $e) {
            throw new ApiError(409, 'slug_taken', $e->getMessage());
        }
    }

    /** @param array<string,string> $params */
    private function delete(Request $request, array $params): Response
    {
        $project = $this->load($params);
        if ($this->runs->hasActive($project->id)) {
            throw new ApiError(409, 'project_busy', 'The project has queued or running runs; cancel them first.');
        }
        $this->projects->delete($project->id);

        return Response::noContent();
    }

    /** @param array<string,string> $params */
    private function readInstructions(Request $request, array $params): Response
    {
        $project = $this->load($params);
        $file = $this->guardFilesystem(fn (): array => $this->instructions->read($project), 'instructions_not_readable');
        $errors = $file['exists'] ? $this->instructions->validate($project, $file['content']) : ['File does not exist yet.'];

        return Response::json([
            'path' => $project->instructionsPath(),
            'exists' => $file['exists'],
            'content' => $file['content'],
            'valid' => $errors === [],
            'errors' => $errors,
        ]);
    }

    /** @param array<string,string> $params */
    private function writeInstructions(Request $request, array $params): Response
    {
        $project = $this->load($params);
        $content = $request->json(self::MAX_INSTRUCTIONS_BYTES * 2)['content'] ?? null;
        if (!is_string($content)) {
            throw ApiError::validation(['content' => 'Expected the YAML document as a string.']);
        }
        if (strlen($content) > self::MAX_INSTRUCTIONS_BYTES) {
            throw ApiError::validation(['content' => sprintf('At most %d bytes.', self::MAX_INSTRUCTIONS_BYTES)]);
        }
        $force = in_array($request->queryValue('force'), ['1', 'true'], true);

        try {
            $errors = $this->guardFilesystem(fn (): array => $this->instructions->save($project, $content, $force), 'instructions_not_writable');
        } catch (InvalidInstructions $e) {
            throw new ApiError(422, 'invalid_pipeline', 'The pipeline is invalid; fix it or save with ?force=1.', ['errors' => $e->errors]);
        }

        return Response::json([
            'saved' => true,
            'path' => $project->instructionsPath(),
            'valid' => $errors === [],
            'errors' => $errors,
        ]);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function guardFilesystem(callable $operation, string $failureCode): mixed
    {
        try {
            return $operation();
        } catch (InstructionsPathOutsideFolder $e) {
            throw new ApiError(409, 'instructions_path_outside_folder', $e->getMessage());
        } catch (RuntimeException $e) {
            throw new ApiError(409, $failureCode, $e->getMessage());
        }
    }
}
