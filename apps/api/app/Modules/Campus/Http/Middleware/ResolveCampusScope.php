<?php

namespace App\Modules\Campus\Http\Middleware;

use App\Modules\Campus\Models\Campus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 统一校区口径中间件（P0 核心）
 *
 * 解析顺序：
 * 1. 已登录用户：SCOPE = user.campus_id（D1，忽略 X-Campus-Id 头）
 * 2. 匿名访客：沿用 X-Campus-Id 头解析（D2）
 * 3. 无 SCOPE：fail-close，业务查询返回空集
 *
 * 写入 request attributes：
 * - campus_database_id：兼容现有读取点
 * - campus_scope_authenticated：是否登录
 * - campus_scope_bound：登录用户是否已绑校
 * - campus_strict：该校是否 strict 模式
 * - campus_public_id：校区 public_id（供响应回显）
 */
class ResolveCampusScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $bound = false;
        $campusDbId = null;

        if ($user !== null) {
            // D1：登录态只认服务端绑定校，忽略 X-Campus-Id
            $campusDbId = $user->campus_id !== null ? (int) $user->campus_id : null;
            $bound = $campusDbId !== null;
        } else {
            // D2：匿名按请求头解析（原有 ResolveCampusContext 逻辑迁移至此）
            $campusDbId = $this->resolveFromHeader($request);
        }

        $campus = $campusDbId !== null ? Campus::query()->find($campusDbId) : null;

        $request->attributes->set('campus_database_id', $campusDbId);
        $request->attributes->set('campus_scope_authenticated', $user !== null);
        $request->attributes->set('campus_scope_bound', $bound);
        $strict = $campus !== null && $campus->isolation_mode === 'strict';
        $request->attributes->set('campus_strict', $strict);
        // 兼容现有管理端中间件 EnforceAdminCampusScope 的旧属性名
        $request->attributes->set('campus_context_strict', $strict);
        if ($campus !== null) {
            $request->attributes->set('campus_public_id', $campus->public_id);
        }

        return $next($request);
    }

    /**
     * 匿名场景：从 X-Campus-Id 头解析校区 id
     */
    private function resolveFromHeader(Request $request): ?int
    {
        $publicId = trim((string) $request->header('X-Campus-Id', ''));
        if ($publicId === '') {
            return null;
        }

        $campus = Campus::query()
            ->where('public_id', $publicId)
            ->where('status', 'active')
            ->first(['id']);

        return $campus !== null ? (int) $campus->id : null;
    }
}
