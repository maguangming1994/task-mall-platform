<?php

namespace App\Modules\Poster\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Poster\Models\PosterTemplate;
use App\Modules\Poster\Services\PosterTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminPosterTemplateController extends Controller
{
    public function __construct(private readonly PosterTemplateService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'is_active' => ['nullable', Rule::in(['0', '1'])],
        ]);

        $query = PosterTemplate::query();
        if (! empty($data['code'])) {
            $query->where('code', $data['code']);
        }
        if (isset($data['is_active'])) {
            $query->where('is_active', (bool) $data['is_active']);
        }

        $items = $query->orderBy('code')->orderByDesc('priority')->orderBy('id')
            ->get()
            ->map(fn (PosterTemplate $t) => $this->serializeAdmin($t));

        return ApiResponse::success(['items' => $items]);
    }

    public function show(PosterTemplate $template): JsonResponse
    {
        return ApiResponse::success(['template' => $this->serializeAdmin($template)]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();

        $data = $request->validate($this->rules());
        $data['created_by_admin_id'] = $admin?->id;
        $data['updated_by_admin_id'] = $admin?->id;
        $data['public_id'] = (string) Str::ulid();

        $this->assertDateRuleValid($data['date_rule'] ?? null);
        $this->normalizeBackgroundValue($data);
        $this->resolveDefaultFlag($data);

        $template = DB::transaction(function () use ($data): PosterTemplate {
            if (! empty($data['is_default'])) {
                PosterTemplate::query()->where('code', $data['code'])->update(['is_default' => false]);
            }

            return PosterTemplate::query()->create($data);
        });

        $this->service->forgetResolved($template->code);

        return ApiResponse::success(['template' => $this->serializeAdmin($template)], 201);
    }

    public function update(Request $request, PosterTemplate $template): JsonResponse
    {
        $admin = $request->user();

        $data = $request->validate($this->rules(true));
        $data['updated_by_admin_id'] = $admin?->id;

        $this->assertDateRuleValid($data['date_rule'] ?? $template->date_rule);
        $this->normalizeBackgroundValue($data);

        DB::transaction(function () use ($data, $template): void {
            if (! empty($data['is_default'])) {
                PosterTemplate::query()->where('code', $template->code)->whereKeyNot($template->id)->update(['is_default' => false]);
            }
            $template->update($data);
        });

        $this->service->forgetResolved($template->code);

        return ApiResponse::success(['template' => $this->serializeAdmin($template->fresh())]);
    }

    public function destroy(PosterTemplate $template): JsonResponse
    {
        $code = $template->code;
        $wasDefault = (bool) $template->is_default;
        $template->delete();

        // 如果删的是默认模板，把同 code 下第一个活跃模板提拔为默认
        if ($wasDefault) {
            $nextDefault = PosterTemplate::query()
                ->where('code', $code)
                ->where('is_active', true)
                ->orderByDesc('priority')
                ->first();
            if ($nextDefault) {
                $nextDefault->update(['is_default' => true]);
            }
        }

        $this->service->forgetResolved($code);

        return ApiResponse::success(['deleted' => true]);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:64', Rule::in([
                PosterTemplate::CODE_COMMUNITY_POST,
                PosterTemplate::CODE_MATERIAL_SHARE,
            ])],
            'name' => [$required, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'canvas_width' => ['nullable', 'integer', 'min:100', 'max:1000'],
            'canvas_height' => ['nullable', 'integer', 'min:100', 'max:1500'],
            'background_type' => [$required, Rule::in([
                PosterTemplate::BACKGROUND_GRADIENT,
                PosterTemplate::BACKGROUND_IMAGE,
                PosterTemplate::BACKGROUND_SOLID,
            ])],
            'background_value' => ['nullable', 'array'],
            'banner_config' => ['nullable', 'array'],
            'body_config' => ['nullable', 'array'],
            'qrcode_config' => ['nullable', 'array'],
            'date_rule' => ['nullable', 'array'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function assertDateRuleValid(?array $rule): void
    {
        if ($rule === null || $rule === []) {
            return;
        }
        $mode = $rule['mode'] ?? null;
        if ($mode === null) {
            throw new ValidationException(validator([], [])->errors()->add('date_rule.mode', '日期规则缺少 mode 字段。'));
        }
        if (! in_array($mode, ['range', 'monthday', 'weekday'], true)) {
            throw new ValidationException(validator([], [])->errors()->add('date_rule.mode', '日期规则 mode 非法。'));
        }

        if ($mode === 'range') {
            $ranges = $rule['ranges'] ?? [];
            foreach ($ranges as $i => $range) {
                if (empty($range['start']) || empty($range['end'])) {
                    throw new ValidationException(validator([], [])->errors()->add("date_rule.ranges.$i", '每条范围必须同时包含 start 和 end。'));
                }
            }
        }
    }

    private function normalizeBackgroundValue(array &$data): void
    {
        $type = $data['background_type'] ?? null;
        if ($type === null) {
            return;
        }
        $value = $data['background_value'] ?? null;
        if ($value === null) {
            return;
        }

        if ($type === PosterTemplate::BACKGROUND_GRADIENT && empty($value['stops'])) {
            unset($data['background_value']);
        }
        if ($type === PosterTemplate::BACKGROUND_IMAGE && empty($value['image_url'])) {
            unset($data['background_value']);
        }
        if ($type === PosterTemplate::BACKGROUND_SOLID && empty($value['color'])) {
            unset($data['background_value']);
        }
    }

    private function resolveDefaultFlag(array &$data): void
    {
        // 创建时若没有指定 is_default，且同 code 下没有默认模板，自动标记
        if (! array_key_exists('is_default', $data)) {
            $hasDefault = PosterTemplate::query()->where('code', $data['code'] ?? '')->where('is_default', true)->exists();
            if (! $hasDefault) {
                $data['is_default'] = true;
            } else {
                $data['is_default'] = false;
            }
        }
    }

    private function serializeAdmin(PosterTemplate $t): array
    {
        return [
            'id' => $t->id,
            'public_id' => $t->public_id,
            'code' => $t->code,
            'name' => $t->name,
            'description' => $t->description,
            'canvas_width' => (int) $t->canvas_width,
            'canvas_height' => (int) $t->canvas_height,
            'background_type' => $t->background_type,
            'background_value' => $t->background_value,
            'banner_config' => $t->banner_config,
            'body_config' => $t->body_config,
            'qrcode_config' => $t->qrcode_config,
            'date_rule' => $t->date_rule,
            'priority' => (int) $t->priority,
            'is_default' => (bool) $t->is_default,
            'is_active' => (bool) $t->is_active,
            'created_at' => $t->created_at?->toDateTimeString(),
            'updated_at' => $t->updated_at?->toDateTimeString(),
        ];
    }
}
