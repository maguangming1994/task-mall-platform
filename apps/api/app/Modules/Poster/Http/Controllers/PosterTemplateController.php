<?php

namespace App\Modules\Poster\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Poster\Services\PosterTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PosterTemplateController extends Controller
{
    public function __construct(private readonly PosterTemplateService $service) {}

    /**
     * 前端用户请求某业务场景下的海报模板。
     * 后端根据当前日期自动匹配；调用方可传 date 参数覆盖（调试/预览用）。
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $dateInput = $request->input('date');
        $date = $dateInput ? Carbon::parse($dateInput) : Carbon::now();

        $template = $this->service->resolve($code, $date);
        if ($template === null) {
            return ApiResponse::error('POSTER_TEMPLATE_NOT_FOUND', '未找到可用海报模板。', 404);
        }

        return ApiResponse::success([
            'template' => $template->toPublicArray(),
            'matched_date' => $date->toDateString(),
            'is_date_specific' => $template->date_rule !== null,
        ]);
    }
}
