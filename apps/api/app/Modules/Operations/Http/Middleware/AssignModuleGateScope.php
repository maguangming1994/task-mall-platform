<?php

namespace App\Modules\Operations\Http\Middleware;

use App\Modules\Operations\Services\ModuleGateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 为每个 HTTP 请求写入唯一的模块门禁 memo 作用域令牌。
 *
 * ModuleGateService 的请求内 memo 依赖本令牌在请求边界作废：
 * Laravel Route 会缓存控制器实例，注入的 gate 服务跨请求复用；
 * 不能用 spl_object_id($request) 区分请求（对象句柄析构后会被回收复用），
 * 故在中间件管道最早阶段为每个请求生成随机令牌。
 */
class AssignModuleGateScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(
            ModuleGateService::REQUEST_SCOPE_ATTRIBUTE,
            bin2hex(random_bytes(16)),
        );

        return $next($request);
    }
}
