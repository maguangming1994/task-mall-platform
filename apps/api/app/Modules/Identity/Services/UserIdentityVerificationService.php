<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Auth\Services\IdentityHasher;
use App\Modules\Identity\Models\UserIdentityVerification;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UserIdentityVerificationService
{
    public function __construct(
        private readonly IdentityVerificationSettingsService $settings,
        private readonly AliyunIdentityVerifier $aliyun,
        private readonly IdentityHasher $hasher,
    ) {}

    /** @return array<string,mixed> */
    public function status(User $user): array
    {
        $configuration = $this->settings->effective();
        $verification = UserIdentityVerification::query()->where('user_id', $user->id)->latest('id')->first();

        return [
            'enabled' => (bool) $configuration['collection_enabled'] && $configuration['driver'] !== 'disabled',
            'driver' => $configuration['driver'],
            'status' => $verification?->status ?? 'not_submitted',
            'real_name_masked' => $verification?->real_name_masked,
            'identity_number_masked' => $verification?->identity_number_masked,
            'verified_at' => $verification?->verified_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function verify(User $user, string $realName, string $identityNumber): array
    {
        $configuration = $this->settings->effective();
        if (! $configuration['collection_enabled'] || $configuration['driver'] === 'disabled') {
            throw ValidationException::withMessages(['identity_number' => '实名认证服务当前未开启。']);
        }

        $realName = trim(preg_replace('/\s+/u', '', $realName) ?? $realName);
        $identityNumber = strtoupper(trim($identityNumber));
        if (! $this->validChineseIdentityNumber($identityNumber)) {
            throw ValidationException::withMessages(['identity_number' => '请输入有效的二代居民身份证号码。']);
        }

        $hash = $this->hasher->hash($identityNumber);
        $verified = UserIdentityVerification::query()
            ->where('user_id', $user->id)
            ->where('status', 'verified')
            ->latest('id')
            ->first();
        if ($verified !== null) {
            if (hash_equals($verified->identity_number_hash, $hash)) {
                return $this->status($user);
            }
            throw ValidationException::withMessages(['identity_number' => '当前账号已经完成实名认证，不能直接更换实名身份。']);
        }

        $provider = (string) $configuration['driver'];
        $result = ['matched' => false, 'request_id' => null, 'provider_code' => 'manual'];
        if ($provider === 'aliyun') {
            try {
                $result = $this->aliyun->verify($realName, $identityNumber);
            } catch (RuntimeException $exception) {
                UserIdentityVerification::query()->create($this->recordData(
                    $user, $realName, $identityNumber, $hash, $provider, 'provider_error', null, 'provider_unavailable',
                ));
                throw $exception;
            }
        }

        $status = $provider === 'manual' ? 'pending' : ($result['matched'] ? 'verified' : 'failed');
        UserIdentityVerification::query()->create($this->recordData(
            $user,
            $realName,
            $identityNumber,
            $hash,
            $provider,
            $status,
            $result['request_id'],
            $status === 'failed' ? 'identity_mismatch' : null,
        ));

        return $this->status($user);
    }

    /** @return array<string,mixed> */
    private function recordData(User $user, string $realName, string $identityNumber, string $hash, string $provider, string $status, ?string $requestId, ?string $failureCode): array
    {
        return [
            'user_id' => $user->id,
            'provider' => $provider,
            'status' => $status,
            'real_name_masked' => $this->maskName($realName),
            'identity_number_masked' => substr($identityNumber, 0, 3).'***********'.substr($identityNumber, -4),
            'identity_number_hash' => $hash,
            'provider_request_id' => $requestId,
            'failure_code' => $failureCode,
            'verified_at' => $status === 'verified' ? now() : null,
        ];
    }

    private function maskName(string $name): string
    {
        $length = mb_strlen($name);
        if ($length <= 1) {
            return '*';
        }

        return mb_substr($name, 0, 1).str_repeat('*', min(6, $length - 1));
    }

    private function validChineseIdentityNumber(string $number): bool
    {
        if (! preg_match('/^[1-9]\d{5}(18|19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}[0-9X]$/', $number)) {
            return false;
        }
        $year = (int) substr($number, 6, 4);
        $month = (int) substr($number, 10, 2);
        $day = (int) substr($number, 12, 2);
        if (! checkdate($month, $day, $year)) {
            return false;
        }
        $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $checks = '10X98765432';
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += ((int) $number[$index]) * $weight;
        }

        return $number[17] === $checks[$sum % 11];
    }
}
