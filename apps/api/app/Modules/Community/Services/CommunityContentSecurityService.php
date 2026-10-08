<?php

namespace App\Modules\Community\Services;

use App\Models\User;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Services\WechatAccessTokenService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 社区 UGC 微信内容安全检测（wxa/msg_sec_check v2）。
 *
 * 仅负责给出判定，不改变业务状态：
 *  - pass    微信认为正常
 *  - review  疑似违规，需人工复审
 *  - block   明确违规，调用方应直接拒绝
 *  - skipped 未配置/未绑定微信/接口异常等，调用方回退到本地敏感词 + 审核模式
 *
 * 设计原则：微信侧任何故障都降级为 skipped，绝不阻断正常发帖。
 */
class CommunityContentSecurityService
{
    public const PASS = 'pass';

    public const REVIEW = 'review';

    public const BLOCK = 'block';

    public const SKIPPED = 'skipped';

    private const ENDPOINT = 'https://api.weixin.qq.com/wxa/msg_sec_check';

    public function __construct(private readonly WechatAccessTokenService $tokens) {}

    /**
     * 检测一段文本。
     *
     * @param  int  $scene  微信场景值：2=评论，3=论坛/社区帖子
     * @return array{verdict:string,label:?int,source:string}
     */
    public function scanText(User $user, string $content, int $scene): array
    {
        $content = trim($content);
        if ($content === '') {
            return $this->skipped('empty');
        }

        // msg_sec_check v2 要求携带发帖用户在当前小程序下的 openid；
        // 手机号注册、未绑定微信的用户无法检测，交由本地敏感词与人工审核兜底。
        $openid = UserIdentity::query()
            ->where('user_id', $user->id)
            ->where('provider', 'wechat_mini')
            ->value('provider_user_value');
        if (! is_string($openid) || $openid === '') {
            return $this->skipped('no-openid');
        }

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

        $errcode = (int) ($payload['errcode'] ?? -1);
        // 87014：内容含有违法违规内容（历史版本兼容返回）
        if ($errcode === 87014) {
            return ['verdict' => self::BLOCK, 'label' => (int) ($payload['result']['label'] ?? 0), 'source' => 'wechat'];
        }
        if ($errcode !== 0) {
            Log::warning('社区内容安全检测降级：微信返回错误码', [
                'errcode' => $errcode,
                'errmsg' => $payload['errmsg'] ?? null,
            ]);

            return $this->skipped('errcode-'.$errcode);
        }

        $suggest = (string) ($payload['result']['suggest'] ?? self::PASS);
        $label = isset($payload['result']['label']) ? (int) $payload['result']['label'] : null;
        $verdict = match ($suggest) {
            'risky' => self::BLOCK,
            'review' => self::REVIEW,
            default => self::PASS,
        };

        return ['verdict' => $verdict, 'label' => $label, 'source' => 'wechat'];
    }

    /** @return array{verdict:string,label:null,source:string} */
    private function skipped(string $source): array
    {
        return ['verdict' => self::SKIPPED, 'label' => null, 'source' => $source];
    }
}
