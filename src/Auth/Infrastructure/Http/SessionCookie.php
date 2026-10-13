<?php

declare(strict_types=1);

namespace Deployer\Auth\Infrastructure\Http;

use Deployer\Auth\Application\AuthPolicy;
use Deployer\Shared\Infrastructure\Http\Request;

final class SessionCookie
{
    public const NAME = 'deployer_session';

    public static function issue(string $token, Request $request): string
    {
        return self::build($token, AuthPolicy::SESSION_ABSOLUTE_SECONDS, $request);
    }

    public static function clear(Request $request): string
    {
        return self::build('', 0, $request);
    }

    private static function build(string $value, int $maxAge, Request $request): string
    {
        $cookie = sprintf('%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax', self::NAME, rawurlencode($value), $maxAge);

        return $request->isSecure() ? $cookie . '; Secure' : $cookie;
    }
}
