<?php

namespace App\Modules\Major\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Campus\Models\Campus;
use App\Modules\Major\Models\CampusMajor;
use App\Modules\Major\Models\Major;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 专业目录：供绑校/换校流程联想选择，兼容自由输入。
 *
 * 目录本身是全国统一的本科专业目录，不区分学校；绑定学校后仅按学校类型
 * 把相关学科门类排到前面（例如农业类院校优先展示农学门类），方便就近选择。
 */
class MajorController extends Controller
{
    private const CATEGORIES = [
        '哲学', '经济学', '法学', '教育学', '文学', '历史学',
        '理学', '工学', '农学', '医学', '管理学', '艺术学',
    ];

    /**
     * 学校名称关键词 => 优先展示的学科门类（按具体程度从高到低匹配）。
     */
    private const SCHOOL_CATEGORY_HINTS = [
        '中医药' => ['医学', '理学'],
        '兽医' => ['农学'],
        '水产' => ['农学', '理学'],
        '农林' => ['农学', '理学', '工学', '管理学'],
        '农业' => ['农学', '理学', '工学', '管理学'],
        '林业' => ['农学', '理学', '工学'],
        '海洋' => ['农学', '理学', '工学'],
        '医科' => ['医学'],
        '医学' => ['医学'],
        '药科' => ['医学'],
        '师范' => ['教育学', '文学', '理学'],
        '体育' => ['教育学'],
        '财经' => ['经济学', '管理学'],
        '工商' => ['管理学', '经济学'],
        '经济' => ['经济学', '管理学'],
        '政法' => ['法学'],
        '公安' => ['法学'],
        '警察' => ['法学'],
        '理工' => ['工学', '理学'],
        '工业' => ['工学'],
        '工程' => ['工学'],
        '科技' => ['工学', '理学'],
        '信息' => ['工学'],
        '电子' => ['工学'],
        '邮电' => ['工学'],
        '交通' => ['工学'],
        '航空' => ['工学'],
        '航天' => ['工学'],
        '铁道' => ['工学'],
        '建筑' => ['工学'],
        '水利' => ['工学'],
        '电力' => ['工学'],
        '石油' => ['工学'],
        '矿业' => ['工学'],
        '地质' => ['理学', '工学'],
        '纺织' => ['工学'],
        '服装' => ['工学', '艺术学'],
        '艺术' => ['艺术学'],
        '美术' => ['艺术学'],
        '音乐' => ['艺术学'],
        '戏剧' => ['艺术学'],
        '传媒' => ['文学', '艺术学'],
        '外国语' => ['文学'],
        '外语' => ['文学'],
        '民族' => ['法学', '文学'],
        '旅游' => ['管理学'],
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:60'],
            'category' => ['sometimes', 'string', Rule::in(self::CATEGORIES)],
            'campus_id' => ['sometimes', 'string', 'max:64'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $keyword = isset($data['keyword']) ? trim($data['keyword']) : '';
        $hasKeyword = $keyword !== '';
        $limit = (int) ($data['limit'] ?? 200);
        $campusPublicId = isset($data['campus_id']) ? trim((string) $data['campus_id']) : null;

        // 已导入学校—专业对照数据时，优先只返回该校开设的专业
        $campus = $campusPublicId
            ? Campus::query()->where('public_id', $campusPublicId)->first(['id', 'name'])
            : null;
        if ($campus !== null) {
            $campusPayload = $this->campusMajorPayload($campus, $data, $keyword, $limit);
            if ($campusPayload !== null) {
                return ApiResponse::success($campusPayload);
            }
        }

        $recommended = $this->recommendedCategoriesForName($campus?->name);
        $priorityRank = array_flip($recommended);

        $query = Major::query()
            ->where('is_active', true)
            ->when(isset($data['category']), fn ($q) => $q->where('category', $data['category']))
            ->when($hasKeyword, function ($q) use ($keyword): void {
                $like = '%'.$keyword.'%';
                $q->where(function ($nested) use ($like): void {
                    $nested->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like);
                });
            });

        $sorted = $query
            ->get(['code', 'name', 'category', 'category_sort', 'sort_order'])
            ->sortBy(function (Major $major) use ($hasKeyword, $keyword, $priorityRank): string {
                // 搜索词完全命中 > 学校推荐门类 > 门类顺序 > 门类内排序
                return sprintf(
                    '%d-%05d-%03d-%03d-%s',
                    $hasKeyword && $major->name === $keyword ? 0 : 1,
                    $priorityRank[$major->category] ?? 99999,
                    $major->category_sort,
                    $major->sort_order,
                    $major->name,
                );
            })
            ->values();

        $items = $sorted
            ->take($limit)
            ->map(fn (Major $major) => [
                'code' => $major->code,
                'name' => $major->name,
                'category' => $major->category,
            ])
            ->values();

        // 无搜索词时按门类分组供完整浏览：分组取全量，不受 limit 截断
        $grouped = null;
        if (! $hasKeyword) {
            $grouped = collect(self::CATEGORIES)
                ->map(function (string $category) use ($sorted): array {
                    $matches = $sorted->where('category', $category);

                    return [
                        'category' => $category,
                        'items' => $matches
                            ->map(fn (Major $major) => ['code' => $major->code, 'name' => $major->name])
                            ->values(),
                    ];
                })
                ->filter(fn (array $group) => $group['items']->isNotEmpty())
                ->sortBy(fn (array $group) => $priorityRank[$group['category']] ?? 99999)
                ->values();
        }

        return ApiResponse::success([
            'items' => $items,
            'groups' => $grouped,
            'categories' => self::CATEGORIES,
            'recommended_categories' => $recommended,
            'source' => 'catalog',
            'campus_name' => $campus?->name,
        ]);
    }

