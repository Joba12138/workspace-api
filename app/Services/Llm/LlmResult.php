<?php

namespace App\Services\Llm;

class LlmResult
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $model = null,
        public readonly array $raw = [],
    ) {}

    public function json(): ?array
    {
        $text = trim($this->content);
        if ($text === '') {
            return null;
        }

        // 容忍模型偶发包一层 ```json
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }
}
