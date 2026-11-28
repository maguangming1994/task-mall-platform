<?php

namespace App\Modules\Community\Services;

use App\Models\User;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Services\WechatAccessTokenService;
use App\Modules\Community\Models\CommunityComment;
use App\Modules\Community\Models\CommunityContentSecurityLog;
use App\Modules\Community\Models\CommunityPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 社区 UGC 内容安全检测。仅给出判定并记录检测日志，不直接改变业务状态。
 */
class CommunityContentSecurityService
{
    public const PASS = 'pass';

    public const REVIEW = 'review';

    public const BLOCK = 'block';

    public const SKIPPED = 'skipped';

    private const ENDPOINT = 'https://api.weixin.qq.com/wxa/msg_sec_check';

    public function __construct(
        private readonly WechatAccessTokenService $tokens,
        private readonly CommunityContentSecuritySettingsService $settings,
        private readonly AliyunContentSecurityClient $aliyun,
    ) {}

    /**
     * @param  int  $scene  微信场景值：2=评论，3=论坛/社区帖子
     * @param  array<string,mixed>  $context
     * @return array{verdict:string,label:?int,source:string,provider:string,skip_reason:?string,record_id:int}
     */
    public function scanText(User $user, string $content, int $scene, array $context = []): array
    {
        $content = trim($content);
        $configuration = $this->settings->configuration();
        $provider = $configuration['provider'];
        $publicId = (string) Str::ulid();

        if ($content === '') {
            return $this->record($user, $content, $provider, $this->skipped('empty'), $context, $publicId);
        }
        if (! $configuration['enabled']) {
            return $this->record($user, $content, $provider, $this->skipped('disabled'), $context, $publicId);
        }
        if ($provider === 'aliyun') {
            if (! $configuration['configured']) {
                return $this->record($user, $content, $provider, $this->skipped('not-configured'), $context, $publicId);
            }
            try {
                $result = $this->aliyun->scan($configuration, $content, $publicId);
            } catch (Throwable $exception) {
                Log::warning('社区内容安全检测降级：阿里云接口不可用', ['reason' => $exception->getMessage()]);

                return $this->record($user, $content, $provider, $this->skipped('error'), $context, $publicId);
            }

            return $this->record($user, $content, $provider, $result + ['label' => null, 'source' => 'aliyun'], $context, $publicId);
        }

        $result = $this->scanWechat($user, $content, $scene);

        return $this->record($user, $content, 'wechat', $result, $context, $publicId);
    }

    /** @param array<int,int> $recordIds */
    public function linkToContent(array $recordIds, CommunityPost|CommunityComment $content, ?int $versionNo = null): void
    {
        if ($recordIds === []) {
            return;
        }
        CommunityContentSecurityLog::query()->whereIn('id', $recordIds)->update([
            'content_id' => $content->getKey(),
            'content_public_id' => $content->public_id,
            'content_version_no' => $versionNo,
        ]);
    }

    /** @return array<string,mixed> */
    private function scanWechat(User $user, string $content, int $scene): array
    {
        // 注意：provider_user_value 在 UserIdentity 模型里 encrypted cast，
        // 必须走模型属性访问（first()?->provider_user_value）才会自动解密；
        // 用 query()->value() 拿到的是加密后的密文，微信会返回 errcode=40003。
        $identity = UserIdentity::query()
            ->where('user_id', $user->id)
            ->where('provider', 'wechat_mini')
            ->orderByDesc('last_login_at')
            ->first();
        $openid = $identity?->provider_user_value;
        if (! is_string($openid) || $openid === '') {
            return $this->skipped('no-openid');
        }

        $startedAt = microtime(true);
        try {
            $response = Http::acceptJson()->timeout(8)
                ->withQueryParameters(['access_token' => $this->tokens->token('mini')])
                ->post(self::ENDPOINT, [
                    'version' => 2,
                    'openid' => $openid,
                    'scene' => $scene,
                    'content' => mb_substr($content, 0, 2500),
                ]);
        } catch (Throwable $exception) {
            Log::warning('社区内容安全检测降级：微信接口不可用', ['reason' => $exception->getMessage()]);

            return $this->skipped('error');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return $this->skipped('bad-response');
        }
        $responseTime = (int) round((microtime(true) - $startedAt) * 1000);
        $errcode = (int) ($payload['errcode'] ?? -1);
        $label = isset($payload['result']['label']) ? (int) $payload['result']['label'] : null;
        if ($errcode === 87014) {
            return $this->wechatResult(self::BLOCK, $label, $responseTime);
        }
        if ($errcode !== 0) {
            Log::warning('社区内容安全检测降级：微信返回错误码', [
                'errcode' => $errcode,
                'errmsg' => $payload['errmsg'] ?? null,
            ]);

            return $this->skipped('errcode-'.$errcode) + ['response_time_ms' => $responseTime];
        }

        $suggest = (string) ($payload['result']['suggest'] ?? self::PASS);
        $verdict = match ($suggest) {
            'risky' => self::BLOCK,
            'review' => self::REVIEW,
            default => self::PASS,
        };

        return $this->wechatResult($verdict, $label, $responseTime);
    }

    /** @return array<string,mixed> */
    private function wechatResult(string $verdict, ?int $label, int $responseTime): array
    {
        return [
            'verdict' => $verdict,
            'label' => $label,
            'source' => 'wechat',
            'risk_level' => $this->riskLevel($verdict),
            'risk_labels' => $label === null ? [] : [(string) $label],
            'request_id' => null,
            'response_time_ms' => $responseTime,
        ];
    }

    /** @return array{verdict:string,label:null,source:string,risk_level:null,risk_labels:array<int,string>,skip_reason:string} */
    private function skipped(string $source): array
    {
        return [
            'verdict' => self::SKIPPED,
            'label' => null,
            'source' => $source,
            'risk_level' => null,
            'risk_labels' => [],
            'skip_reason' => $source,
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $context
     * @return array{verdict:string,label:?int,source:string,provider:string,skip_reason:?string,record_id:int}
     */
    private function record(User $user, string $content, string $provider, array $result, array $context, string $publicId): array
    {
        $log = CommunityContentSecurityLog::query()->create([
            'public_id' => $publicId,
            'campus_id' => $context['campus_id'] ?? $user->campus_id,
            'user_id' => $user->id,
            'content_type' => $context['content_type'] ?? 'post',
            'content_id' => $context['content_id'] ?? null,
            'content_public_id' => $context['content_public_id'] ?? null,
            'content_version_no' => $context['content_version_no'] ?? null,
            'content_field' => $context['content_field'] ?? 'content',
            'subject_snapshot' => $context['subject_snapshot'] ?? null,
            'content_snapshot' => $content,
            'content_hash' => hash('sha256', $content),
            'provider' => $provider,
            'verdict' => $result['verdict'],
            'risk_level' => $result['risk_level'] ?? $this->riskLevel($result['verdict']),
            'risk_labels' => $result['risk_labels'] ?? [],
            'skip_reason' => $result['skip_reason'] ?? null,
            'request_id' => $result['request_id'] ?? null,
            'response_time_ms' => $result['response_time_ms'] ?? null,
        ]);

        return [
            'verdict' => $result['verdict'],
            'label' => $result['label'] ?? null,
            'source' => $result['source'],
            'provider' => $provider,
            'skip_reason' => $result['skip_reason'] ?? null,
            'record_id' => $log->id,
        ];
    }

    private function riskLevel(string $verdict): ?string
    {
        return match ($verdict) {
            self::PASS => 'none',
            self::REVIEW => 'medium',
            self::BLOCK => 'high',
            default => null,
        };
    }
}
