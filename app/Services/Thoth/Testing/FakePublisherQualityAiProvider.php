<?php

namespace App\Services\Thoth\Testing;

use App\Services\Thoth\Contracts\AiQualityProvider;
use App\Services\Thoth\Data\PublisherQualityAiRequest;
use App\Services\Thoth\Data\PublisherQualityAiResult;
use App\Services\Thoth\ThothProviderException;

final class FakePublisherQualityAiProvider implements AiQualityProvider
{
    public function __construct(private readonly string $mode = 'success') {}

    public function provider(): string
    {
        return 'FAKE';
    }

    public function supportsModel(string $model): bool
    {
        return $model === 'fake-quality-model';
    }

    public function analyze(PublisherQualityAiRequest $request, string $model, string $credential, int $timeout, int $maxOutputTokens): PublisherQualityAiResult
    {
        if ($this->mode !== 'success') {
            throw new ThothProviderException(match ($this->mode) {
                'timeout' => 'TIMED_OUT', 'malformed' => 'INVALID_RESPONSE', default => 'PROVIDER_UNAVAILABLE'
            });
        }

        return new PublisherQualityAiResult(['recommended_decision' => 'REVIEW_REQUIRED', 'risk_level' => 'MEDIUM', 'confidence' => 75, 'categories' => ['CONTENT_QUALITY'], 'findings' => [], 'positive_signals' => [], 'concerns' => ['Synthetic test result.'], 'recommended_admin_checks' => ['Review manually.'], 'summary' => 'Deterministic fake advisory.', 'limitations' => ['Test only.']], 'FAKE', $model, 'fake-request', [], now()->toIso8601String());
    }
}
