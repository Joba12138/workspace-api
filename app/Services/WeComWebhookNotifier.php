<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 企业微信群机器人 Webhook 告警。
 * 强节流：全局限流 + 同类去重，避免一页多接口同时 500 刷屏。
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

        // 只按「异常类 + 信息」去重（不含 file/line，避免同类问题刷多条）
        $fingerprint = md5($e::class.'|'.mb_substr($e->getMessage(), 0, 300));
        $perErrorSeconds = max(1, (int) config('services.wecom.throttle_seconds', 300));
        $globalSeconds = max(1, (int) config('services.wecom.global_throttle_seconds', 60));

        try {
            if (! Cache::add('wecom:ex:'.$fingerprint, 1, $perErrorSeconds)) {
                Cache::increment('wecom:suppressed');

                return;
            }

            // 全局闸门：任意异常 60s 内最多推 1 条
            if (! Cache::add('wecom:global_gate', 1, $globalSeconds)) {
                Cache::increment('wecom:suppressed');

                return;
            }
        } catch (Throwable $cacheError) {
            // 缓存不可用时也绝不裸奔刷屏：退回文件锁
            if (! $this->acquireFileLock($fingerprint, $perErrorSeconds)) {
                return;
            }
        }

        $suppressed = 0;
        try {
            $suppressed = (int) Cache::pull('wecom:suppressed', 0);
        } catch (Throwable) {
            //
        }

        $this->sendMarkdown($this->formatException($e, $suppressed));
    }

    /**
     * 业务/上游告警（非 5xx），如 LLM 额度用尽。带同类节流。
     *
     * @param  array<string, scalar|null>  $context
     */
    public function reportAlert(string $title, string $detail, array $context = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $fingerprint = md5('alert|'.$title.'|'.mb_substr($detail, 0, 300));
        $perErrorSeconds = max(1, (int) config('services.wecom.throttle_seconds', 300));
        $globalSeconds = max(1, (int) config('services.wecom.global_throttle_seconds', 60));

        try {
            if (! Cache::add('wecom:alert:'.$fingerprint, 1, $perErrorSeconds)) {
                Cache::increment('wecom:suppressed');

                return;
            }
            if (! Cache::add('wecom:global_gate', 1, $globalSeconds)) {
                Cache::increment('wecom:suppressed');

                return;
            }
        } catch (Throwable) {
            if (! $this->acquireFileLock('a:'.$fingerprint, $perErrorSeconds)) {
                return;
            }
        }

        $suppressed = 0;
        try {
            $suppressed = (int) Cache::pull('wecom:suppressed', 0);
        } catch (Throwable) {
            //
        }

        $app = config('app.name', 'workspace-api');
        $env = config('app.env');
        $method = request()?->method() ?: '-';
        $url = request()?->fullUrl() ?: (PHP_SAPI === 'cli' ? 'cli' : '-');
        $userId = auth()->id() ?: '-';
        $safeDetail = str_replace(["\n", '`'], [' ', "'"], mb_substr($detail, 0, 500));

        $lines = [
            "**[{$app}] {$title}**",
            ">环境: <font color=\"warning\">{$env}</font>",
            '>时间: '.now()->toDateTimeString(),
            ">请求: `{$method} {$url}`",
            ">用户: {$userId}",
            ">说明: <font color=\"comment\">{$safeDetail}</font>",
        ];

        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $k = str_replace(["\n", '`'], [' ', "'"], (string) $key);
            $v = str_replace(["\n", '`'], [' ', "'"], mb_substr((string) $value, 0, 200));
            $lines[] = ">{$k}: `{$v}`";
        }

        if ($suppressed > 0) {
            $lines[] = ">节流: 期间已抑制 <font color=\"warning\">{$suppressed}</font> 条同类/并发告警";
        }

        $this->sendMarkdown(implode("\n", $lines));
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

    private function formatException(Throwable $e, int $suppressed = 0): string
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
            ->take(6)
            ->implode("\n");

        $lines = [
            "**[{$app}] 服务端异常**",
            ">环境: <font color=\"warning\">{$env}</font>",
            '>时间: '.now()->toDateTimeString(),
            ">请求: `{$method} {$url}`",
            ">用户: {$userId}",
            ">类型: `{$class}`",
            ">信息: <font color=\"comment\">{$message}</font>",
            ">位置: `{$file}:{$line}`",
        ];

        if ($suppressed > 0) {
            $lines[] = ">节流: 期间已抑制 <font color=\"warning\">{$suppressed}</font> 条同类/并发告警";
        }

        $lines[] = "```\n{$trace}\n```";

        return implode("\n", $lines);
    }

    private function acquireFileLock(string $fingerprint, int $seconds): bool
    {
        $dir = storage_path('framework/wecom-throttle');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir.'/'.$fingerprint.'.lock';
        if (is_file($path) && (time() - (int) @filemtime($path)) < $seconds) {
            return false;
        }
        @file_put_contents($path, (string) time());

        return true;
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
