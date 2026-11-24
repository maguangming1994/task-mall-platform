<?php

namespace App\Modules\Community\Http\Controllers;

use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\ShortLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * 短链跳转。
 *
 * `/s/{code}` → 查表找目标 → 302 跳转到前端帖子详情页。
 * 短链码为 6 位随机字母数字，不暴露自增 ID，防止枚举遍历。
 */
class ShortLinkController
{
    public function post(Request $request, string $code)
    {
        if (strlen($code) !== ShortLink::CODE_LENGTH
            || ! preg_match('/^['.preg_quote(ShortLink::ALPHABET, '/').']+$/', $code)) {
            abort(404, '短链无效');
        }

        $link = ShortLink::query()->where('code', $code)->first();
        if (! $link) {
            abort(404, '短链不存在');
        }

        // 异步记录点击，不阻塞跳转
        try {
            $link->recordClick();
        } catch (\Throwable) {
            // 记录失败不影响跳转
        }

        if ($link->target_type !== 'community_post') {
            abort(404, '短链目标类型不支持');
        }

        $post = CommunityPost::query()->find($link->target_id);
        if (! $post) {
            abort(404, '帖子已删除');
        }

        $base = rtrim((string) config('app.user_h5_url'), '/');
        if ($base === '') {
            $base = rtrim((string) config('app.url'), '/');
        }
        $url = $base.'/#/pages/community-detail/index?id='.rawurlencode((string) $post->public_id);

        return Redirect::away($url)->setStatusCode(302);
    }
}
