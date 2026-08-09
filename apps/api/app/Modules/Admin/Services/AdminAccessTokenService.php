<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Support\Str;
use RuntimeException;

class AdminAccessTokenService
{
    /**
     * @return array{token: string, expires_in: int}
     */
    public function issue(AdminUser $admin, AdminSession $session): array
    {
        $issuedAt = now()->timestamp;
        $ttl = max(60, (int) config('admin_auth.access_ttl_seconds'));
        $payload = [
            'iss' => config('admin_auth.issuer'),
            'aud' => config('admin_auth.audience'),
            'sub' => $admin->public_id,
            'sid' => $session->public_id,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttl,
            'jti' => (string) Str::ulid(),
        ];

        return ['token' => $this->encode($payload), 'expires_in' => $ttl];
    }

    /** @return array<string, mixed> */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed admin access token.');
        }

        [$headerPart, $payloadPart, $signaturePart] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac(
            'sha256',
            $headerPart.'.'.$payloadPart,
            $this->signingKey(),
            true,
        ));

        if (! hash_equals($expected, $signaturePart)) {
            throw new RuntimeException('Invalid admin access token signature.');
        }

        $header = json_decode($this->base64UrlDecode($headerPart), true, flags: JSON_THROW_ON_ERROR);
        $payload = json_decode($this->base64UrlDecode($payloadPart), true, flags: JSON_THROW_ON_ERROR);

        if (($header['alg'] ?? null) !== 'HS256'
            || ($payload['iss'] ?? null) !== config('admin_auth.issuer')
            || ($payload['aud'] ?? null) !== config('admin_auth.audience')
            || ! is_int($payload['exp'] ?? null)
            || $payload['exp'] <= now()->timestamp
            || ! is_string($payload['sub'] ?? null)
            || ! is_string($payload['sid'] ?? null)) {
            throw new RuntimeException('Invalid or expired admin access token.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$body, $this->signingKey(), true));

        return $header.'.'.$body.'.'.$signature;
    }

    private function signingKey(): string
    {
        return hash('sha256', (string) config('admin_auth.jwt_secret'), true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Malformed admin access token encoding.');
        }

        return $decoded;
    }
}
