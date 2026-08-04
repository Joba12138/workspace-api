<?php

namespace App\Services\Llm;

use App\Contracts\LlmClient;
use InvalidArgumentException;

class LlmManager
{
    public function driver(?string $name = null): LlmClient
    {
        $name = $name ?: (string) config('services.llm.driver', 'openai_compatible');

        return match ($name) {
            'openai_compatible' => app(OpenAiCompatibleClient::class),
            default => throw new InvalidArgumentException("未知 LLM driver: {$name}"),
        };
    }
}
