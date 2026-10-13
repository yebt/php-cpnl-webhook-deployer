<?php

declare(strict_types=1);

namespace Deployer\Auth\Application;

use Deployer\Auth\Domain\User;
use Deployer\Auth\Domain\UserRepository;
use Deployer\Shared\Domain\Clock;
use Deployer\Shared\Domain\ValidationFailed;

/**
 * Operator recovery (CLI): sets the administrator password and revokes every
 * session. Creates the administrator when none exists yet.
 */
final class SetPassword
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Sessions $sessions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{User, bool} the user and whether it was created
     * @throws ValidationFailed
     */
    public function __invoke(?string $username, string $password): array
    {
        $existing = $this->users->first();
        if ($existing !== null && $username !== null && $username !== $existing->username) {
            throw ValidationFailed::field('username', sprintf('The administrator is "%s".', $existing->username));
        }
        $errors = AuthPolicy::validateCredentials($existing->username ?? $username ?? 'admin', $password);
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        if ($existing === null) {
            $created = $this->users->createFirst($username ?? 'admin', AuthPolicy::hash($password), $this->clock->now());
            if ($created !== null) {
                return [$created, true];
            }
            $existing = $this->users->first() ?? throw new \RuntimeException('Administrator vanished during creation.');
        }

        $this->users->updatePassword($existing->id, AuthPolicy::hash($password));
        $this->sessions->revokeAllFor($existing->id);

        return [$existing, false];
    }
}
