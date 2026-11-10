<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Domain/API error with a stable machine-readable code.
 * Example: throw ApiException::conflict('invalid_transition', 'Order cannot move to printing.');
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>|null  $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self(403, $code, $message);
    }

    public static function unprocessable(string $code, string $message): self
    {
        return new self(422, $code, $message);
    }
}
