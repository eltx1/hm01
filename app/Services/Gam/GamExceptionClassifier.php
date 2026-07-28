<?php

namespace App\Services\Gam;

use App\Enums\GamErrorCategory;
use App\Services\Gam\Exceptions\GamTransportException;
use Throwable;

final class GamExceptionClassifier
{
    /** @return array{category: GamErrorCategory, code: ?string, retryable: bool, safe_to_retry: bool} */
    public function classify(Throwable $exception): array
    {
        $message = strtolower($exception->getMessage());
        $code = $exception instanceof GamTransportException ? $exception->upstreamCode : null;

        $category = match (true) {
            str_contains($message, 'oauth'), str_contains($message, 'authentication'), str_contains($message, 'credential') => GamErrorCategory::Authentication,
            str_contains($message, 'permission'), str_contains($message, 'not authorized'), str_contains($message, 'access denied') => GamErrorCategory::Permission,
            str_contains($message, 'quota') => GamErrorCategory::Quota,
            str_contains($message, 'rate') => GamErrorCategory::RateLimit,
            str_contains($message, 'validation'), str_contains($message, 'invalid') => GamErrorCategory::Validation,
            str_contains($message, 'timeout'), str_contains($message, 'network'), str_contains($message, 'transport') => GamErrorCategory::Network,
            str_contains($message, 'soap extension'), str_contains($message, 'disabled'), str_contains($message, 'configuration') => GamErrorCategory::Configuration,
            str_contains($message, 'server'), str_contains($message, 'unavailable') => GamErrorCategory::Upstream,
            default => GamErrorCategory::Unknown,
        };

        return [
            'category' => $category,
            'code' => $code,
            'retryable' => $exception instanceof GamTransportException ? $exception->retryable : false,
            'safe_to_retry' => $exception instanceof GamTransportException ? $exception->safeToRetry : false,
        ];
    }
}
