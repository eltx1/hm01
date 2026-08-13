<?php

namespace App\Services\Thoth\Data;

final readonly class PublisherQualityAiResult
{
    public function __construct(public array $result, public string $provider, public string $model, public ?string $requestId = null, public array $usage = [], public ?string $completedAt = null) {}
}
