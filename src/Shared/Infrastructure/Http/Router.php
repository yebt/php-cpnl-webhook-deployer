<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Http;

use Closure;

/**
 * Tiny method + path router. Path segments like `{project}` become named params.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: Closure}> */
    private array $routes = [];

    /** @param Closure(Request, array<string,string>): Response $handler */
    public function add(string $method, string $pattern, Closure $handler): self
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = ['method' => strtoupper($method), 'regex' => '#^' . $regex . '$#', 'handler' => $handler];

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return ($route['handler'])($request, array_map('rawurldecode', $params));
        }

        if ($allowed !== []) {
            $allow = implode(', ', array_unique($allowed));

            return (new ApiError(405, 'method_not_allowed', 'Method not allowed.', headers: ['Allow' => $allow]))->toResponse();
        }

        return ApiError::notFound()->toResponse();
    }
}
