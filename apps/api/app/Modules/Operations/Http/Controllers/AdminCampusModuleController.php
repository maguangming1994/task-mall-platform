<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campus\Models\Campus;
use App\Modules\Operations\Models\CampusFeatureFlag;
use App\Modules\Operations\Models\FeatureFlag;
use App\Modules\Operations\Services\ModuleGateService;
use App\Modules\Operations\Services\ReleaseControlService;
use App\Modules\Operations\Support\ModulePackage;
use App\Modules\Operations\Support\ModuleRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 校区功能模块矩阵：平台超管（campus.manage）可写，学校代理只读。
 */
class AdminCampusModuleController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModulePackage $packages,
        private readonly ModuleGateService $gates,
        private readonly ReleaseControlService $releases,
    ) {}

    /**
     * 校区功能模块矩阵（全局状态 / 校区覆盖 / 最终生效 / 套餐目录）。
     */
    public function show(Campus $campus): JsonResponse
    {
        return ApiResponse::success($this->matrix($campus));
    }

    /**
     * 调整单个模块的校区覆盖（inherit/enabled/disabled），带乐观锁。
     */
    public function update(Request $request, Campus $campus, string $code, AuditLogger $audit): JsonResponse
    {
        if (! $this->registry->exists($code)) {
            throw ValidationException::withMessages(['code' => ['未知的功能模块。']]);
        }
        $data = $request->validate([
            'status' => ['required', 'in:inherit,enabled,disabled'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        /** @var AdminUser $actor */
        $actor = $request->user();

        $result = DB::transaction(function () use ($campus, $code, $data, $actor, $audit): array {
            $row = CampusFeatureFlag::query()
                ->where('campus_id', $campus->id)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();
            $currentVersion = (int) ($row?->version ?? 0);
            if ((int) $data['expected_version'] !== $currentVersion) {
                throw ValidationException::withMessages([
                    'expected_version' => ['模块配置已被他人修改，请刷新矩阵后重试。'],
                ])->status(422);
            }

            // 目标状态与现状一致：幂等返回，不写审计
            $targetStatus = $data['status'];
            if (($row === null && $targetStatus === 'inherit') || ($row !== null && $row->status === $targetStatus)) {
                return ['changed' => false];
            }

            $before = $row === null
                ? ['code' => $code, 'status' => 'inherit', 'version' => 0]
                : ['code' => $code, 'status' => $row->status, 'version' => (int) $row->version];

            if ($targetStatus === 'inherit') {
                $row->delete();
                $after = ['code' => $code, 'status' => 'inherit', 'version' => 0];
            } elseif ($row === null) {
                try {
                    CampusFeatureFlag::query()->create([
                        'campus_id' => $campus->id,
                        'code' => $code,
                        'status' => $targetStatus,
                        'version' => 1,
                        'updated_by_admin_id' => $actor->id,
                    ]);
                } catch (QueryException $exception) {
                    // 两人同时首配：行锁锁不住插入，并发事务撞 (campus_id, code) 唯一索引
                    if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                        throw ValidationException::withMessages([
                            'expected_version' => ['模块配置已被他人修改，请刷新矩阵后重试。'],
                        ])->status(422);
                    }
                    throw $exception;
                }
                $after = ['code' => $code, 'status' => $targetStatus, 'version' => 1];
            } else {
                $row->update([
                    'status' => $targetStatus,
                    'version' => $currentVersion + 1,
                    'updated_by_admin_id' => $actor->id,
                ]);
                $after = ['code' => $code, 'status' => $targetStatus, 'version' => $currentVersion + 1];
            }

            $audit->record(
                $actor,
                'campus_module.updated',
                'campus_module',
                $campus->public_id.':'.$code,
                $before,
                $after,
                $data['reason'] ?? null,
            );

            return ['changed' => true];
        });

        if ($result['changed']) {
            $this->gates->forgetCampus($campus->id);
        }

        return ApiResponse::success($this->matrix($campus), 200, '功能模块配置已更新。');
    }

    /**
     * 批量应用阶段套餐（basic/standard/full），整体事务 + 一条汇总审计。
     */
    public function applyPackage(Request $request, Campus $campus, string $package, AuditLogger $audit): JsonResponse
    {
        if (! $this->packages->exists($package)) {
            throw ValidationException::withMessages(['package' => ['未知的功能套餐。']]);
        }
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        /** @var AdminUser $actor */
        $actor = $request->user();

        DB::transaction(function () use ($campus, $package, $data, $actor, $audit): void {
            $existing = CampusFeatureFlag::query()
                ->where('campus_id', $campus->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('code');
            $before = $existing->map(fn (CampusFeatureFlag $flag): array => [
                'status' => $flag->status,
                'version' => (int) $flag->version,
            ])->all();

            // full：清空全部覆盖行（全部 inherit 跟随全局）；其余套餐按目标状态 upsert
            $targets = $this->packages->targetStatuses($package);
            if ($targets === []) {
                CampusFeatureFlag::query()->where('campus_id', $campus->id)->delete();
            } else {
                foreach ($this->registry->codes() as $code) {
                    $targetStatus = $targets[$code];
                    $row = $existing->get($code);
                    if ($row === null) {
                        CampusFeatureFlag::query()->create([
                            'campus_id' => $campus->id,
                            'code' => $code,
                            'status' => $targetStatus,
                            'version' => 1,
                            'updated_by_admin_id' => $actor->id,
                        ]);
                    } elseif ($row->status !== $targetStatus) {
                        $row->update([
                            'status' => $targetStatus,
                            'version' => (int) $row->version + 1,
                            'updated_by_admin_id' => $actor->id,
                        ]);
                    }
                }
            }

            $after = CampusFeatureFlag::query()
                ->where('campus_id', $campus->id)
                ->pluck('status', 'code')
                ->all();

            $audit->record(
                $actor,
                'campus_module_package.applied',
                'campus_module',
                $campus->public_id.':'.$package,
                ['package' => $package, 'modules' => $before],
                ['package' => $package, 'modules' => $after],
                $data['reason'] ?? null,
            );
        });

        $this->gates->forgetCampus($campus->id);

        return ApiResponse::success($this->matrix($campus), 200, '套餐已应用：'.$this->packages->name($package).'。');
    }

    /**
     * @return array<string, mixed>
     */
    private function matrix(Campus $campus): array
    {
        $globalFlags = FeatureFlag::query()
            ->whereIn('code', $this->registry->codes())
            ->get()
            ->keyBy('code');
        $overrides = CampusFeatureFlag::query()
            ->with('updatedByAdmin')
            ->where('campus_id', $campus->id)
            ->get()
            ->keyBy('code');

        $modules = [];
        foreach ($this->registry->all() as $code => $meta) {
            $globalFlag = $globalFlags->get($code);
            $evaluation = $this->releases->evaluation($code, 'anonymous', null);
            $override = $overrides->get($code);

            $modules[] = [
                'code' => $code,
                'name' => $meta['name'],
                'group' => $meta['group'],
                'parent' => $meta['parent'],
                'status' => $override?->status ?? 'inherit',
                'version' => (int) ($override?->version ?? 0),
                'global' => [
                    // enabled/disabled/percentage/missing（记录缺失）
                    'mode' => $evaluation['mode'] ?? 'missing',
                    'enabled' => (bool) $evaluation['enabled'],
                    'rollout_percentage' => $globalFlag?->rollout_percentage,
                ],
                // 以匿名设备为基准的最终生效预览（percentage 灰度以匿名哈希为准）
                'effective' => $this->gates->effective($code, $campus->id, 'anonymous', null),
                'updated_by_admin' => $override?->updatedByAdmin === null ? null : [
                    'id' => $override->updatedByAdmin->public_id,
                    'display_name' => $override->updatedByAdmin->display_name,
                ],
                'updated_at' => $override?->updated_at?->toIso8601String(),
            ];
        }

        return [
            'campus' => [
                'id' => $campus->public_id,
                'name' => $campus->name,
                'status' => $campus->status,
            ],
            'groups' => $this->registry->groups(),
            'modules' => $modules,
            'packages' => array_map(
                fn (string $code): array => [
                    'code' => $code,
                    'name' => $this->packages->name($code),
                    'description' => $this->packages->catalog()[$code]['description'],
                    'modules' => $this->packages->catalog()[$code]['modules'],
                ],
                $this->packages->codes(),
            ),
        ];
    }
}
