<?php

namespace App\Modules\CampusAgent\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CampusAgent\Models\CampusAgentPost;
use App\Modules\CampusAgent\Models\CampusAgentWechatBinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 校区代理交流板（C 端小程序入口）
 * 仅「后台绑定过小程序 openid 的代理」可访问。所有代理互相可见。
 */
class CampusAgentPostController extends Controller
{
    /** 列表（所有代理帖） */
    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveAgentIdentity($request);
        if ($identity === null) {
            return ApiResponse::error('NOT_CAMPUS_AGENT', '仅校区代理可访问。', 403);
        }

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category' => ['nullable', Rule::in(array_keys(CampusAgentPost::CATEGORIES))],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;

        $query = CampusAgentPost::query()
            ->where('status', 'published')
            ->with(['author:id,name', 'campus:id,name'])
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category_code', $c))
            ->orderByDesc('published_at')->orderByDesc('id');

        $total = $query->count();
        $items = $query->forPage($page, $size)->get()->map(fn (CampusAgentPost $p) => $this->data($p));

        return ApiResponse::success([
            'items' => $items,
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
            'identity' => $identity,
        ]);
    }

    /** 详情 */
    public function show(Request $request, string $post): JsonResponse
    {
        $identity = $this->resolveAgentIdentity($request);
        if ($identity === null) {
            return ApiResponse::error('NOT_CAMPUS_AGENT', '仅校区代理可访问。', 403);
        }

        $model = CampusAgentPost::query()
            ->with(['author:id,name', 'campus:id,name'])
            ->where('public_id', $post)
            ->first();

        abort_unless($model !== null && $model->status === 'published', 404);
        $model->increment('view_count');

        return ApiResponse::success(['item' => $this->data($model, withContent: true)]);
    }

    /** 发布 */
    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveAgentIdentity($request);
        if ($identity === null) {
            return ApiResponse::error('NOT_CAMPUS_AGENT', '仅校区代理可发布。', 403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:140'],
            'content' => ['required', 'string', 'max:5000'],
            'category_code' => ['nullable', Rule::in(array_keys(CampusAgentPost::CATEGORIES))],
        ]);

        $campusId = $identity['campus_id'];
        $model = CampusAgentPost::create([
            'author_admin_id' => $identity['admin_user_id'],
            'author_campus_id' => $campusId,
            'title' => $data['title'],
            'content' => $data['content'],
            'category_code' => $data['category_code'] ?? 'notice',
            'status' => 'published',
            'published_at' => now(),
        ]);

        return ApiResponse::success(['item' => $this->data($model, withContent: true)], 201);
    }

    /** 删除（仅作者本人或平台管理员） */
    public function destroy(Request $request, string $post): JsonResponse
    {
        $identity = $this->resolveAgentIdentity($request);
        if ($identity === null) {
            return ApiResponse::error('NOT_CAMPUS_AGENT', '仅校区代理可操作。', 403);
        }

        $model = CampusAgentPost::where('public_id', $post)->first();
        abort_unless($model !== null, 404);
        abort_unless($model->author_admin_id === $identity['admin_user_id'], 403, '仅作者可删除。');
        $model->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * 把 C 端 User（小程序登录）解析为代理身份。
     * 通过 auth_identities 表找到该 user 的小程序 openid，再查绑定表。
     *
     * @return array{admin_user_id:int, campus_id:?int, nickname:?string}|null
     */
    private function resolveAgentIdentity(Request $request): ?array
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        // 从 auth_identities 找小程序 openid
        $row = \DB::table('auth_identities')
            ->where('user_id', $user->id)
            ->where('provider_code', 'wechat_mini')
            ->orderByDesc('last_used_at')
            ->first(['provider_user_value', 'last_used_at']);

        if ($row === null) {
            return null;
        }

        $hash = CampusAgentWechatBinding::hashOpenid($row->provider_user_value);
        $binding = CampusAgentWechatBinding::query()
            ->where('openid_hash', $hash)
            ->with(['adminUser:id,name'])
            ->first();

        if ($binding === null) {
            return null;
        }

        // 取代理主校区
        $campusId = \DB::table('admin_user_campus')
            ->where('admin_user_id', $binding->admin_user_id)
            ->where('is_primary', true)
            ->value('campus_id');

        if ($campusId === null) {
            $campusId = \DB::table('admin_user_campus')
                ->where('admin_user_id', $binding->admin_user_id)
                ->value('campus_id');
        }

        $binding->update(['last_used_at' => now()]);

        return [
            'admin_user_id' => $binding->admin_user_id,
            'campus_id' => $campusId !== null ? (int) $campusId : null,
            'nickname' => $binding->nickname_snapshot ?? $binding->adminUser?->name,
        ];
    }

    private function data(CampusAgentPost $post, bool $withContent = false): array
    {
        $campusName = $post->campus?->name;
        $authorName = $post->author?->name ?? '校区代理';
        $out = [
            'public_id' => $post->public_id,
            'title' => $post->title,
            'category_code' => $post->category_code,
            'category_label' => CampusAgentPost::CATEGORIES[$post->category_code] ?? $post->category_code,
            'author_name' => $authorName,
            'campus_name' => $campusName,
            'view_count' => $post->view_count,
            'like_count' => $post->like_count,
            'comment_count' => $post->comment_count,
            'published_at' => optional($post->published_at)->toIso8601String(),
            'is_mine' => false,
        ];
        if ($withContent) {
            $out['content'] = $post->content;
        }

        return $out;
    }
}
