<?php

namespace App\Modules\Share\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\ShortLink;
use App\Modules\Material\Models\Material;
use App\Modules\Product\Models\Product;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\Share\Services\MiniProgramCodeService;
use App\Modules\Task\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 分享二维码：海报二维码优先返回微信小程序码，小程序未上线时回退 H5 链接。
 *
 * 公开接口：分享落地页可能未登录，因此不要求登录态；target 使用 public_id，避免暴露自增 ID。
 */
class ShareQrCodeController extends Controller
{
    private const TARGETS = ['community_post', 'task', 'product', 'material', 'second_hand', 'referral'];

    public function __construct(private readonly MiniProgramCodeService $codes) {}

    /**
     * 返回二维码决策：mode=mini 时给出小程序码图片地址，mode=h5 时给出 H5 链接。
     */
    public function show(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $target = $this->resolve($data['type'], $data['id']);
        if ($target === null) {
            return ApiResponse::error('SHARE_TARGET_NOT_FOUND', '分享内容不存在或已下架。', 404);
        }

        $png = $this->codes->png($target['path']);
        if ($png === null) {
            return ApiResponse::success([
                'mode' => 'h5',
                'image_url' => null,
                'image_base64' => null,
                'link' => $target['h5'],
            ]);
        }

        return ApiResponse::success([
            'mode' => 'mini',
            'image_url' => $this->imageUrl($data['type'], $data['id']),
            // H5 直接使用 base64 绘制，避免跨域图片污染画布导致海报无法导出
            'image_base64' => 'data:image/png;base64,'.base64_encode($png),
            'link' => $target['h5'],
        ]);
    }

    /** 输出小程序码 PNG；小程序不可用时 404，前端回退到 H5 二维码。 */
    public function image(Request $request): Response
    {
        $data = $this->validated($request);
        $target = $this->resolve($data['type'], $data['id']);
        $png = $target === null ? null : $this->codes->png($target['path']);
        if ($png === null) {
            abort(404, '小程序码暂不可用');
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=2592000',
            // 允许 H5 画布跨域绘制图片后导出海报
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** @return array{type:string,id:string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::TARGETS)],
            'id' => ['required', 'string', 'max:64'],
        ]);

        return [
            'type' => (string) $data['type'],
            'id' => trim((string) $data['id']),
        ];
    }

    /** @return array{path:string,h5:string}|null */
    private function resolve(string $type, string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        $h5Base = $this->h5Base();

        return match ($type) {
            'community_post' => $this->communityPost($id, $h5Base),
            'task' => Task::query()->where('public_id', $id)->exists()
                ? $this->page('/pages/task-detail/index', $id, $h5Base)
                : null,
            'product' => Product::query()->where('public_id', $id)->exists()
                ? $this->page('/pages/product-detail/index', $id, $h5Base)
                : null,
            'material' => Material::query()->where('public_id', $id)->exists()
                ? $this->page('/pages/material-detail/index', $id, $h5Base)
                : null,
            'second_hand' => SecondHandItem::query()->where('public_id', $id)->exists()
                ? $this->page('/pages/second-hand-detail/index', $id, $h5Base)
                : null,
            // 邀请码本身不落库（由用户 public_id 派生），这里仅做格式校验
            'referral' => preg_match('/^[A-Za-z0-9]{4,32}$/', $id) === 1
                ? $this->referral($id, $h5Base)
                : null,
            default => null,
        };
    }

    /** @return array{path:string,h5:string}|null */
    private function communityPost(string $publicId, string $h5Base): ?array
    {
        $post = CommunityPost::query()->where('public_id', $publicId)->first();
        if ($post === null) {
            return null;
        }
        // 帖子沿用短链：不暴露自增 ID，并可在短链表统计点击
        $link = ShortLink::ensureFor('community_post', (int) $post->id);
        $h5 = rtrim((string) config('app.url'), '/').'/s/'.$link->code;

        return ['path' => '/pages/community-detail/index?id='.rawurlencode($publicId), 'h5' => $h5];
    }

    /** @return array{path:string,h5:string} */
    private function page(string $page, string $publicId, string $h5Base): array
    {
        return [
            'path' => $page.'?id='.rawurlencode($publicId),
            'h5' => $h5Base.'/#'.$page.'?id='.rawurlencode($publicId),
        ];
    }

    /** @return array{path:string,h5:string} */
    private function referral(string $code, string $h5Base): array
    {
        $normalized = strtoupper($code);
        $query = '?referral_code='.rawurlencode($normalized);

        return [
            'path' => '/pages/referrals/index'.$query,
            'h5' => $h5Base.'/#/pages/referrals/index'.$query,
        ];
    }

    private function h5Base(): string
    {
        $base = rtrim((string) config('app.user_h5_url'), '/');

        return $base !== '' ? $base : rtrim((string) config('app.url'), '/');
    }

    private function imageUrl(string $type, string $id): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/share/qrcode/image?'
            .http_build_query(['type' => $type, 'id' => $id]);
    }
}
