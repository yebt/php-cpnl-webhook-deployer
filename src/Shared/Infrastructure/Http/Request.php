<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Http;

use JsonException;

final class Request
{
    /** Hard cap for any request body read from the SAPI. Routes apply smaller limits. */
    public const MAX_BODY_BYTES = 1048576;
    /** Default limit for JSON API bodies. */
    public const DEFAULT_JSON_BYTES = 65536;

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers lower-cased header names
     * @param array<string,string> $cookies
     * @param array<string,mixed> $attributes values attached by middleware (e.g. the session)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $cookies = [],
        public readonly string $remoteAddr = '',
        public readonly bool $https = false,
        public readonly bool $bodyTooLarge = false,
        private readonly array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        // Never buffer more than the hard cap: read one extra byte to detect overflow.
        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $body = '';
        $tooLarge = $declared > self::MAX_BODY_BYTES;
        if (!$tooLarge) {
            $stream = fopen('php://input', 'rb');
            if ($stream !== false) {
                $body = (string) stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
                fclose($stream);
            }
            $tooLarge = strlen($body) > self::MAX_BODY_BYTES;
        }
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on' || (string) ($_SERVER['HTTPS'] ?? '') === '1';

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: is_string($path) && $path !== '' ? $path : '/',
            query: array_filter($_GET, 'is_string'),
            headers: $headers,
            body: $tooLarge ? '' : $body,
            cookies: array_filter($_COOKIE, 'is_string'),
            remoteAddr: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            https: $https,
            bodyTooLarge: $tooLarge,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * True on HTTPS, including TLS terminated by a proxy that sets X-Forwarded-Proto.
     * Only used to decide the cookie Secure flag, so a spoofed header cannot weaken anything.
     */
    public function isSecure(): bool
    {
        $forwarded = strtolower(trim(explode(',', (string) $this->header('x-forwarded-proto'))[0]));

        return $this->https || $forwarded === 'https';
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->headers,
            $this->body,
            $this->cookies,
            $this->remoteAddr,
            $this->https,
            $this->bodyTooLarge,
            [...$this->attributes, $name => $value],
        );
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function queryValue(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * Decodes a JSON object body. An empty body is an empty object.
     *
     * @return array<string,mixed>
     * @throws ApiError
     */
    public function json(int $maxBytes = self::DEFAULT_JSON_BYTES): array
    {
        if ($this->bodyTooLarge || strlen($this->body) > $maxBytes) {
            throw new ApiError(413, 'payload_too_large', sprintf('Request body exceeds %d bytes.', $maxBytes));
        }
        if (trim($this->body) === '') {
            return [];
        }
        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiError(400, 'invalid_json', 'Request body is not valid JSON.');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ApiError(400, 'invalid_json', 'Request body must be a JSON object.');
        }

        return $data;
    }
}
