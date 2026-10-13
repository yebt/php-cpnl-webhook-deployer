<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Http;

/**
 * Serves the built single-page app (frontend/dist) from disk or from inside the phar.
 *
 * - Files under /assets/ carry a content hash in their name (Vite), so they are cached
 *   for a year as immutable; everything else is revalidated with an ETag.
 * - Any other GET/HEAD without a file extension gets index.html (history-mode deep links),
 *   with its <base href> rewritten to the request base path so asset and API URLs resolve
 *   both behind a rewrite and through PATH_INFO URLs.
 * - /api and /hooks are never handled here.
 *
 * Paths are validated lexically (realpath does not work on phar:// paths): every segment
 * must be a plain, non-hidden file name, which rules out traversal and dotfiles.
 */
final class StaticSite
{
    private const MIME_TYPES = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'txt' => 'text/plain; charset=utf-8',
    ];

    private const IMMUTABLE = 'public, max-age=31536000, immutable';

    /**
     * The app needs inline styles (CodeMirror injects <style> elements) but no inline or
     * remote scripts.
     */
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
        . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function __construct(private readonly string $root)
    {
    }

    public static function handles(Request $request): bool
    {
        $path = $request->path;

        return in_array($request->method, ['GET', 'HEAD'], true)
            && $path !== '/api' && !str_starts_with($path, '/api/')
            && $path !== '/hooks' && !str_starts_with($path, '/hooks/');
    }

    public function respond(Request $request): Response
    {
        $relative = ltrim($request->path, '/');
        $file = $relative === '' ? null : $this->resolve($relative);

        if ($file !== null) {
            $cache = str_starts_with($relative, 'assets/') ? self::IMMUTABLE : 'no-cache';

            return $this->file($request, (string) file_get_contents($file), self::mimeType($file), $cache);
        }

        $last = basename($relative);
        if ($relative !== '' && str_contains($last, '.')) {
            return self::notFound('Not found.');
        }

        $index = $this->root . '/index.html';
        if (!is_file($index)) {
            return self::notFound('The web interface is not built. Run build/build.sh (or `npm run build` in frontend/).');
        }
        $html = (string) file_get_contents($index);
        $base = htmlspecialchars(rtrim($request->basePath, '/') . '/', ENT_QUOTES);
        $html = (string) preg_replace('#<base\s+href="[^"]*"\s*/?>#i', '<base href="' . $base . '" />', $html, 1);

        return $this->file($request, $html, self::MIME_TYPES['html'], 'no-cache')
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin');
    }

    private function resolve(string $relative): ?string
    {
        foreach (explode('/', $relative) as $segment) {
            if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/', $segment)) {
                return null;
            }
        }
        $file = $this->root . '/' . $relative;

        return is_file($file) ? $file : null;
    }

    private function file(Request $request, string $body, string $type, string $cache): Response
    {
        $etag = '"' . hash('sha256', $body) . '"';
        $headers = ['Content-Type' => $type, 'Cache-Control' => $cache, 'ETag' => $etag, 'X-Content-Type-Options' => 'nosniff'];
        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('if-none-match'))), true)) {
            return new Response(304, '', $headers);
        }

        return new Response(200, $request->method === 'HEAD' ? '' : $body, $headers);
    }

    private static function mimeType(string $file): string
    {
        return self::MIME_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    private static function notFound(string $message): Response
    {
        return new Response(404, $message . "\n", [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
