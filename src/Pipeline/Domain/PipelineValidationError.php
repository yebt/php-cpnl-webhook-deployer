<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Domain;

use DomainException;

final class PipelineValidationError extends DomainException
{
    /** @param list<string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct("Invalid pipeline:\n - " . implode("\n - ", $errors));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
