<?php

namespace App\Modules\Campus\Http\Middleware;

use App\Common\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L1 绑定校验中间件：已登录但未绑校或档案不完整 → 403
 */
class RequireBoundCampus
{
    public function handle(Request $request, Closure $next): Response
    {
        $bound = (bool) $request->attributes->get('campus_scope_bound', false);
        if (! $bound) {
            return ApiResponse::error(
                'CAMPUS_NOT_BOUND',
                '请先绑定学校。',
                403,
                ['select_url' => '/api/v1/campuses'],
            );
        }

        // 档案完整性检查：已绑校但 grade_year/major 为空
        $user = $request->user();
        if ($user !== null && ($user->grade_year === null || $user->major === null)) {
            return ApiResponse::error(
                'CAMPUS_PROFILE_INCOMPLETE',
                '请补全年级和专业信息。',
                403,
                ['missing_fields' => array_filter([
                    $user->grade_year === null ? 'grade_year' : null,
                    $user->major === null ? 'major' : null,
                ]), 'bind_url' => '/api/v1/me/campus'],
            );
        }

        return $next($request);
    }
}
