<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Auth\Services\PhoneVerificationService;
use App\Modules\Campus\Models\Campus;
use App\Modules\Operations\Models\FeatureFlag;
use App\Modules\Task\Services\RewardBudgetService;
use App\Modules\Task\Services\TaskPublishingPermissionService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 审核演示账号（生产可执行、幂等）。
 *
 * 微信审核员无法接收短信验证码，因此审核期为其准备固定演示账号：
 * 手机号 13800000001 + 登录页展示的演示验证码（仅 review_in_progress 开启时生效）。
 * 该账号需具备闲置发布与任务发布能力，并为任务发布准备可用预算池。
 */
class ProductionReviewDemoAccountSeeder extends Seeder
{
    public const DEMO_PHONE = '13800000001';

    public function run(
        PhoneVerificationService $phones,
        TaskPublishingPermissionService $permissions,
        RewardBudgetService $budgets,
    ): void {
        $flag = FeatureFlag::query()->where('code', 'review_in_progress')->first();
        if ($flag === null || $flag->mode === 'disabled') {
            $this->command?->info('当前不在审核期，跳过审核演示账号初始化。');

            return;
        }

        $actor = AdminUser::query()
            ->whereHas('roles', fn ($query) => $query->where('code', 'super_admin'))
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
        if ($actor === null) {
            throw new RuntimeException('生产环境缺少可用的 super_admin，无法初始化审核演示账号。');
        }

        $user = User::query()->firstOrCreate(
            ['phone_hash' => $phones->phoneHash(self::DEMO_PHONE)],
            [
                'nickname' => '审核演示账号',
                'phone' => self::DEMO_PHONE,
                'phone_verified_at' => now(),
                'profile_completed_at' => now(),
                'status' => 'active',
            ],
        );

        $attributes = ['status' => 'active'];
        if ($user->phone_verified_at === null) {
            $attributes['phone_verified_at'] = now();
        }
        if ($user->profile_completed_at === null) {
            $attributes['profile_completed_at'] = now();
        }
        if ($user->campus_id === null) {
            $attributes['campus_id'] = $this->campusId();
        }
        if ($user->grade_year === null) {
            $attributes['grade_year'] = (int) now()->format('Y') - 1;
        }
        if ($user->major === null) {
            $attributes['major'] = '计算机科学与技术';
        }
        if ($user->college === null) {
            $attributes['college'] = '信息学院';
        }
        $user->update($attributes);

        $publisher = $user->taskPublisher()->first();
        if (! $user->can_publish_tasks || $publisher === null || ! $permissions->publisherCanPublish($publisher)) {
            $permissions->update($actor, $user, true);
        }

        $this->ensureBudgetPool($actor, $budgets);

        $this->command?->info('审核演示账号已就绪：'.self::DEMO_PHONE.'（闲置发布 + 任务发布）。');
    }

    private function campusId(): ?int
    {
        $campusId = User::query()->whereNotNull('campus_id')
            ->selectRaw('campus_id, count(*) as aggregate')
            ->groupBy('campus_id')->orderByDesc('aggregate')->value('campus_id');
        $campusId = $campusId ?: Campus::query()->value('id');

        return $campusId === null ? null : (int) $campusId;
    }

    private function ensureBudgetPool(AdminUser $actor, RewardBudgetService $budgets): void
    {
        $budgets->create($actor, [
            'name' => '审核演示积分预算池',
            'asset_type' => 'points',
            'total_amount' => 20000,
            'reason' => '供微信审核演示账号发布任务使用',
        ], 'review-demo-points-pool-v1');
    }
}
