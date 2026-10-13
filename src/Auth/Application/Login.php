<?php

declare(strict_types=1);

namespace Deployer\Auth\Application;

use Deployer\Auth\Domain\LoginAttempts;
use Deployer\Auth\Domain\UserRepository;
use Deployer\Shared\Domain\Clock;

final class Login
{
    /** Verified when the username is unknown so both failure paths cost the same. */
    private const DUMMY_HASH = '$2y$12$3wGXrj7dyRsA3hB7unIk5.uBE.tRjacaOzmrFwMju//HdJePlLsSm';

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginAttempts $attempts,
        private readonly Sessions $sessions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Verifies credentials and issues a NEW session (the previous one, if any, is revoked).
     *
     * @throws TooManyAttempts
     * @throws InvalidCredentials
     */
    public function __invoke(string $username, string $password, string $ip, ?string $previousToken): IssuedSession
    {
        $now = $this->clock->now();
        $windowStart = $now->modify(sprintf('-%d seconds', AuthPolicy::THROTTLE_WINDOW_SECONDS));
        $failures = $this->attempts->failuresSince($ip, $windowStart);
        if (count($failures) >= AuthPolicy::THROTTLE_MAX_FAILURES) {
            $oldestRelevant = $failures[count($failures) - AuthPolicy::THROTTLE_MAX_FAILURES];
            $retryAfter = $oldestRelevant->getTimestamp() + AuthPolicy::THROTTLE_WINDOW_SECONDS - $now->getTimestamp();

            throw new TooManyAttempts(max(1, $retryAfter));
        }

        $user = $this->users->findByUsername($username);
        $valid = password_verify($password, $user?->passwordHash ?? self::DUMMY_HASH);
        if ($user === null || !$valid) {
            $this->attempts->prune($now->modify('-1 day'));
            $this->attempts->recordFailure($ip, $now);

            throw new InvalidCredentials();
        }

        if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
            $this->users->updatePassword($user->id, AuthPolicy::hash($password));
        }
        $this->attempts->clear($ip);
        $this->sessions->revoke($previousToken);

        return $this->sessions->issue($user->id);
    }
}
