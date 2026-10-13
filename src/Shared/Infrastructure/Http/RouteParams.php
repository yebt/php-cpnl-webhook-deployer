<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Http;

final class RouteParams
{
    /**
     * Positive integer `{id}` route parameter; anything else is a 404.
     *
     * @param array<string,string> $params
     */
    public static function id(array $params, string $name = 'id'): int
    {
        $value = $params[$name] ?? '';
        if (!preg_match('/^[1-9]\d{0,17}$/', $value)) {
            throw ApiError::notFound();
        }

        return (int) $value;
    }
}
