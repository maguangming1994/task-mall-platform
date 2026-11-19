<?php

namespace App\Modules\Major\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Major\Models\Major;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 专业目录：供绑校/换校流程联想选择，兼容自由输入。
 */
class MajorController extends Controller
{
    private const CATEGORIES = [
        '哲学', '经济学', '法学', '教育学', '文学', '历史学',
        '理学', '工学', '农学', '医学', '管理学', '艺术学',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:60'],
            'category' => ['sometimes', 'string', Rule::in(self::CATEGORIES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Major::query()->where('is_active', true);

        if (isset($data['category'])) {
            $query->where('category', $data['category']);
        }

        if (isset($data['keyword']) && $data['keyword'] !== '') {
            $keyword = '%'.$data['keyword'].'%';
            $query->where(function ($nested) use ($keyword): void {
                $nested->where('name', 'like', $keyword)
                    ->orWhere('code', 'like', $keyword);
            })->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$data['keyword']]);
        }

        $limit = (int) ($data['limit'] ?? 200);
        $hasKeyword = isset($data['keyword']) && $data['keyword'] !== '';

        $items = $query
            ->orderBy('category_sort')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'category'])
            ->map(fn (Major $major) => [
                'code' => $major->code,
                'name' => $major->name,
                'category' => $major->category,
            ])
            ->values();

        // 无搜索词时按门类分组供完整浏览：分组取全量，不受 limit 截断
        $grouped = null;
        if (! $hasKeyword) {
            $all = Major::query()
                ->where('is_active', true)
                ->when(isset($data['category']), fn ($q) => $q->where('category', $data['category']))
                ->orderBy('category_sort')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['code', 'name', 'category'])
                ->map(fn (Major $major) => [
                    'code' => $major->code,
                    'name' => $major->name,
                    'category' => $major->category,
                ]);

            $grouped = collect(self::CATEGORIES)
                ->map(function (string $category) use ($all): array {
                    $matches = $all->where('category', $category);

                    return [
                        'category' => $category,
                        'items' => $matches
                            ->map(fn (array $major) => ['code' => $major['code'], 'name' => $major['name']])
                            ->values(),
                    ];
                })
                ->filter(fn (array $group) => $group['items']->isNotEmpty())
                ->values();
        }

        return ApiResponse::success([
            'items' => $items,
            'groups' => $grouped,
            'categories' => self::CATEGORIES,
        ]);
    }
}
