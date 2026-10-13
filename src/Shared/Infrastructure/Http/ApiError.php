<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Http;

use RuntimeException;

/**
 * An HTTP error rendered with the uniform API shape:
 * {"error": {"code": "...", "message": "...", "details": {...}}}.
 */
final class ApiError extends RuntimeException
{
    /**
     * @param array<string,mixed> $details
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    /** @param array<string,string|list<string>> $details field => message(s) */
    public static function validation(array $details, string $message = 'Validation failed.'): self
    {
        return new self(422, 'validation_failed', $message, $details);
    }

    public function toResponse(): Response
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->details !== []) {
            $error['details'] = $this->details;
        }
        $response = Response::json(['error' => $error], $this->status);
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
