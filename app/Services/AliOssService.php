<?php

namespace App\Services;

use App\Support\AliOssTicket;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AliOssService
{
    protected string $store = 'default';

    public function isConfigured(?string $store = 'default'): bool
    {
        $store = $store ?: 'default';

        return (bool) config("alioss.stores.{$store}.access_id")
            && (bool) config("alioss.stores.{$store}.access_key")
            && (bool) config("alioss.stores.{$store}.bucket");
    }

    public function signTicket(string $store, string $module): AliOssTicket
    {
        if (! $this->isConfigured($store)) {
            throw new RuntimeException('OSS 未配置，请填写 ALIOSS_* 环境变量');
        }

        if (! config("alioss.modules.{$module}")) {
            throw new RuntimeException("未知上传模块: {$module}");
        }

        $this->store = $store;

        $ticket = new AliOssTicket;
        $ticket->store = $store;
        $ticket->module = $module;
        $ticket->generateSessionId();

        $encodedCallback = base64_encode(json_encode(
            $this->callbackBody($ticket->sessionId),
            JSON_UNESCAPED_UNICODE
        ));
        $encodedPolicy = base64_encode(json_encode(
            $this->policy($module, $encodedCallback),
            JSON_UNESCAPED_UNICODE
        ));

        $ticket->accessId = (string) $this->cfg('access_id');
        $ticket->host = $this->host();
        $ticket->policy = $encodedPolicy;
        $ticket->signature = base64_encode(hash_hmac('sha1', $encodedPolicy, (string) $this->cfg('access_key'), true));
        $ticket->expiredAt = Carbon::now()->addSeconds((int) config("alioss.modules.{$module}.expire", 300));
        $ticket->expire = $ticket->expiredAt->getTimestamp();
        $ticket->callback = $encodedCallback;
        $ticket->dir = $this->dir($module);

        return $ticket;
    }

    public function verifyCallback(Request $request): void
    {
        $authorization = base64_decode((string) $request->header('Authorization', ''));
        $pubKeyUrl = base64_decode((string) $request->header('x-oss-pub-key-url', ''));

        if ($authorization === false || $authorization === '' || $pubKeyUrl === false || $pubKeyUrl === '') {
            throw new RuntimeException('OSS 回调签名头缺失');
        }

        $pubKey = Http::timeout(3)->get($pubKeyUrl)->body();
        if ($pubKey === '') {
            throw new RuntimeException('无法获取 OSS 公钥');
        }

        $body = $request->getContent();
        $path = $request->server('REQUEST_URI', '');
        $pos = strpos($path, '?');
        $authStr = $pos === false
            ? urldecode($path)."\n".$body
            : urldecode(substr($path, 0, $pos)).substr($path, $pos)."\n".$body;

        if (openssl_verify($authStr, $authorization, $pubKey, OPENSSL_ALGO_MD5) !== 1) {
            throw new RuntimeException('OSS 回调签名校验失败');
        }
    }

    public function diskName(string $store = 'default'): string
    {
        return config('alioss.disk_prefix', 'alioss_').$store;
    }

    protected function cfg(string $key): mixed
    {
        return config("alioss.stores.{$this->store}.{$key}");
    }

    protected function host(): string
    {
        $protocol = $this->cfg('ssl') ? 'https' : 'http';
        if ($this->cfg('is_domain') && $this->cfg('cdn_domain')) {
            return "{$protocol}://".$this->cfg('cdn_domain');
        }

        return "{$protocol}://".$this->cfg('bucket').'.'.$this->cfg('endpoint');
    }

    protected function dir(string $module): string
    {
        return 'workspace/'.$module.'/'.today()->format('Ym/d').'/';
    }

    protected function callbackBody(string $sessionId): array
    {
        return [
            'callbackBodyType' => 'application/json',
            'callbackUrl' => url('/api/v1/attachments/callback'),
            'callbackBody' => '{"bucket":${bucket},"filepath":${object},"filename":${x:filename},"md5":${etag},"mime_type":${mimeType},"size":${size},"image.width":"${imageInfo.width}","image.height":"${imageInfo.height}","image.ext":${imageInfo.format},"session_id":"'.$sessionId.'"}',
        ];
    }

    protected function policy(string $module, string $encodedCallback): array
    {
        $expire = Carbon::now()->addSeconds((int) config("alioss.modules.{$module}.expire", 300))->toIso8601String();
        $maxSize = (int) config("alioss.modules.{$module}.max_size", 10 * 1024 * 1024);
        $dir = $this->dir($module);

        return [
            'expiration' => $expire,
            'conditions' => [
                ['content-length-range', 1, $maxSize],
                ['starts-with', '$key', $dir],
                ['eq', '$success_action_status', '200'],
                ['eq', '$callback', $encodedCallback],
            ],
        ];
    }
}
