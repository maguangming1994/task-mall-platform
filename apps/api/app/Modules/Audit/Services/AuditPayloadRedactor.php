<?php

namespace App\Modules\Audit\Services;

use Illuminate\Support\Str;

class AuditPayloadRedactor
{
    public const REDACTED = '[已脱敏]';

    /**
     * @param  array<string|int, mixed>|null  $payload
     * @return array<string|int, mixed>|null
     */
    public function redact(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return $this->redactArray($payload);
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string|int, mixed>
     */
    private function redactArray(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = is_array($value)
                ? $this->redactArray($value)
                : $value;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = Str::snake(str_replace(['.', '-'], '_', mb_strtolower($key)));

        if (in_array($normalized, [
            'authorization',
            'cookie',
            'set_cookie',
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'access_token',
            'refresh_token',
            'session_token',
            'api_key',
            'secret_key',
            'client_secret',
            'private_key',
            'verification_code',
            'sms_code',
            'mfa_code',
            'totp_secret',
            'phone',
            'mobile',
            'email',
            'id_card',
            'identity_number',
            'bank_card',
            'bank_account',
            'card_number',
            'account_number',
            'detailed_address',
            'detail_address',
            'receiver_name',
            'contact_name',
            'ip_hash',
        ], true)) {
            return true;
        }

        return preg_match(
            '/(?:^|_)(?:password|passwd|secret|access_token|refresh_token|session_token|authorization|verification_code|sms_code|mfa_code|totp_secret|private_key|api_key|client_secret|phone|mobile|email|id_card|identity_number|bank_card|bank_account|card_number|account_number|detailed_address|detail_address|receiver_name|contact_name|ip_hash)(?:_|$)/',
            $normalized,
        ) === 1;
    }
}
