<?php

declare(strict_types=1);

namespace Deployer\Auth\Application;

use Deployer\Auth\Domain\User;
use Deployer\Auth\Domain\UserRepository;
use Deployer\Shared\Domain\Clock;
use Deployer\Shared\Domain\ValidationFailed;

/** First-run bootstrap: creates the single administrator while no user exists. */
final class SetupAdmin
{
    public function __construct(private readonly UserRepository $users, private readonly Clock $clock)
    {
    }

    /**
     * @throws AlreadySetUp
     * @throws ValidationFailed
     */
    public function __invoke(string $username, string $password): User
    {
        if ($this->users->exists()) {
            throw new AlreadySetUp();
        }
        $errors = AuthPolicy::validateCredentials($username, $password);
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $this->users->createFirst($username, AuthPolicy::hash($password), $this->clock->now())
            ?? throw new AlreadySetUp();
    }
}
