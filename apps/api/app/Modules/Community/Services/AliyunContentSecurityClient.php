<?php

namespace App\Modules\Community\Services;

use AlibabaCloud\Dara\Models\RuntimeOptions;
use AlibabaCloud\SDK\Green\V20220302\Green;
use AlibabaCloud\SDK\Green\V20220302\Models\TextModerationPlusRequest;
use Darabonba\OpenApi\Models\Config;
use RuntimeException;
use Throwable;

class AliyunContentSecurityClient
{
    /**
     * @param  array<string,mixed>  $configuration
     * @return array{verdict:string,risk_level:string,risk_labels:array<int,string>,request_id:?string,response_time_ms:int}
     */
    public function scan(array $configuration, string $content, string $dataId): array
    {
        $startedAt = microtime(true);
        try {
            $config = new Config([
                'accessKeyId' => $configuration['access_key_id'],
                'accessKeySecret' => $configuration['access_key_secret'],
                'regionId' => $configuration['region'],
                'endpoint' => $configuration['endpoint'],
            ]);
            $client = $this->createClient($config);
            $request = new TextModerationPlusRequest([
                'service' => $configuration['service'],
                'serviceParameters' => json_encode([
                    'content' => mb_substr($content, 0, 2500),
                    'dataId' => $dataId,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $runtime = new RuntimeOptions([
                'connectTimeout' => 3000,
                'readTimeout' => 8000,
                'autoretry' => false,
            ]);
            $response = $client->textModerationPlusWithOptions($request, $runtime);
            $payload = $response->body?->toMap();
        } catch (Throwable $exception) {
            throw new RuntimeException('阿里云内容安全接口调用失败。', 0, $exception);
        }

        if (! is_array($payload) || (int) ($payload['Code'] ?? 0) !== 200 || ! is_array($payload['Data'] ?? null)) {
            throw new RuntimeException('阿里云内容安全返回了无效响应。');
        }
        $data = $payload['Data'];
        $rawRiskLevel = strtolower((string) ($data['RiskLevel'] ?? 'none'));
        $riskLevel = match ($rawRiskLevel) {
            'high', 'medium', 'low', 'none' => $rawRiskLevel,
            '' => 'none',
            default => 'medium',
        };
        $verdict = match ($riskLevel) {
            'high' => CommunityContentSecurityService::BLOCK,
            'medium' => CommunityContentSecurityService::REVIEW,
            'low', 'none' => CommunityContentSecurityService::PASS,
            default => CommunityContentSecurityService::REVIEW,
        };
        $labels = collect($data['Result'] ?? [])->map(fn ($result) => is_array($result) ? ($result['Label'] ?? null) : null)
            ->filter(fn ($label) => is_string($label) && $label !== '')->unique()->values()->all();

        return [
            'verdict' => $verdict,
            'risk_level' => $riskLevel === '' ? 'none' : $riskLevel,
            'risk_labels' => $labels,
            'request_id' => isset($payload['RequestId']) ? (string) $payload['RequestId'] : null,
            'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    protected function createClient(Config $config): Green
    {
        return new Green($config);
    }
}
