<?php

namespace App\Modules\CampusAgent\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\CampusAgent\Models\CampusAgentPost;
use App\Modules\CampusAgent\Models\CampusAgentWechatBinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 后台代理绑定管理（admin-web 调用）
 * - 列出全部绑定
 * - 新增绑定：openid + admin_user_id
 * - 解绑
 * - 代理帖管理（列表/隐藏）
 */
class AdminCampusAgentBindingController extends Controller
{
    /** 列表：绑定 */
    public function index(Request $request): JsonResponse
    {
        $bindings = CampusAgentWechatBinding::query()
            ->with(['adminUser:id,name,email'])
            ->orderByDesc('last_used_at')
            ->paginate(20);

        return ApiResponse::success([
            'items' => collect($bindings->items())->map(fn (CampusAgentWechatBinding $b) => [
                'public_id' => $b->public_id,
                'admin_user_id' => $b->admin_user_id,
                'admin_name' => $b->adminUser?->name,
                'admin_email' => $b->adminUser?->email,
                'app_id' => $b->app_id,
                'nickname_snapshot' => $b->nickname_snapshot,
                'bound_at' => optional($b->bound_at)->toIso8601String(),
                'last_used_at' => optional($b->last_used_at)->toIso8601String(),
            ]),
            'pagination' => [
                'page' => $bindings->currentPage(),
                'page_size' => $bindings->perPage(),
                'total' => $bindings->total(),
            ],
        ]);
    }

    /** 新增绑定：openid + admin_user_id */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'openid' => ['required', 'string', 'max:128'],
            'admin_user_id' => ['required', 'integer', Rule::exists('admin_users', 'id')],
            'app_id' => ['nullable', 'string', 'max:64'],
            'nickname_snapshot' => ['nullable', 'string', 'max:80'],
        ]);

        $hash = CampusAgentWechatBinding::hashOpenid($data['openid']);
        $appId = $data['app_id'] ?? 'wechat_mini';

        // 检查重复
        $exists = CampusAgentWechatBinding::query()
            ->where('app_id', $appId)
            ->where('openid_hash', $hash)
            ->exists();
        if ($exists) {
            return ApiResponse::error('OPENID_ALREADY_BOUND', '该 openid 已被绑定。', 422);
        }

        $binding = CampusAgentWechatBinding::create([
            'app_id' => $appId,
            'openid_hash' => $hash,
            'admin_user_id' => $data['admin_user_id'],
            'nickname_snapshot' => $data['nickname_snapshot'] ?? null,
            'bound_at' => now(),
        ]);

        return ApiResponse::success(['public_id' => $binding->public_id], 201);
    }

    /** 解绑 */
    public function destroy(string $publicId): JsonResponse
    {
        $binding = CampusAgentWechatBinding::where('public_id', $publicId)->first();
        abort_unless($binding !== null, 404);
        $binding->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    /** 后台查看所有代理帖（含隐藏） */
    public function posts(Request $request): JsonResponse
    {
        $posts = CampusAgentPost::query()
            ->with(['author:id,name', 'campus:id,name'])
            ->orderByDesc('published_at')
            ->paginate(20);

        return ApiResponse::success([
            'items' => collect($posts->items())->map(fn (CampusAgentPost $p) => [
                'public_id' => $p->public_id,
                'title' => $p->title,
                'content' => $p->content,
                'category_code' => $p->category_code,
                'status' => $p->status,
                'author_name' => $p->author?->name,
                'campus_name' => $p->campus?->name,
                'view_count' => $p->view_count,
                'published_at' => optional($p->published_at)->toIso8601String(),
            ]),
            'pagination' => [
                'page' => $posts->currentPage(),
                'page_size' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /** 隐藏/恢复某帖 */
    public function togglePost(string $publicId, Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['published', 'hidden'])]]);
        $post = CampusAgentPost::where('public_id', $publicId)->first();
        abort_unless($post !== null, 404);
        $post->update(['status' => $data['status']]);

        return ApiResponse::success(['public_id' => $post->public_id, 'status' => $post->status]);
    }
}
