<?php

namespace App\Services\Llm;

use App\Contracts\LlmClient;
use App\Services\WeComWebhookNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            $raw = (string) $res->body();
            $snippet = mb_substr($raw, 0, 500);
            $isQuota = str_contains($raw, 'quota')
                || str_contains($raw, 'FreeTier')
                || str_contains($raw, 'exhausted')
                || str_contains($raw, 'AllocationQuota');
            $isExpectedClientError = $isQuota
                || $status === 401
                || $status === 403
                || $status === 429;

            Log::warning('LLM upstream rejected request', [
                'status' => $status,
                'model' => $model,
                'body' => $snippet,
            ]);

            // 额度/鉴权/限流：企微通知，但不走「服务端异常」
            if ($isExpectedClientError) {
                $title = $isQuota ? 'AI 额度告警' : ($status === 429 ? 'AI 限流告警' : 'AI 上游告警');
                $detail = $isQuota
                    ? '模型免费额度已用尽或仅免费额度模式被拒绝，请充值/关闭 FreeTierOnly，或更换 LLM_API_KEY'
                    : '上游返回 HTTP '.$status.'，请检查 API Key / 模型权限';

                try {
                    app(WeComWebhookNotifier::class)->reportAlert($title, $detail, [
                        '模型' => $model,
                        'HTTP' => $status,
                    ]);
                } catch (\Throwable) {
                    // 告警失败不影响主流程
                }
            } else {
                report(new RuntimeException('LLM HTTP '.$status.': '.$snippet));
            }

            if ($isQuota) {
                throw new RuntimeException('AI 额度暂时用完了，请稍后再试');
            }
            if ($status === 401 || $status === 403) {
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
