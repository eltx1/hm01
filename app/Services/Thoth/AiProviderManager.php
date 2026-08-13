<?php

namespace App\Services\Thoth;

use App\Services\Thoth\Contracts\AiQualityProvider;
use App\Services\Thoth\Providers\GeminiStructuredOutputProvider;
use App\Services\Thoth\Providers\OpenAiResponsesProvider;
use InvalidArgumentException;

final class AiProviderManager
{
    public function __construct(private readonly OpenAiResponsesProvider $openAi, private readonly GeminiStructuredOutputProvider $gemini) {}

    public function for(string $provider): AiQualityProvider
    {
        return match ($provider) {
            'OPENAI' => $this->openAi, 'GEMINI' => $this->gemini, default => throw new InvalidArgumentException('Unsupported THOTH provider.')
        };
    }
}
