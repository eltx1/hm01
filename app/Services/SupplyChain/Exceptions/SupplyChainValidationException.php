<?php

namespace App\Services\SupplyChain\Exceptions;

use RuntimeException;

final class SupplyChainValidationException extends RuntimeException
{
    /** @param array<int, string> $errors */
    public function __construct(
        public readonly string $category,
        public readonly array $errors,
    ) {
        parent::__construct($category.': '.implode(' ', $errors));
    }
}
