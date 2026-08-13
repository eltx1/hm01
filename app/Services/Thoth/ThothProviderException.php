<?php

namespace App\Services\Thoth;

use RuntimeException;

final class ThothProviderException extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}
