<?php

namespace App\Exceptions;

class QuoteExpiredException extends DomainException
{
    public function __construct(string $message = 'Kutipan checkout (quote) telah kedaluwarsa. Silakan lakukan kutipan ulang.', array $fields = [])
    {
        parent::__construct($message, 'QUOTE_EXPIRED', 409, $fields);
    }
}
