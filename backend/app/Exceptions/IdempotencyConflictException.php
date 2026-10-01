<?php

namespace App\Exceptions;

class IdempotencyConflictException extends DomainException
{
    public function __construct(string $message, string $code = 'IDEMPOTENCY_CONFLICT', array $fields = [])
    {
        parent::__construct($message, $code, 409, $fields);
    }
}
