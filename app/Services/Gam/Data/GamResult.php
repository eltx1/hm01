<?php

namespace App\Services\Gam\Data;

final readonly class GamResult
{
    public function __construct(
        public bool $success,
        public bool $dryRun = false,
        public bool $duplicate = false,
        public array $data = [],
        public ?string $operationId = null,
        public ?string $errorCategory = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
    }

    public static function success(array $data = [], ?string $operationId = null): self
    {
        return new self(true, false, false, $data, $operationId);
    }

    public static function dryRun(array $data = [], ?string $operationId = null): self
    {
        return new self(true, true, false, $data, $operationId);
    }

    public static function duplicate(array $data = [], ?string $operationId = null): self
    {
        return new self(true, false, true, $data, $operationId);
    }

    public static function failure(string $category, ?string $code, string $message, ?string $operationId = null): self
    {
        return new self(false, false, false, [], $operationId, $category, $code, $message);
    }
}
