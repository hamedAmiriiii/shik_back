<?php

namespace App\Services;

use RuntimeException;

class ProductLimitReachedException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $payload;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(string $message, array $payload = [])
    {
        parent::__construct($message);
        $this->payload = $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
