<?php

namespace App\Modules\Operations\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Operations\Models\DeploymentRelease;
use App\Modules\Operations\Models\FeatureFlag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReleaseControlService
{
    public function __construct(private readonly AuditLogger $audit, private readonly ProductionReadinessService $productionReadiness) {}

    public function updateFlag(AdminUser $admin, FeatureFlag $flag, string $mode, int $percentage, array $audience, int $expectedVersion, string $reason, ?array $placeholder = null): FeatureFlag
    {
        if (! in_array($mode, ['disabled', 'percentage', 'enabled'], true) || $percentage < 0 || $percentage > 100) {
            throw ValidationException::withMessages(['mode' => '灰度模式或比例无效。']);
        }
        if (($mode === 'disabled' && $percentage !== 0) || ($mode === 'enabled' && $percentage !== 100) || ($mode === 'percentage' && ($percentage < 1 || $percentage > 99))) {
            throw ValidationException::withMessages(['rollout_percentage' => '模式与灰度比例不匹配。']);
        }

        return DB::transaction(function () use ($admin, $flag, $mode, $percentage, $audience, $expectedVersion, $reason, $placeholder): FeatureFlag {
            $locked = FeatureFlag::query()->lockForUpdate()->findOrFail($flag->id);
            if ($locked->version !== $expectedVersion) {
                throw ValidationException::withMessages(['expected_version' => '功能开关已被其他管理员更新，请刷新后重试。']);
            }
            $before = $locked->only(['mode', 'rollout_percentage', 'audience', 'version', 'placeholder_title_reviewing', 'placeholder_desc_reviewing', 'placeholder_title_reviewed', 'placeholder_desc_reviewed']);
            $version = $locked->version + 1;
            $locked->versions()->create(['version' => $version, 'mode' => $mode, 'rollout_percentage' => $percentage, 'audience' => $audience, 'reason' => $reason, 'created_by_admin_id' => $admin->id]);
            $attributes = ['mode' => $mode, 'rollout_percentage' => $percentage, 'audience' => $audience, 'version' => $version, 'updated_by_admin_id' => $admin->id];
            if ($placeholder !== null) {
                $attributes['placeholder_title_reviewing'] = $placeholder['title_reviewing'] ?? null;
                $attributes['placeholder_desc_reviewing'] = $placeholder['desc_reviewing'] ?? null;
                $attributes['placeholder_title_reviewed'] = $placeholder['title_reviewed'] ?? null;
                $attributes['placeholder_desc_reviewed'] = $placeholder['desc_reviewed'] ?? null;
            }
            $locked->update($attributes);
            $this->audit->record($admin, 'feature_flag.updated', 'feature_flag', $locked->code, $before, $locked->only(['mode', 'rollout_percentage', 'audience', 'version', 'placeholder_title_reviewing', 'placeholder_desc_reviewing', 'placeholder_title_reviewed', 'placeholder_desc_reviewed']), $reason);

            return $locked->fresh();
        });
    }

    /**
     * 返回各模块当前审核状态下的占位文案。
     *
     * @return array<string, array{title: string, desc: string}>
     */
    public function modulePlaceholders(string $reviewStatus): array
    {
        $titleField = $reviewStatus === 'reviewing' ? 'placeholder_title_reviewing' : 'placeholder_title_reviewed';
        $descField = $reviewStatus === 'reviewing' ? 'placeholder_desc_reviewing' : 'placeholder_desc_reviewed';

        return FeatureFlag::query()
            ->where('code', 'like', 'module_%')
            ->get(['code', $titleField, $descField])
            ->mapWithKeys(function (FeatureFlag $flag) use ($titleField, $descField): array {
                $title = $flag->getAttribute($titleField) ?: '功能升级中';
                $desc = $flag->getAttribute($descField) ?: '该功能正在升级优化，稍作等待，精彩马上回来。';

                return [$flag->code => ['title' => $title, 'desc' => $desc]];
            })
            ->all();
    }

    public function enabled(string $code, string $subject, ?string $platform = null): bool
    {
        return $this->evaluation($code, $subject, $platform)['enabled'];
    }

    /**
     * 全局开关判定的单一实现：同时返回模式与布尔结果。
     * mode=null 表示开关记录缺失（按关闭处理，且校区覆盖不可开启）。
     *
     * @return array{mode: ?string, enabled: bool}
     */
    public function evaluation(string $code, string $subject, ?string $platform = null): array
    {
        $flag = FeatureFlag::query()->where('code', $code)->first();
        if (! $flag || $flag->mode === 'disabled') {
            return ['mode' => $flag?->mode, 'enabled' => false];
        }
        if ($flag->mode === 'enabled') {
            return ['mode' => 'enabled', 'enabled' => true];
        }
        $audience = $flag->audience ?? [];
        if (! empty($audience['platforms']) && ! in_array($platform, $audience['platforms'], true)) {
            return ['mode' => 'percentage', 'enabled' => false];
        }
        if (in_array($subject, $audience['subject_allowlist'] ?? [], true)) {
            return ['mode' => 'percentage', 'enabled' => true];
        }

        return [
            'mode' => 'percentage',
            'enabled' => hexdec(substr(hash('sha256', $code.':'.$subject), 0, 8)) % 100 < $flag->rollout_percentage,
        ];
    }

    public function registerRelease(string $version, string $commitSha, string $artifactHash, string $configurationHash, ?AdminUser $admin = null): DeploymentRelease
    {
        foreach (['commit_sha' => [$commitSha, 40], 'artifact_hash' => [$artifactHash, 64], 'configuration_hash' => [$configurationHash, 64]] as $field => [$value, $length]) {
            if (! preg_match('/^[a-f0-9]{'.$length.'}$/', $value)) {
                throw ValidationException::withMessages([$field => '摘要格式无效。']);
            }
        }

        return DB::transaction(function () use ($version, $commitSha, $artifactHash, $configurationHash, $admin): DeploymentRelease {
            $existing = DeploymentRelease::query()->where('release_version', $version)->first();
            if ($existing) {
                if ($existing->commit_sha !== $commitSha || $existing->artifact_hash !== $artifactHash || $existing->configuration_hash !== $configurationHash) {
                    throw ValidationException::withMessages(['release_version' => '相同版本号不能绑定不同制品或配置。']);
                }

                return $existing;
            }
            $release = DeploymentRelease::query()->create(['release_version' => $version, 'environment' => app()->environment(), 'commit_sha' => $commitSha, 'artifact_hash' => $artifactHash, 'configuration_hash' => $configurationHash, 'status' => 'prepared', 'rollout_percentage' => 0, 'created_by_admin_id' => $admin?->id]);
            $this->event($release, 'register', null, 'prepared', 0, '制品与配置摘要已登记。', $artifactHash, $admin);

            return $release->fresh('events');
        });
    }

    public function transition(DeploymentRelease $release, string $action, int $percentage, string $reason, string $evidenceHash, ?AdminUser $admin = null): DeploymentRelease
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $evidenceHash)) {
            throw ValidationException::withMessages(['evidence_hash' => '发布证据摘要格式无效。']);
        }

        return DB::transaction(function () use ($release, $action, $percentage, $reason, $evidenceHash, $admin): DeploymentRelease {
            $locked = DeploymentRelease::query()->lockForUpdate()->findOrFail($release->id);
            [$to, $targetPercentage] = match ($action) {
                'canary' => ['canary', $percentage],
                'complete' => ['deployed', 100],
                'rollback' => ['rolled_back', 0],
                default => throw ValidationException::withMessages(['action' => '发布动作无效。']),
            };
            $valid = ($action === 'canary' && in_array($locked->status, ['prepared', 'canary'], true) && $targetPercentage >= 1 && $targetPercentage <= 99 && $targetPercentage > $locked->rollout_percentage)
                || ($action === 'complete' && in_array($locked->status, ['prepared', 'canary'], true))
                || ($action === 'rollback' && in_array($locked->status, ['canary', 'deployed'], true));
            if (! $valid) {
                throw ValidationException::withMessages(['action' => '当前发布状态不允许此动作或灰度比例未递增。']);
            }
            if ($action === 'complete' && app()->environment('production') && ! $this->productionReadiness->evaluate()['ready']) {
                throw ValidationException::withMessages(['action' => '生产就绪门禁未通过，禁止全量发布。']);
            }
            $from = $locked->status;
            $locked->update(['status' => $to, 'rollout_percentage' => $targetPercentage, 'deployed_at' => $to === 'deployed' ? now() : $locked->deployed_at, 'rolled_back_at' => $to === 'rolled_back' ? now() : null]);
            $this->event($locked, $action, $from, $to, $targetPercentage, $reason, $evidenceHash, $admin);
            $this->audit->record($admin, 'deployment.'.$action, 'deployment_release', $locked->public_id, ['status' => $from], ['status' => $to, 'rollout_percentage' => $targetPercentage], $reason);

            return $locked->fresh('events');
        });
    }

    private function event(DeploymentRelease $release, string $type, ?string $from, string $to, int $percentage, string $reason, string $hash, ?AdminUser $admin): void
    {
        $release->events()->create(['event_type' => $type, 'from_status' => $from, 'to_status' => $to, 'rollout_percentage' => $percentage, 'reason' => $reason, 'evidence_hash' => $hash, 'admin_user_id' => $admin?->id]);
    }
}
