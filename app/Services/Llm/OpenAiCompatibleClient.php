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
            throw new RuntimeException('AI 服务尚未配置完成');
        }
        if ($model === '') {
            throw new RuntimeException('AI 服务尚未配置完成');
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
            $status = $res->status();
            $body = (string) $res->body();
            report(new RuntimeException('LLM HTTP '.$status.': '.mb_substr($body, 0, 500)));

            if ($status === 401 || $status === 403) {
                if (str_contains($body, 'quota') || str_contains($body, 'FreeTier') || str_contains($body, 'exhausted')) {
                    throw new RuntimeException('AI 额度暂时用完了，请稍后再试');
                }

                throw new RuntimeException('AI 服务暂时不可用，请稍后再试');
            }
            if ($status === 429) {
                throw new RuntimeException('请求有点频繁，请稍后再试');
            }

            throw new RuntimeException('AI 暂时开小差了，请稍后再试');
        }

        $json = $res->json() ?: [];
        $content = (string) data_get($json, 'choices.0.message.content', '');

        if ($content === '') {
            throw new RuntimeException('AI 没给出有效回复，请再说一遍');
        }

        return new LlmResult(
            content: $content,
            model: (string) ($json['model'] ?? $model),
            raw: $json,
        );
    }
}
