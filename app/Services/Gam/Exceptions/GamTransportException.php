<?php

namespace App\Services\Gam\Exceptions;

use RuntimeException;
use Throwable;

class GamTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $upstreamCode = null,
        public readonly bool $retryable = false,
        public readonly bool $safeToRetry = false,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
