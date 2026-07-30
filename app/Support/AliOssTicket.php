<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AliOssTicket
{
    public string $store = 'default';

    public string $module = '';

    public string $sessionId = '';

    public string $accessId = '';

    public string $host = '';

    public string $policy = '';

    public string $signature = '';

    public string $callback = '';

    public string $dir = '';

    public int $expire = 0;

    public ?Carbon $expiredAt = null;

    public string $guard = 'app';

    public ?int $userId = null;

    public ?string $workspaceId = null;

    public ?string $memberId = null;

    public function generateSessionId(): string
    {
        $this->sessionId = (string) Str::uuid();

        return $this->sessionId;
    }

    public function storeInCache(Carbon $expiresAt): void
    {
        Cache::put(self::cacheKey($this->sessionId), $this->toCache(), $expiresAt);
    }

    public static function fromSessionId(string $sessionId): ?self
    {
        $data = Cache::get(self::cacheKey($sessionId));
        if (! is_array($data)) {
            return null;
        }

        return self::fromCache($data);
    }

    public function destroy(): void
    {
        Cache::forget(self::cacheKey($this->sessionId));
    }

    public function toUploadPayload(): array
    {
        return [
            'session_id' => $this->sessionId,
            'access_id' => $this->accessId,
            'host' => $this->host,
            'policy' => $this->policy,
            'signature' => $this->signature,
            'expire' => $this->expire,
            'callback' => $this->callback,
            'dir' => $this->dir,
            'module' => $this->module,
        ];
    }

    protected function toCache(): array
    {
        return [
            'store' => $this->store,
            'module' => $this->module,
            'session_id' => $this->sessionId,
            'guard' => $this->guard,
            'user_id' => $this->userId,
            'workspace_id' => $this->workspaceId,
            'member_id' => $this->memberId,
        ];
    }

    protected static function fromCache(array $data): self
    {
        $ticket = new self;
        $ticket->store = (string) ($data['store'] ?? 'default');
        $ticket->module = (string) ($data['module'] ?? '');
        $ticket->sessionId = (string) ($data['session_id'] ?? '');
        $ticket->guard = (string) ($data['guard'] ?? 'app');
        $ticket->userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
        $ticket->workspaceId = $data['workspace_id'] ?? null;
        $ticket->memberId = $data['member_id'] ?? null;

        return $ticket;
    }

    protected static function cacheKey(string $sessionId): string
    {
        return 'alioss:ticket:'.$sessionId;
    }
}
