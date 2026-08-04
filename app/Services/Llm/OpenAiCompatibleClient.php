<?php

namespace App\Services\Llm;

use App\Contracts\LlmClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiCompatibleClient implements LlmClient
{
    public function chat(array $messages, array $options = []): LlmResult
    {
        $base = rtrim((string) config('services.llm.base_url'), '/');
        $apiKey = (string) config('services.llm.api_key');
        $model = (string) ($options['model'] ?? config('services.llm.model'));
        $timeout = (int) config('services.llm.timeout', 60);

        if ($base === '' || $apiKey === '') {
            throw new RuntimeException('LLM 未配置：请设置 LLM_BASE_URL 与 LLM_API_KEY');
        }
        if ($model === '') {
            throw new RuntimeException('LLM 未配置：请设置 LLM_MODEL');
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
        ];

        if (array_key_exists('temperature', $options)) {
            $body['temperature'] = $options['temperature'];
        }
        if (! empty($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }
        if (! empty($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        $res = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->post($base.'/chat/completions', $body);

        if (! $res->successful()) {
            throw new RuntimeException(
                'LLM 调用失败: HTTP '.$res->status().' '.mb_substr($res->body(), 0, 300)
            );
        }

        $json = $res->json() ?: [];
        $content = (string) data_get($json, 'choices.0.message.content', '');

        if ($content === '') {
            throw new RuntimeException('LLM 返回空内容');
        }

        return new LlmResult(
            content: $content,
            model: (string) ($json['model'] ?? $model),
            raw: $json,
        );
    }
}
