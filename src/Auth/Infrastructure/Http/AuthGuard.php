<?php

declare(strict_types=1);

namespace Deployer\Auth\Infrastructure\Http;

use Deployer\Auth\Application\Sessions;
use Deployer\Auth\Domain\Session;
use Deployer\Shared\Infrastructure\Http\ApiError;
use Deployer\Shared\Infrastructure\Http\Request;

/**
 * Protects /api: every route except the public ones needs a live session, and
 * state-changing methods also need the session's CSRF token in X-CSRF-Token.
 */
final class AuthGuard
{
    public const SESSION_ATTRIBUTE = 'session';
    /** @var list<string> */
    private const PUBLIC_PATHS = ['/api/health', '/api/setup/status', '/api/setup', '/api/login'];

    public function __construct(private readonly Sessions $sessions)
    {
    }

    /** @throws ApiError */
    public function __invoke(Request $request): Request
    {
        if (!str_starts_with($request->path, '/api/') || in_array($request->path, self::PUBLIC_PATHS, true)) {
            return $request;
        }

        $session = $this->sessions->resolve($request->cookie(SessionCookie::NAME));
        if ($session === null) {
            throw new ApiError(401, 'unauthenticated', 'Authentication required.');
        }
        if (!$request->isSafeMethod() && !hash_equals($session->csrfToken, (string) $request->header('x-csrf-token'))) {
            throw new ApiError(403, 'csrf_mismatch', 'Missing or invalid X-CSRF-Token header.');
        }

        return $request->withAttribute(self::SESSION_ATTRIBUTE, $session);
    }

    public static function session(Request $request): Session
    {
        $session = $request->attribute(self::SESSION_ATTRIBUTE);

        return $session instanceof Session ? $session : throw new ApiError(401, 'unauthenticated', 'Authentication required.');
    }
}
