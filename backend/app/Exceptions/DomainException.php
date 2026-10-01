<?php

namespace App\Exceptions;

use Exception;

class DomainException extends Exception
{
    protected string $errorCode;
    protected int $statusCode;
    protected array $fields;

    public function __construct(
        string $message,
        string $errorCode = 'DOMAIN_ERROR',
        int $statusCode = 400,
        array $fields = []
    ) {
        parent::__construct($message, $statusCode);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->fields = $fields;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getFields(): array
    {
        return $this->fields;
    }
}
