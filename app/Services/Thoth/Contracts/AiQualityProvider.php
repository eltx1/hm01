<?php

namespace App\Services\Thoth\Contracts;

use App\Services\Thoth\Data\PublisherQualityAiRequest;
use App\Services\Thoth\Data\PublisherQualityAiResult;

interface AiQualityProvider
{
    public function provider(): string;

    public function supportsModel(string $model): bool;

    public function analyze(PublisherQualityAiRequest $request, string $model, string $credential, int $timeout, int $maxOutputTokens): PublisherQualityAiResult;
}