    /**
     * 学校维度专业清单；该校尚未导入对照数据时返回 null，走全国专业目录兜底。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function campusMajorPayload(Campus $campus, array $data, string $keyword, int $limit): ?array
    {
        $base = CampusMajor::query()->where('campus_id', $campus->id);
        if (! (clone $base)->exists()) {
            return null;
        }

        $priorityRank = array_flip($this->recommendedCategoriesForName($campus->name));

        $all = $base
            ->when(isset($data['category']), fn ($q) => $q->where('category', $data['category']))
            ->when($keyword !== '', function ($q) use ($keyword): void {
                $like = '%'.$keyword.'%';
                $q->where(function ($nested) use ($like): void {
                    $nested->where('major_name', 'like', $like)
                        ->orWhere('major_code', 'like', $like);
                });
            })
            ->get(['major_name', 'major_code', 'category', 'level', 'sort_order'])
            ->sortBy(fn (CampusMajor $major) => sprintf(
                '%05d-%05d-%s',
                $priorityRank[$major->category] ?? 99999,
                $major->sort_order,
                $major->major_name,
            ))
            ->values();

        $items = $all
            ->take($limit)
            ->map(fn (CampusMajor $major) => [
                'code' => (string) ($major->major_code ?? ''),
                'name' => $major->major_name,
                'category' => $major->category,
                'level' => $major->level,
            ])
            ->values();

        $grouped = null;
        if ($keyword === '') {
            $grouped = $all
                ->groupBy(fn (CampusMajor $major) => $major->category ?: '其他')
                ->map(fn ($rows, string $category) => [
                    'category' => $category,
                    'items' => $rows
                        ->map(fn (CampusMajor $major) => [
                            'code' => (string) ($major->major_code ?? ''),
                            'name' => $major->major_name,
                        ])
                        ->values(),
                ])
                ->values();
        }

        return [
            'items' => $items,
            'groups' => $grouped,
            'categories' => $all->pluck('category')->filter()->unique()->values()->all(),
            'recommended_categories' => [],
            'source' => 'campus',
            'campus_name' => $campus->name,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function recommendedCategoriesForName(?string $campusName): array
    {
        if ($campusName === null || $campusName === '') {
            return [];
        }

        foreach (self::SCHOOL_CATEGORY_HINTS as $keyword => $categories) {
            if (str_contains($campusName, $keyword)) {
                return $categories;
            }
        }

        return [];
    }
}
