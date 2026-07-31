<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * 校验 Apple Sign In 返回的 identityToken（JWT）。
 */
class AppleTokenVerifier
{
    public function verify(string $identityToken): array
    {
        $parts = explode('.', $identityToken);
        if (count($parts) !== 3) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple 登录凭证无效'],
            ]);
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;
        $header = json_decode($this->b64urlDecode($headerB64), true);
        $payload = json_decode($this->b64urlDecode($payloadB64), true);

        if (! is_array($header) || ! is_array($payload)) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple 登录凭证无法解析'],
            ]);
        }

        $kid = $header['kid'] ?? null;
        $alg = $header['alg'] ?? null;
        if ($alg !== 'RS256' || ! $kid) {
            throw ValidationException::withMessages([
                'identity_token' => ['不支持的 Apple 令牌算法'],
            ]);
        }

        $key = $this->findAppleKey($kid);
        if (! $key) {
            // 刷新一次公钥缓存再试
            Cache::forget('apple_jwks');
            $key = $this->findAppleKey($kid);
        }
        if (! $key) {
            throw ValidationException::withMessages([
                'identity_token' => ['无法匹配 Apple 公钥'],
            ]);
        }

        $pem = $this->jwkToPem($key);
        $signed = $headerB64.'.'.$payloadB64;
        $signature = $this->b64urlDecode($sigB64);
        $ok = openssl_verify($signed, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple 登录凭证验签失败'],
            ]);
        }

        $clientIds = $this->allowedClientIds();

        if (($payload['iss'] ?? '') !== 'https://appleid.apple.com') {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple 签发方不正确'],
            ]);
        }

        $aud = $payload['aud'] ?? null;
        if (is_array($aud)) {
            $aud = $aud[0] ?? null;
        }
        if ($clientIds && ! in_array($aud, $clientIds, true)) {
            throw ValidationException::withMessages([
                'identity_token' => [
                    'Apple 受众与 Bundle ID 不匹配（token.aud='.($aud ?: '空')
                    .'，服务端期望='.implode(',', $clientIds).'）。请把 APPLE_CLIENT_ID 改成与 iOS Bundle ID 完全一致后 php artisan config:clear',
                ],
            ]);
        }

        if (! empty($payload['exp']) && time() >= (int) $payload['exp']) {
            throw ValidationException::withMessages([
                'identity_token' => ['Apple 登录凭证已过期'],
            ]);
        }

        if (empty($payload['sub'])) {
            throw ValidationException::withMessages([
                'identity_token' => ['缺少 Apple 用户标识'],
            ]);
        }

        return $payload;
    }

    /** @return list<string> */
    private function allowedClientIds(): array
    {
        $raw = array_filter([
            config('services.apple.client_id'),
            config('services.apple.client_id_web'),
        ]);

        $ids = [];
        foreach ($raw as $item) {
            foreach (preg_split('/\s*,\s*/', (string) $item) ?: [] as $id) {
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function findAppleKey(string $kid): ?array
    {
        $jwks = Cache::remember('apple_jwks', 86400, function () {
            $res = Http::timeout(10)->get('https://appleid.apple.com/auth/keys');
            if (! $res->successful()) {
                throw new RuntimeException('无法拉取 Apple 公钥');
            }

            return $res->json();
        });

        foreach (($jwks['keys'] ?? []) as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }

        return null;
    }

    private function jwkToPem(array $jwk): string
    {
        $n = $this->b64urlDecode($jwk['n'] ?? '');
        $e = $this->b64urlDecode($jwk['e'] ?? '');
        if ($n === '' || $e === '') {
            throw new RuntimeException('Apple JWK 缺少 n/e');
        }

        $modulus = $this->encodeAsn1Integer($n);
        $exponent = $this->encodeAsn1Integer($e);
        $rsaPublicKey = $this->encodeAsn1Sequence($modulus.$exponent);
        $bitString = "\x03".$this->encodeAsn1Length(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;
        $rsaOid = hex2bin('300d06092a864886f70d0101010500');
        $publicKeyInfo = $this->encodeAsn1Sequence($rsaOid.$bitString);
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($publicKeyInfo), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        return $pem;
    }

    private function encodeAsn1Integer(string $bytes): string
    {
        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->encodeAsn1Length(strlen($bytes)).$bytes;
    }

    private function encodeAsn1Sequence(string $contents): string
    {
        return "\x30".$this->encodeAsn1Length(strlen($contents)).$contents;
    }

    private function encodeAsn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $temp = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($temp)).$temp;
    }

    private function b64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
