<?php

declare(strict_types=1);

namespace Deployer\Auth\Application;

use DateTimeImmutable;
use Deployer\Auth\Domain\Session;
use Deployer\Auth\Domain\SessionRepository;
use Deployer\Shared\Domain\Clock;

/** Issues, resolves and revokes server-side sessions. */
final class Sessions
{
    private const TIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly SessionRepository $sessions, private readonly Clock $clock)
    {
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function issue(int $userId): IssuedSession
    {
        $now = $this->clock->now();
        $this->sessions->deleteExpired(...$this->expiryBounds($now));
        $token = bin2hex(random_bytes(32));
        $session = new Session(
            self::hashToken($token),
            $userId,
            bin2hex(random_bytes(32)),
            $now->format(self::TIME_FORMAT),
            $now->format(self::TIME_FORMAT),
        );
        $this->sessions->save($session);

        return new IssuedSession($token, $session);
    }

    /** Returns the live session for a cookie token, or null when missing/expired. */
    public function resolve(?string $token): ?Session
    {
        if ($token === null || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $session = $this->sessions->find(self::hashToken($token));
        if ($session === null) {
            return null;
        }

        $now = $this->clock->now();
        [$idleBefore, $createdBefore] = $this->expiryBounds($now);
        $lastSeen = new DateTimeImmutable($session->lastSeenAt, $now->getTimezone());
        if ($lastSeen < $idleBefore || new DateTimeImmutable($session->createdAt, $now->getTimezone()) < $createdBefore) {
            $this->sessions->delete($session->tokenHash);

            return null;
        }
        if ($now->getTimestamp() - $lastSeen->getTimestamp() >= AuthPolicy::SESSION_TOUCH_SECONDS) {
            $this->sessions->touch($session->tokenHash, $now);
        }

        return $session;
    }

    public function revoke(?string $token): void
    {
        if ($token !== null && $token !== '') {
            $this->sessions->delete(self::hashToken($token));
        }
    }

    public function revokeAllFor(int $userId): void
    {
        $this->sessions->deleteForUser($userId);
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    private function expiryBounds(DateTimeImmutable $now): array
    {
        return [
            $now->modify(sprintf('-%d seconds', AuthPolicy::SESSION_IDLE_SECONDS)),
            $now->modify(sprintf('-%d seconds', AuthPolicy::SESSION_ABSOLUTE_SECONDS)),
        ];
    }
}
