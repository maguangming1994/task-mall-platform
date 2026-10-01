<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\IconConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 小程序图标配置管理：后台维护图标图形、展示文字、配色与启停状态。
 */
class IconConfigController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = IconConfig::query()->orderBy('sort_order')->orderBy('id');

        if ($request->has('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $items = $query->get();

        return ApiResponse::success(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:icon_configs,code'],
            'name' => ['required', 'string', 'max:32'],
            'category' => ['required', 'string', Rule::in(['tabbar', 'module', 'order', 'community', 'service', 'common'])],
            'svg_path' => ['required', 'string', 'max:255'],
            'fallback_char' => ['nullable', 'string', 'max:8'],
            'label' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:64'],
            'tone' => ['nullable', 'string', Rule::in(['default', 'yellow', 'cyan', 'green', 'blue', 'purple', 'red'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        $item = IconConfig::create($validated);

        return ApiResponse::success(['item' => $item], 201);
    }

    public function show(IconConfig $iconConfig): JsonResponse
    {
        return ApiResponse::success(['item' => $iconConfig]);
    }

    public function update(Request $request, IconConfig $iconConfig): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:32'],
            'category' => ['sometimes', 'string', Rule::in(['tabbar', 'module', 'order', 'community', 'service', 'common'])],
            'svg_path' => ['sometimes', 'string', 'max:255'],
            'fallback_char' => ['nullable', 'string', 'max:8'],
            'label' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:64'],
            'tone' => ['nullable', 'string', Rule::in(['default', 'yellow', 'cyan', 'green', 'blue', 'purple', 'red'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        $iconConfig->update($validated);

        return ApiResponse::success(['item' => $iconConfig->fresh()]);
    }

    public function destroy(IconConfig $iconConfig): JsonResponse
    {
        $iconConfig->delete();

        return ApiResponse::success(['message' => '已删除']);
    }
}
