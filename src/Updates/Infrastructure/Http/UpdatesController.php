<?php

declare(strict_types=1);

namespace Deployer\Updates\Infrastructure\Http;

use Closure;
use Deployer\Shared\Infrastructure\Http\ApiError;
use Deployer\Shared\Infrastructure\Http\Response;
use Deployer\Shared\Infrastructure\Http\Router;
use Deployer\Updates\Application\ApplyUpdate;
use Deployer\Updates\Application\CheckForUpdate;
use Deployer\Updates\Application\UpdateFailed;
use Deployer\Updates\Application\UpdateRefused;

/**
 * GET /api/updates and POST /api/updates/apply (session + CSRF via AuthGuard).
 * The API never forces: dev builds and reinstalls are CLI-only (`self-update --force`).
 */
final class UpdatesController
{
    private const NOTES_EXCERPT_CHARS = 600;

    /**
     * Use cases are built lazily so a bad update setting only breaks these two routes.
     *
     * @param Closure(): CheckForUpdate $check
     * @param Closure(): ApplyUpdate $apply
     * @param Closure(): int $activeRuns
     */
    public function __construct(
        private readonly Closure $check,
        private readonly Closure $apply,
        private readonly bool $runningFromPhar,
        private readonly Closure $activeRuns,
    ) {
    }

    public function register(Router $router): void
    {
        $router
            ->add('GET', '/api/updates', fn (): Response => $this->status())
            ->add('POST', '/api/updates/apply', fn (): Response => $this->applyLatest());
    }

    private function status(): Response
    {
        try {
            $check = (($this->check)())();
        } catch (UpdateFailed $e) {
            throw new ApiError(502, 'update_check_failed', $e->getMessage());
        }
        $latest = $check->latest;

        return Response::json([
            'current' => $check->current,
            'current_is_dev' => $check->currentIsDev,
            'available' => $check->available,
            'can_apply' => $this->runningFromPhar,
            'active_runs' => ($this->activeRuns)(),
            'latest' => $latest === null ? null : [
                'version' => (string) $latest->version,
                'tag' => $latest->tag,
                'name' => $latest->name,
                'url' => $latest->htmlUrl,
                'published_at' => $latest->publishedAt,
                'notes_excerpt' => self::excerpt($latest->notes),
            ],
        ]);
    }

    private function applyLatest(): Response
    {
        try {
            $result = (($this->apply)())(false);
        } catch (UpdateRefused $e) {
            throw new ApiError(409, $e->reason, $e->getMessage());
        } catch (UpdateFailed $e) {
            throw new ApiError(502, 'update_failed', $e->getMessage());
        }

        return Response::json([
            'updated' => $result->updated,
            'from' => $result->from,
            'to' => $result->to,
            'backup_file' => $result->backupPath === null ? null : basename($result->backupPath),
        ]);
    }

    private static function excerpt(string $notes): string
    {
        $notes = trim($notes);

        return mb_strlen($notes) > self::NOTES_EXCERPT_CHARS
            ? rtrim(mb_substr($notes, 0, self::NOTES_EXCERPT_CHARS)) . '…'
            : $notes;
    }
}
