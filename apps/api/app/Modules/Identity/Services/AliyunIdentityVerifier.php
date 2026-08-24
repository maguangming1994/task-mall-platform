<?php

namespace App\Modules\Identity\Services;

use AlibabaCloud\Dara\Models\RuntimeOptions;
use AlibabaCloud\SDK\Cloudauth\V20190307\Cloudauth;
use AlibabaCloud\SDK\Cloudauth\V20190307\Models\Id2MetaVerifyRequest;
use Darabonba\OpenApi\Models\Config as OpenApiConfig;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AliyunIdentityVerifier
{
    public function __construct(private readonly IdentityVerificationSettingsService $settings) {}

    /** @return array{matched:bool,request_id:?string,provider_code:string} */
    public function verify(string $realName, string $identityNumber): array
    {
        $configuration = $this->settings->effective();
        $aliyun = (array) ($configuration['aliyun'] ?? []);
        $credentials = $this->settings->aliyunCredentials();
        $accessKeyId = trim((string) ($credentials['access_key_id'] ?? ''));
        $accessKeySecret = trim((string) ($credentials['access_key_secret'] ?? ''));
        if ($accessKeyId === '' || $accessKeySecret === '') {
            throw new RuntimeException('Aliyun identity verification credentials are incomplete.');
        }

        try {
            $client = new Cloudauth(new OpenApiConfig([
                'accessKeyId' => $accessKeyId,
                'accessKeySecret' => $accessKeySecret,
                'securityToken' => $this->nullableString($credentials['security_token'] ?? null),
                'regionId' => (string) ($aliyun['region'] ?? 'cn-hangzhou'),
                'endpoint' => $this->endpointHost((string) ($aliyun['endpoint'] ?? 'cloudauth.aliyuncs.com')),
            ]));
            $timeout = max(1, (int) ($aliyun['timeout_seconds'] ?? 8)) * 1000;
            $response = $client->id2MetaVerifyWithOptions(new Id2MetaVerifyRequest([
                'paramType' => 'normal',
                'userName' => $realName,
                'identifyNum' => $identityNumber,
            ]), new RuntimeOptions([
                'autoretry' => false,
                'max_attempts' => 1,
                'connectTimeout' => $timeout,
                'readTimeout' => $timeout,
            ]));
            $body = $response->body;
            if ($body === null || (string) $body->code !== '200' || $body->resultObject === null) {
                Log::warning('Aliyun identity verification request was rejected.', [
                    'provider_code' => $body?->code,
                    'request_id' => $body?->requestId,
                ]);
                throw new RuntimeException('Aliyun identity verification rejected the request.');
            }

            $bizCode = (string) $body->resultObject->bizCode;
            Log::info('Aliyun identity verification request completed.', [
                'request_id' => $body->requestId,
                'matched' => $bizCode === '1',
            ]);

            return [
                'matched' => $bizCode === '1',
                'request_id' => $body->requestId === null ? null : (string) $body->requestId,
                'provider_code' => $bizCode,
            ];
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Aliyun identity verification is unavailable.', ['exception' => $exception::class]);
            throw new RuntimeException('Aliyun identity verification is unavailable.', previous: $exception);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function endpointHost(string $endpoint): string
    {
        $endpoint = preg_replace('#^https?://#i', '', trim($endpoint)) ?: trim($endpoint);

        return rtrim($endpoint, '/');
    }
}
