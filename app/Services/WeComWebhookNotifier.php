<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 企业微信群机器人 Webhook 告警（无需 easywechat）。
 */
class WeComWebhookNotifier
{
    public function enabled(): bool
    {
        return (bool) config('services.wecom.enabled')
            && filled(config('services.wecom.webhook_url'));
    }

    public function reportException(Throwable $e): void
    {
        if (! $this->enabled() || ! $this->shouldReport($e)) {
            return;
        }

        $fingerprint = md5($e::class.'|'.$e->getMessage().'|'.$e->getFile().'|'.$e->getLine());
        $throttleSeconds = (int) config('services.wecom.throttle_seconds', 60);
        if ($throttleSeconds > 0 && ! Cache::add('wecom:ex:'.$fingerprint, 1, $throttleSeconds)) {
            return;
        }

        $this->sendMarkdown($this->formatException($e));
    }

    public function sendText(string $content): bool
    {
        return $this->post([
            'msgtype' => 'text',
            'text' => [
                'content' => mb_substr($content, 0, 2000),
            ],
        ]);
    }

    public function sendMarkdown(string $content): bool
    {
        return $this->post([
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => mb_substr($content, 0, 4000),
            ],
        ]);
    }

    public function shouldReport(Throwable $e): bool
    {
        foreach ((array) config('services.wecom.dont_report', []) as $class) {
            if (is_string($class) && $e instanceof $class) {
                return false;
            }
        }

        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return $e->getStatusCode() >= 500;
        }

        return true;
    }

    private function formatException(Throwable $e): string
    {
        $app = config('app.name', 'workspace-api');
        $env = config('app.env');
        $method = request()?->method() ?: '-';
        $url = request()?->fullUrl() ?: (PHP_SAPI === 'cli' ? 'cli' : '-');
        $userId = auth()->id() ?: '-';
        $class = $e::class;
        $file = $e->getFile();
        $line = $e->getLine();
        $message = str_replace(["\n", '`'], [' ', "'"], mb_substr($e->getMessage(), 0, 500));
        $trace = collect(explode("\n", $e->getTraceAsString()))
            ->take(8)
            ->implode("\n");

        return implode("\n", [
            "**[{$app}] 服务端异常**",
            ">环境: <font color=\"warning\">{$env}</font>",
            '>时间: '.now()->toDateTimeString(),
            ">请求: `{$method} {$url}`",
            ">用户: {$userId}",
            ">类型: `{$class}`",
            ">信息: <font color=\"comment\">{$message}</font>",
            ">位置: `{$file}:{$line}`",
            "```\n{$trace}\n```",
        ]);
    }

    private function post(array $payload): bool
    {
        $url = (string) config('services.wecom.webhook_url');
        if ($url === '') {
            return false;
        }

        try {
            $res = Http::timeout(5)->acceptJson()->post($url, $payload);
            if (! $res->successful() || (int) $res->json('errcode') !== 0) {
                Log::warning('WeCom webhook failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('WeCom webhook exception: '.$e->getMessage());

            return false;
        }
    }
}
