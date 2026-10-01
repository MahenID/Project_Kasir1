<?php

namespace App\Exceptions;

class InsufficientStockException extends DomainException
{
    public function __construct(string $message, array $fields = [])
    {
        parent::__construct($message, 'INSUFFICIENT_STOCK', 409, $fields);
    }
}
