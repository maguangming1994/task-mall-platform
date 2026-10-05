<?php

namespace App\Modules\Operations\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Modules\Operations\Services\ModuleGateService;
use App\Modules\Operations\Support\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 模块后端门禁：module.gate:{code}
 * 关闭（含父链关闭、全局总闸门关闭）时返回 403 MODULE_DISABLED；
 * 判定异常时 fail-close，避免绕过前端直接访问被关模块。
 */
class RequireModuleEnabled
{
    public function __construct(
        private readonly ModuleGateService $gates,
        private readonly ModuleRegistry $registry,
    ) {}

    public function handle(Request $request, Closure $next, string $code): Response
    {
        try {
            $enabled = $this->registry->exists($code) && $this->gates->effectiveForRequest($request, $code);
        } catch (Throwable $exception) {
            Log::error('模块门禁判定失败，按关闭处理', [
                'code' => $code,
                'exception' => $exception->getMessage(),
            ]);
            $enabled = false;
        }

        if (! $enabled) {
            return ApiResponse::error(
                'MODULE_DISABLED',
                ($this->registry->name($code) ?: '该功能').'当前未开放，敬请期待。',
                403,
                ['module' => $code],
            );
        }

        return $next($request);
    }
}
