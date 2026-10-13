<?php

declare(strict_types=1);

namespace Deployer\Shared\Domain;

use DomainException;

/** Input rejected by a use case; carries one message per offending field. */
final class ValidationFailed extends DomainException
{
    /** @param array<string,string|list<string>> $errors field => message(s) */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed: ' . json_encode($errors, JSON_UNESCAPED_SLASHES));
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => $message]);
    }
}
