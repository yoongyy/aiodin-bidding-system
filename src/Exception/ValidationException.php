<?php

declare(strict_types=1);

namespace App\Exception;

use InvalidArgumentException;

final class ValidationException extends InvalidArgumentException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private array $errors)
    {
        parent::__construct('Validation failed.');
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
