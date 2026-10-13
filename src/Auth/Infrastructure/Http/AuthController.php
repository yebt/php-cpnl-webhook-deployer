<?php

declare(strict_types=1);

namespace Deployer\Auth\Infrastructure\Http;

use Deployer\Auth\Application\AlreadySetUp;
use Deployer\Auth\Application\InvalidCredentials;
use Deployer\Auth\Application\IssuedSession;
use Deployer\Auth\Application\Login;
use Deployer\Auth\Application\Sessions;
use Deployer\Auth\Application\SetupAdmin;
use Deployer\Auth\Application\TooManyAttempts;
use Deployer\Auth\Domain\User;
use Deployer\Auth\Domain\UserRepository;
use Deployer\Shared\Infrastructure\Http\ApiError;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Http\Response;
use Deployer\Shared\Infrastructure\Http\Router;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SetupAdmin $setupAdmin,
        private readonly Login $login,
        private readonly Sessions $sessions,
    ) {
    }

    public function register(Router $router): void
    {
        $router
            ->add('GET', '/api/setup/status', fn (): Response => Response::json(['needs_setup' => !$this->users->exists()]))
            ->add('POST', '/api/setup', $this->setup(...))
            ->add('POST', '/api/login', $this->login(...))
            ->add('POST', '/api/logout', $this->logout(...))
            ->add('GET', '/api/me', $this->me(...));
    }

    private function setup(Request $request): Response
    {
        [$username, $password] = $this->credentials($request);
        try {
            $user = ($this->setupAdmin)($username, $password);
        } catch (AlreadySetUp $e) {
            throw new ApiError(409, 'already_set_up', $e->getMessage());
        }

        return $this->sessionResponse($request, $user, $this->sessions->issue($user->id), 201);
    }

    private function login(Request $request): Response
    {
        [$username, $password] = $this->credentials($request);
        try {
            $issued = ($this->login)($username, $password, $request->remoteAddr, $request->cookie(SessionCookie::NAME));
        } catch (TooManyAttempts $e) {
            throw new ApiError(429, 'too_many_attempts', $e->getMessage(), headers: ['Retry-After' => (string) $e->retryAfterSeconds]);
        } catch (InvalidCredentials $e) {
            throw new ApiError(401, 'invalid_credentials', $e->getMessage());
        }
        $user = $this->users->find($issued->session->userId) ?? throw ApiError::notFound();

        return $this->sessionResponse($request, $user, $issued, 200);
    }

    private function logout(Request $request): Response
    {
        $this->sessions->revoke($request->cookie(SessionCookie::NAME));

        return Response::noContent()->withCookie(SessionCookie::clear($request));
    }

    private function me(Request $request): Response
    {
        $session = AuthGuard::session($request);
        $user = $this->users->find($session->userId) ?? throw new ApiError(401, 'unauthenticated', 'Authentication required.');

        return Response::json(['user' => $this->user($user), 'csrf_token' => $session->csrfToken]);
    }

    /** @return array{string, string} */
    private function credentials(Request $request): array
    {
        $data = $request->json(8192);

        return [
            is_string($data['username'] ?? null) ? trim($data['username']) : '',
            is_string($data['password'] ?? null) ? $data['password'] : '',
        ];
    }

    private function sessionResponse(Request $request, User $user, IssuedSession $issued, int $status): Response
    {
        return Response::json(['user' => $this->user($user), 'csrf_token' => $issued->session->csrfToken], $status)
            ->withCookie(SessionCookie::issue($issued->token, $request));
    }

    /** @return array{id: int, username: string} */
    private function user(User $user): array
    {
        return ['id' => $user->id, 'username' => $user->username];
    }
}
