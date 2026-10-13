<?php

declare(strict_types=1);

namespace Deployer\Auth\Application;

final class AuthPolicy
{
    public const PASSWORD_MIN_LENGTH = 10;
    /** bcrypt (PASSWORD_DEFAULT) only uses the first 72 bytes; longer input is still accepted. */
    public const PASSWORD_MAX_LENGTH = 1024;
    public const USERNAME_PATTERN = '/^[A-Za-z0-9._@-]{1,64}$/';

    public const SESSION_IDLE_SECONDS = 12 * 3600;
    public const SESSION_ABSOLUTE_SECONDS = 7 * 86400;
    /** last_seen_at is refreshed at most this often, so log polling does not write on every request. */
    public const SESSION_TOUCH_SECONDS = 60;

    public const THROTTLE_MAX_FAILURES = 5;
    public const THROTTLE_WINDOW_SECONDS = 15 * 60;

    /** @return array<string,string> field => message */
    public static function validateCredentials(string $username, string $password): array
    {
        $errors = [];
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            $errors['username'] = 'Use 1-64 characters: letters, digits, dot, underscore, @ or dash.';
        }
        $length = mb_strlen($password, 'UTF-8');
        if ($length < self::PASSWORD_MIN_LENGTH) {
            $errors['password'] = sprintf('Use at least %d characters.', self::PASSWORD_MIN_LENGTH);
        } elseif ($length > self::PASSWORD_MAX_LENGTH) {
            $errors['password'] = sprintf('Use at most %d characters.', self::PASSWORD_MAX_LENGTH);
        }

        return $errors;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
