<?php

namespace App\Exceptions;

class ShiftException extends DomainException
{
    public function __construct(string $message, string $code = 'SHIFT_ERROR', int $status = 409, array $fields = [])
    {
        parent::__construct($message, $code, $status, $fields);
    }
}
