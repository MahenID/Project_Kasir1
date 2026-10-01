<?php

namespace App\Exceptions;

class DiscountLimitException extends DomainException
{
    public function __construct(string $message, array $fields = [])
    {
        parent::__construct($message, 'DISCOUNT_LIMIT_EXCEEDED', 422, $fields);
    }
}
