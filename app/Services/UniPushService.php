<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * UniPush 下发
 * - driver=getui：个推 RestAPI V2（开发者中心取 AppID/AppKey/MasterSecret）
 * - driver=unicloud：调用已 URL 化的云函数（UniPush 2.0 官方推荐）
 */
class UniPushService
{
    public function enabled(): bool
    {
        $driver = config('services.unipush.driver', 'getui');

        if ($driver === 'unicloud') {
            return (bool) config('services.unipush.cloud_url');
        }

        return (bool) (
            config('services.unipush.app_id')
            && config('services.unipush.app_key')
            && config('services.unipush.master_secret')
        );
    }

    /**
     * @param  list<string>  $clientIds
     * @param  array<string, mixed>  $payload
     */
    public function sendToClients(array $clientIds, string $title, string $content, array $payload = []): bool
    {
        $clientIds = array_values(array_unique(array_filter(array_map('strval', $clientIds))));
        if (! $clientIds || ! $this->enabled()) {
            return false;
        }

        $driver = config('services.unipush.driver', 'getui');

        try {
            return $driver === 'unicloud'
                ? $this->sendViaUniCloud($clientIds, $title, $content, $payload)
                : $this->sendViaGetui($clientIds, $title, $content, $payload);
        } catch (\Throwable $e) {
            Log::warning('unipush.send_failed', [
                'driver' => $driver,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  list<string>  $clientIds
     * @param  array<string, mixed>  $payload
     */
    protected function sendViaUniCloud(array $clientIds, string $title, string $content, array $payload): bool
    {
        $url = rtrim((string) config('services.unipush.cloud_url'), '/');
        $secret = (string) config('services.unipush.cloud_secret', '');

        $res = Http::timeout(15)
            ->acceptJson()
            ->asJson()
            ->withHeaders($secret ? ['X-Push-Secret' => $secret] : [])
            ->post($url, [
                'push_clientid' => count($clientIds) === 1 ? $clientIds[0] : $clientIds,
                'title' => $title,
                'content' => $content,
                'payload' => $payload,
                'force_notification' => true,
                'request_id' => (string) Str::uuid(),
            ]);

        if (! $res->successful()) {
            Log::warning('unipush.unicloud_http_error', [
                'status' => $res->status(),
                'body' => $res->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $clientIds
     * @param  array<string, mixed>  $payload
     */
    protected function sendViaGetui(array $clientIds, string $title, string $content, array $payload): bool
    {
        $okAny = false;
        foreach ($clientIds as $cid) {
            if ($this->getuiPushOne($cid, $title, $content, $payload)) {
                $okAny = true;
            }
        }

        return $okAny;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function getuiPushOne(string $cid, string $title, string $content, array $payload): bool
    {
        $appId = (string) config('services.unipush.app_id');
        $base = "https://restapi.getui.com/v2/{$appId}";
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';

        $body = [
            'request_id' => str_replace('-', '', (string) Str::uuid()),
            'audience' => [
                'cid' => [$cid],
            ],
            'push_message' => [
                'notification' => [
                    'title' => $title,
                    'body' => $content,
                    'click_type' => 'payload',
                    'payload' => $payloadJson,
                ],
            ],
            'push_channel' => [
                'ios' => [
                    'type' => 'notify',
                    'payload' => $payloadJson,
                    'aps' => [
                        'alert' => [
                            'title' => $title,
                            'body' => $content,
                        ],
                        'content-available' => 0,
                    ],
                    'auto_badge' => '+1',
                ],
            ],
        ];

        $send = function (string $token) use ($base, $body) {
            return Http::timeout(15)
                ->withHeaders(['token' => $token])
                ->acceptJson()
                ->asJson()
                ->post("{$base}/push/single/cid", $body);
        };

        $token = $this->getuiToken();
        $res = $send($token);
        $json = $res->json() ?? [];

        if ((int) ($json['code'] ?? 0) === 10001) {
            Cache::forget('getui_auth_token');
            $res = $send($this->getuiToken());
            $json = $res->json() ?? [];
        }

        if (! $res->successful() || (int) ($json['code'] ?? 1) !== 0) {
            Log::warning('unipush.getui_error', [
                'cid' => $cid,
                'status' => $res->status(),
                'body' => $json ?: $res->body(),
            ]);

            return false;
        }

        return true;
    }

    protected function getuiToken(): string
    {
        return Cache::remember('getui_auth_token', 60 * 60 * 20, function () {
            $appId = (string) config('services.unipush.app_id');
            $appKey = (string) config('services.unipush.app_key');
            $master = (string) config('services.unipush.master_secret');
            $timestamp = (string) (int) round(microtime(true) * 1000);
            $sign = hash('sha256', $appKey.$timestamp.$master);

            $res = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post("https://restapi.getui.com/v2/{$appId}/auth", [
                    'sign' => $sign,
                    'timestamp' => $timestamp,
                    'appkey' => $appKey,
                ]);

            $json = $res->json() ?? [];
            $token = (string) ($json['data']['token'] ?? '');
            if (! $res->successful() || (int) ($json['code'] ?? 1) !== 0 || $token === '') {
                throw new \RuntimeException('Getui auth failed: '.($res->body() ?: 'unknown'));
            }

            return $token;
        });
    }
}
