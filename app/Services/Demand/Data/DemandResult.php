<?php

namespace App\Services\Demand\Data;

final readonly class DemandResult
{
    public function __construct(
        public bool $success,
        public bool $dryRun = false,
        public array $data = [],
        public ?string $errorCategory = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $retryable = false,
    ) {
    }

    public static function success(array $data = []): self
    {
        return new self(true, false, $data);
    }

    public static function dryRun(array $data = []): self
    {
        return new self(true, true, $data);
    }

    public static function failure(
        string $category,
        ?string $code,
        string $message,
        bool $retryable = false,
    ): self {
        return new self(false, false, [], $category, $code, $message, $retryable);
    }
}
