<?php

namespace Database\Seeders;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Task\Services\RewardBudgetService;
use App\Modules\Task\Services\TaskDefinitionService;
use Illuminate\Database\Seeder;
use RuntimeException;

class TaskContentShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Task showcase data can only be seeded in local or testing environments.');
        }

        $this->call(DatabaseSeeder::class);
        $admin = $this->admin();
        $budgets = app(RewardBudgetService::class);
        $tasks = app(TaskDefinitionService::class);

        $pointsPool = $budgets->create($admin, [
            'name' => '校园任务内容评审积分池',
            'asset_type' => 'points',
            'total_amount' => 2000000,
            'reason' => '为本地任务大厅多类型内容评审提供积分预算',
        ], 'task-content-showcase-points-pool-v1');

        $walletPool = $budgets->create($admin, [
            'name' => '校园任务内容评审余额池',
            'asset_type' => 'wallet_cash',
            'total_amount' => 100000,
            'reason' => '为本地任务大厅现金奖励场景提供余额预算',
        ], 'task-content-showcase-wallet-pool-v1');

        foreach ($this->definitions() as $index => $definition) {
            $rewards = [['type' => 'points', 'points' => $definition['points']]];
            $rewardBudgets = [['reward_sequence' => 1, 'budget_pool_id' => $pointsPool->public_id]];
            if (($definition['wallet_cents'] ?? 0) > 0) {
                $rewards[] = ['type' => 'wallet_balance', 'balance_amount' => $definition['wallet_cents']];
                $rewardBudgets[] = ['reward_sequence' => 2, 'budget_pool_id' => $walletPool->public_id];
            }

            $content = [
                'business_scenario' => $definition['scenario'] ?? 'platform_task',
                'project_name' => $definition['project_name'] ?? '校园成长任务计划',
                'cover_image_url' => null,
                'supported_devices' => ['android', 'ios'],
                'visibility' => 'public',
                'category' => $definition['category'],
                'task_type' => $definition['task_type'],
                'participation_mode' => 'claim_and_submit',
                'verification_mode' => 'manual',
                'title' => $definition['title'],
                'description' => $definition['description'],
                'tags' => $definition['tags'],
                'rewards' => $rewards,
                'reward_budgets' => $rewardBudgets,
                'reward_quota_total' => $definition['quota_total'],
                'reward_quota_per_cycle' => $definition['quota_per_cycle'] ?? null,
                'completion_window_hours' => $definition['hours'],
                'submission_limit' => $definition['submission_limit'] ?? 3,
                'review_deadline_hours' => $definition['review_hours'] ?? 24,
                'appeal_deadline_days' => 7,
                'appeal_limit' => 1,
                'virtual_view_count' => $definition['views'],
                'claim_starts_at' => now()->subHours(2)->toIso8601String(),
                'claim_ends_at' => now()->addDays($definition['claim_days'] ?? 30)->toIso8601String(),
                'submit_ends_at' => now()->addDays(($definition['claim_days'] ?? 30) + 7)->toIso8601String(),
                'rules' => [
                    '仅提交本人真实完成的材料，禁止使用网络图片或重复材料。',
                    '请在领取后的规定时间内提交；逾期将自动释放任务名额。',
                    '审核未通过时可按审核意见修改，并在剩余提交次数内重新提交。',
                ],
                'steps' => $definition['steps'],
                'attachment_rules' => [
                    'max_count' => 9,
                    'max_images' => 9,
                    'max_image_bytes' => 10485760,
                ],
            ];

            $sequence = $index + 1;
            $task = $tasks->create($admin, $content, "task-content-showcase-create-v1-{$sequence}");
            $tasks->publish($admin, $task, '发布校园任务大厅内容评审批次', "task-content-showcase-publish-v1-{$sequence}");
        }

        $this->command?->info('Task content showcase is ready: 12 published tasks across 6 categories and 6 task types.');
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->firstOrCreate(
            ['account' => 'preview_admin'],
            ['display_name' => '预览管理员', 'password' => 'TaskMall2026!Preview', 'status' => 'active'],
        );
        $admin->roles()->syncWithoutDetaching(Role::query()->whereIn('code', ['platform_admin', 'super_admin'])->pluck('id'));

        return $admin;
    }

    /** @return array<int,array<string,mixed>> */
    private function definitions(): array
    {
        return [
            [
                'category' => 'onboarding', 'task_type' => 'one_time', 'title' => '完善你的校园兴趣档案',
                'description' => '补充学习方向、兴趣社团和本学期目标，让后续任务推荐更符合你的校园生活。',
                'points' => 80, 'quota_total' => 200, 'hours' => 24, 'views' => 326, 'tags' => ['资料完善', '新生必做'],
                'steps' => [
                    $this->formStep('campus-profile', '填写校园兴趣档案', '至少填写一个学习方向、一个兴趣爱好和一个本学期目标。', '例如：学习方向、兴趣社团、本学期目标'),
                    $this->confirmStep('profile-check', '确认信息真实有效', '提交前检查内容是否真实、完整，确认后等待审核。'),
                ],
            ],
            [
                'category' => 'onboarding', 'task_type' => 'milestone', 'title' => '完成新生校园路线探索',
                'description' => '依次找到图书馆、校医院和学生服务中心，完成一次实用的校园路线探索。',
                'points' => 180, 'quota_total' => 120, 'hours' => 72, 'views' => 584, 'tags' => ['校园探索', '里程碑'],
                'steps' => [
                    $this->imageStep('route-proof', '上传三个校园地点打卡图', '分别上传图书馆、校医院和学生服务中心的现场照片。', 3, 3),
                    $this->formStep('route-note', '填写路线体验', '说明路线耗时，并写下一条最有用的校园服务信息。', '路线耗时、印象最深的地点、实用信息'),
                ],
            ],
            [
                'category' => 'daily', 'task_type' => 'daily', 'title' => '早八课堂学习打卡',
                'description' => '在上午第一节课后提交一张课堂学习记录，并写下今天掌握的一个知识点。',
                'points' => 25, 'quota_total' => 600, 'quota_per_cycle' => 60, 'hours' => 8, 'review_hours' => 6, 'views' => 1680, 'tags' => ['每日打卡', '学习'],
                'steps' => [
                    $this->imageStep('class-proof', '上传课堂学习记录', '上传包含当天日期的课堂笔记或课程签到页面。', 1, 2),
                    $this->formStep('class-note', '记录今日知识点', '用一句话写下今天真正理解的知识点。', '今天我学会了……'),
                ],
            ],
            [
                'category' => 'daily', 'task_type' => 'weekly', 'title' => '一周三次运动挑战',
                'description' => '一周内完成三次不少于 20 分钟的运动，用记录建立稳定的校园运动习惯。',
                'points' => 150, 'quota_total' => 300, 'quota_per_cycle' => 50, 'hours' => 168, 'views' => 932, 'tags' => ['运动健康', '每周挑战'],
                'steps' => [
                    $this->imageStep('sports-records', '上传三次运动记录', '每张记录需要显示运动日期、时长或里程。', 3, 3),
                    $this->formStep('sports-review', '完成运动复盘', '说明三次运动的类型与身体感受。', '运动类型、总时长、完成感受'),
                ],
            ],
            [
                'category' => 'community', 'task_type' => 'repeatable', 'title' => '分享一条校园避坑经验',
                'description' => '发布一条真实、具体、可验证的校园经验，帮助同学少走弯路。',
                'points' => 100, 'quota_total' => 360, 'quota_per_cycle' => 60, 'hours' => 48, 'views' => 1246, 'tags' => ['经验分享', '校园互助'],
                'steps' => [
                    $this->formStep('post-content', '填写经验内容', '写明适用场景、具体做法和注意事项，不少于 80 字。', '场景、经验、注意事项'),
                    $this->imageStep('post-proof', '上传发布成功截图', '在社区发布后，上传包含标题与发布时间的页面截图。', 1, 1),
                ],
            ],
            [
                'category' => 'community', 'task_type' => 'one_time', 'title' => '校内失物信息核对员',
                'description' => '从社区失物招领信息中选择一条，核对时间、地点、物品特征和联系方式是否完整。',
                'points' => 90, 'quota_total' => 160, 'hours' => 24, 'views' => 478, 'tags' => ['失物招领', '有偿求助'],
                'scenario' => 'user_bounty', 'project_name' => '校园互助任务',
                'steps' => [
                    $this->formStep('lost-found-check', '填写核对结果', '记录信息中完整的字段与仍需补充的字段。', '信息编号、完整字段、缺失字段'),
                    $this->confirmStep('privacy-confirm', '确认未传播隐私信息', '确认核对过程中未复制或公开手机号、证件号等敏感信息。'),
                ],
            ],
            [
                'category' => 'commerce', 'task_type' => 'one_time', 'title' => '校园商城价格体验官',
                'description' => '选择同一个商品，比较现金、积分和现金＋积分三种购买方式，并给出你的选择理由。',
                'points' => 60, 'quota_total' => 200, 'hours' => 24, 'views' => 746, 'tags' => ['商城体验', '价格对比'],
                'steps' => [
                    $this->confirmStep('browse-product', '选择同一 SKU 查看三种价格', '进入商城商品详情，切换并记录三种购买方式。'),
                    $this->imageStep('price-proof', '上传价格对比截图', '截图需要清晰展示商品名称与三种购买价格。', 1, 3),
                    $this->formStep('purchase-choice', '填写购买选择', '说明你会选择哪种方式以及原因。', '我会选择……因为……'),
                ],
            ],
            [
                'category' => 'commerce', 'task_type' => 'monthly', 'title' => '食堂新品体验反馈',
                'description' => '体验一款本月校园食堂新品，从口味、分量、价格和复购意愿四个维度提交反馈。',
                'points' => 120, 'wallet_cents' => 50, 'quota_total' => 80, 'quota_per_cycle' => 20, 'hours' => 72, 'views' => 1068, 'tags' => ['食堂新品', '余额奖励'],
                'steps' => [
                    $this->imageStep('meal-proof', '上传新品与小票照片', '至少上传一张餐品照片；如有小票可一并上传。', 1, 2),
                    $this->formStep('meal-review', '填写四维体验评价', '分别评价口味、分量、价格和复购意愿。', '口味：；分量：；价格：；复购意愿：'),
                ],
            ],
            [
                'category' => 'research', 'task_type' => 'one_time', 'title' => '校园生活满意度调研',
                'description' => '围绕学习空间、餐饮、住宿和校园服务完成一份简短调研，答案仅用于产品改进。',
                'points' => 70, 'quota_total' => 400, 'hours' => 24, 'views' => 892, 'tags' => ['问卷调研', '校园体验'],
                'steps' => [
                    $this->formStep('satisfaction-score', '填写四项满意度', '按 1—5 分评价学习空间、餐饮、住宿和校园服务。', '学习空间：；餐饮：；住宿：；校园服务：'),
                    $this->formStep('improvement', '提交一条改进建议', '写下你最希望学校优先改善的一件事。', '最希望改善的是……'),
                ],
            ],
            [
                'category' => 'research', 'task_type' => 'weekly', 'title' => '图书馆空座观察',
                'description' => '在指定时段记录图书馆不同楼层的空座情况，为同学提供更实用的自习参考。',
                'points' => 50, 'quota_total' => 240, 'quota_per_cycle' => 40, 'hours' => 12, 'views' => 536, 'tags' => ['图书馆', '数据观察'],
                'steps' => [
                    $this->imageStep('library-proof', '上传现场时间凭证', '上传能看清楼层和时间的现场照片，请避开拍摄他人正脸。', 1, 1),
                    $this->formStep('seat-count', '填写空座观察数据', '记录楼层、观察时间、区域与大致空座数量。', '楼层、时间、区域、空座数量'),
                ],
            ],
            [
                'category' => 'campaign', 'task_type' => 'milestone', 'title' => '迎新季校园地标挑战',
                'description' => '完成校门、主教学楼、图书馆和操场四个地标打卡，制作你的第一份校园记忆。',
                'points' => 300, 'quota_total' => 120, 'hours' => 168, 'views' => 2140, 'tags' => ['迎新季', '地标挑战'],
                'scenario' => 'growth_challenge', 'project_name' => '迎新季校园挑战',
                'steps' => [
                    $this->imageStep('landmark-photos', '上传四个校园地标照片', '依次上传校门、主教学楼、图书馆和操场的本人打卡照片。', 4, 4),
                    $this->formStep('campus-memory', '写下校园第一印象', '用 30—100 字记录完成挑战后的校园第一印象。', '我对校园的第一印象是……'),
                ],
            ],
            [
                'category' => 'campaign', 'task_type' => 'repeatable', 'title' => '校园公益随手拍',
                'description' => '发现并记录校园中的文明行为或可改进问题，让每一次观察都能推动校园变得更好。',
                'points' => 100, 'quota_total' => 360, 'quota_per_cycle' => 60, 'hours' => 48, 'views' => 1386, 'tags' => ['校园公益', '随手拍'],
                'scenario' => 'growth_challenge', 'project_name' => '文明校园行动',
                'steps' => [
                    $this->imageStep('public-good-photo', '上传现场照片', '照片需能说明问题或文明行为，注意遮挡人脸与个人信息。', 1, 3),
                    $this->formStep('public-good-note', '填写地点与建议', '写明地点、时间、情况说明和一条可执行建议。', '地点、时间、情况、建议'),
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function formStep(string $id, string $title, string $description, string $placeholder): array
    {
        return ['id' => $id, 'kind' => 'form_input', 'title' => $title, 'description' => $description, 'input_type' => 'text', 'required' => true, 'placeholder' => $placeholder];
    }

    /** @return array<string,mixed> */
    private function imageStep(string $id, string $title, string $description, int $min, int $max): array
    {
        return ['id' => $id, 'kind' => 'verification_image', 'title' => $title, 'description' => $description, 'input_type' => 'image', 'required' => true, 'min_files' => $min, 'max_files' => $max];
    }

    /** @return array<string,mixed> */
    private function confirmStep(string $id, string $title, string $description): array
    {
        return ['id' => $id, 'kind' => 'confirmation', 'title' => $title, 'description' => $description, 'input_type' => 'none', 'required' => true];
    }
}
