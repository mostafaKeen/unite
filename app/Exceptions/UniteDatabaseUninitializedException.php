<?php

namespace App\Exceptions;

use Exception;

class UniteDatabaseUninitializedException extends Exception
{
    protected ?array $payload;

    public function __construct(string $message = "Unite EMR Database ConnectionString is uninitialized on vendor server", int $code = 400, ?\Throwable $previous = null, ?array $payload = null)
    {
        parent::__construct($message, $code, $previous);
        $this->payload = $payload;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }
}
