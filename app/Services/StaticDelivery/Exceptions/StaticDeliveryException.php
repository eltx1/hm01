<?php

namespace App\Services\StaticDelivery\Exceptions;

use RuntimeException;

class StaticDeliveryException extends RuntimeException
{
    public function __construct(public readonly string $category, string $message)
    {
        parent::__construct($message);
    }
}
