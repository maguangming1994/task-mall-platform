<?php

namespace App\Modules\Auth\Services;

use AlibabaCloud\Dara\Models\RuntimeOptions;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;
use Darabonba\OpenApi\Models\Config as OpenApiConfig;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AliyunSmsSender
{
    public function __construct(private readonly SmsProviderSettingsService $settings) {}

    /** @return array{request_id:?string,biz_id:?string}|null */
    public function send(string $phone, string $purpose, string $code): ?array
    {
        $configuration = (array) ($this->settings->effective()['aliyun'] ?? []);
        $credentials = $this->settings->aliyunCredentials();
        $accessKeyId = trim((string) ($credentials['access_key_id'] ?? ''));
        $accessKeySecret = trim((string) ($credentials['access_key_secret'] ?? ''));
        $signName = trim((string) ($configuration['sign_name'] ?? ''));
        $templateCode = trim((string) ($configuration['template_code'] ?? ''));

        if ($accessKeyId === '' || $accessKeySecret === '' || $signName === '' || $templateCode === '') {
            throw new RuntimeException('The Aliyun SMS provider is incomplete.');
        }

        try {
            $clientConfig = new OpenApiConfig([
                'accessKeyId' => $accessKeyId,
                'accessKeySecret' => $accessKeySecret,
                'securityToken' => $this->nullableString($credentials['security_token'] ?? null),
                'regionId' => (string) ($configuration['region'] ?? 'cn-hangzhou'),
                'endpoint' => $this->endpointHost((string) ($configuration['endpoint'] ?? 'dysmsapi.aliyuncs.com')),
            ]);
            $client = new Dysmsapi($clientConfig);
            $templateVariables = (array) ($configuration['verification_variables'] ?? ['code']);
            if ($templateVariables === []) {
                $templateVariables = ['code'];
            }
            $templateParameters = [];
            foreach ($templateVariables as $variable) {
                $variable = trim((string) $variable);
                if ($variable === '') {
                    continue;
                }
                $templateParameters[$variable] = in_array(strtolower($variable), ['minutes', 'ttl_minutes'], true)
                    ? (string) max(1, (int) ceil(((int) ($this->settings->effective()['ttl_seconds'] ?? 300)) / 60))
                    : $code;
            }
            if ($templateParameters === []) {
                $templateParameters['code'] = $code;
            }
            $request = new SendSmsRequest([
                'phoneNumbers' => $phone,
                'signName' => $signName,
                'templateCode' => $templateCode,
                'templateParam' => json_encode($templateParameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $timeout = max(1, (int) ($configuration['timeout_seconds'] ?? 5)) * 1000;
            $runtime = new RuntimeOptions([
                'autoretry' => false,
                'max_attempts' => 1,
                'connectTimeout' => $timeout,
                'readTimeout' => $timeout,
            ]);
            $response = $client->sendSmsWithOptions($request, $runtime);
            $body = $response->body;

            if ($body === null || strtoupper((string) $body->code) !== 'OK') {
                Log::warning('Aliyun rejected a phone verification request.', [
                    'provider_code' => $body?->code,
                    'request_id' => $body?->requestId,
                    'purpose' => $purpose,
                ]);

                throw new RuntimeException('The Aliyun SMS provider rejected the request.');
            }

            Log::info('Aliyun accepted a phone verification request.', [
                'request_id' => $body->requestId,
                'biz_id' => $body->bizId,
                'purpose' => $purpose,
            ]);

            return [
                'request_id' => $body->requestId === null ? null : (string) $body->requestId,
                'biz_id' => $body->bizId === null ? null : (string) $body->bizId,
            ];
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Aliyun phone verification delivery failed.', [
                'exception' => $exception::class,
                'purpose' => $purpose,
            ]);

            throw new RuntimeException('The Aliyun SMS provider is unavailable.', previous: $exception);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function endpointHost(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        $endpoint = preg_replace('#^https?://#i', '', $endpoint) ?: $endpoint;

        return rtrim($endpoint, '/');
    }
}
