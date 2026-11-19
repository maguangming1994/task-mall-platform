<?php

namespace Database\Seeders;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Settings\Models\SystemSettingDefinition;
use App\Modules\Settings\Models\SystemSettingVersion;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Services\RewardBudgetService;
use App\Modules\Task\Services\TaskDefinitionService;
use Illuminate\Database\Seeder;

/**
 * 首页 + 任务大厅线上内容填充（生产可执行、幂等）。
 *
 * 数据口径全部为正式内容：
 * - 积分预算池、任务、首页装修均带固定幂等键/标记，可重复执行
 * - 生产环境复用既有平台管理员，不创建带默认密码的账号
 */
class HomeShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        // 确保配置定义已同步（updateOrCreate 幂等），否则任务发布流程读取配置会失败。
        $this->call(SystemSettingDefinitionSeeder::class);

        $admin = $this->admin();
        $budgets = app(RewardBudgetService::class);
        $tasks = app(TaskDefinitionService::class);

        $pointsPool = $budgets->create($admin, [
            'name' => '首页与任务大厅内容积分池',
            'asset_type' => 'points',
            'total_amount' => 20000,
            'reason' => '为首页与任务大厅校园互助内容提供积分预算',
        ], 'home-showcase-points-pool-v1');

        foreach ($this->definitions() as $index => $definition) {
            $content = [
                'business_scenario' => 'user_bounty',
                'project_name' => '校园互助任务',
                'cover_image_url' => $definition['cover'],
                'supported_devices' => ['android', 'ios'],
                'visibility' => 'public',
                'category' => $definition['category'],
                'task_type' => 'one_time',
                'participation_mode' => 'claim_and_submit',
                'verification_mode' => 'manual',
                'title' => $definition['title'],
                'description' => $definition['description'],
                'tags' => $definition['tags'],
                'rewards' => [['type' => 'points', 'points' => $definition['points']]],
                'reward_budgets' => [['reward_sequence' => 1, 'budget_pool_id' => $pointsPool->public_id]],
                'reward_quota_total' => $definition['quota_total'],
                'completion_window_hours' => 24,
                'submission_limit' => 3,
                'review_deadline_hours' => 24,
                'appeal_deadline_days' => 7,
                'appeal_limit' => 1,
                'virtual_view_count' => $definition['views'],
                'claim_starts_at' => now()->subHours(2)->toIso8601String(),
                'claim_ends_at' => now()->addDays(14)->toIso8601String(),
                'submit_ends_at' => now()->addDays(21)->toIso8601String(),
                'rules' => [
                    '请按任务说明在约定时间、地点完成，注意保护个人隐私。',
                    '完成后及时提交凭证，超时将自动释放名额。',
                    '如遇特殊情况请提前与发布者沟通取消。',
                ],
                'steps' => $definition['steps'],
                'attachment_rules' => ['max_count' => 9, 'max_images' => 9, 'max_image_bytes' => 10485760],
                // —— 首页/大厅社交化展示字段（随版本内容透传）——
                'publisher_display_name' => $definition['publisher'],
                'publisher_avatar_url' => $definition['avatar'],
                'publisher_credit_score' => $definition['credit_score'],
                'location_text' => $definition['location'] ?? null,
                'urgent' => $definition['urgent'] ?? false,
                'bounty' => $definition['bounty'] ?? false,
                'gallery' => $definition['gallery'] ?? [],
            ];

            $sequence = $index + 1;
            $task = $tasks->create($admin, $content, "home-showcase-create-v1-{$sequence}");
            $tasks->publish($admin, $task, '发布首页与任务大厅校园互助内容', "home-showcase-publish-v1-{$sequence}");

            // 发布时间按设计稿错开（5/12/26 分钟前…），仅在任务已发布时回填。
            $published = Task::query()->find($task->id);
            if ($published !== null && $published->status === 'published' && $published->published_at !== null) {
                $target = now()->subMinutes($definition['minutes_ago']);
                if (abs($published->published_at->diffInSeconds($target)) > 5) {
                    $published->update(['published_at' => $target]);
                }
            }
        }

        $this->publishHomepageSettings($admin);

        $this->command?->info('Home showcase ready: 7 campus tasks and homepage v1 decorations (idempotent).');
    }

    private function admin(): AdminUser
    {
        // 生产环境只允许使用既有管理员发布内容，绝不创建带默认密码的账号。
        if (app()->environment('production')) {
            $admin = AdminUser::query()
                ->whereHas('roles', fn ($query) => $query->whereIn('code', ['platform_admin', 'super_admin']))
                ->where('status', 'active')
                ->orderBy('id')
                ->first();

            if ($admin === null) {
                throw new \RuntimeException('生产环境缺少可用的平台管理员，无法发布首页与任务大厅内容。');
            }

            return $admin;
        }

        $admin = AdminUser::query()->firstOrCreate(
            ['account' => 'preview_admin'],
            ['display_name' => '预览管理员', 'password' => 'TaskMall2026!Preview', 'status' => 'active'],
        );
        $admin->roles()->syncWithoutDetaching(Role::query()->whereIn('code', ['platform_admin', 'super_admin'])->pluck('id'));

        return $admin;
    }

    private function publishHomepageSettings(AdminUser $admin): void
    {
        $service = app(SystemSettingService::class);

        // 首页轮播：首张为「周末拼单节」橙色 banner。
        $this->publishSettingOnce($service, $admin, 'homepage.carousel', 'weekend-group-buy-v1', [
            'autoplay' => true,
            'interval_ms' => 4500,
            'items' => [
                [
                    'id' => 'weekend-group-buy-v1',
                    'kicker' => '周末拼单节',
                    'title' => '3人成团 · 拼单积分翻倍',
                    'description' => '仅限本周，组队发布或完成拼单任务，积分奖励翻倍拿。',
                    'button_text' => '立即拼单',
                    'link_type' => 'tasks',
                    'image_url' => '/content/home/promo-group-buy.png',
                ],
                [
                    'id' => 'task-welcome',
                    'kicker' => '今天也有新收获',
                    'title' => '完成好任务，兑换好生活',
                    'description' => '做任务赚积分，校园互助从一件小事开始。',
                    'button_text' => '去任务大厅',
                    'link_type' => 'tasks',
                    'image_url' => '',
                ],
            ],
        ]);

        // 金刚区：校园分类入口 + 新增地推中心、资料共享（图标由用户端按 id 内置，保证离线可用）。
        $this->publishSettingOnce($service, $admin, 'homepage.modules', 'cat-material-share', [
            'page_background_url' => '',
            'notification_icon_url' => '',
            'quick_grid_background_url' => '',
            'priority_entries' => [
                ['id' => 'assets', 'mark' => '资', 'icon_url' => ''],
                ['id' => 'referrals', 'mark' => '邀', 'icon_url' => ''],
            ],
            'quick_entries' => [
                ['id' => 'cat-errand', 'label' => '跑腿代办', 'note' => '取件带饭', 'mark' => '跑', 'tone' => 'green', 'icon_url' => '', 'link_type' => 'tasks:errand'],
                ['id' => 'cat-study', 'label' => '学习助力', 'note' => '答疑打印', 'mark' => '学', 'tone' => 'blue', 'icon_url' => '', 'link_type' => 'tasks:study'],
                ['id' => 'cat-secondhand', 'label' => '二手闲置', 'note' => '淘好物', 'mark' => '闲', 'tone' => 'orange', 'icon_url' => '', 'link_type' => 'secondhand'],
                ['id' => 'cat-skill', 'label' => '技能服务', 'note' => '修电脑修车', 'mark' => '技', 'tone' => 'violet', 'icon_url' => '', 'link_type' => 'tasks:skill'],
                ['id' => 'cat-lost', 'label' => '失物招领', 'note' => '寻物启事', 'mark' => '失', 'tone' => 'pink', 'icon_url' => '', 'link_type' => 'tasks:lost_found'],
                ['id' => 'cat-teamup', 'label' => '组队拼单', 'note' => '搭子拼团', 'mark' => '队', 'tone' => 'cyan', 'icon_url' => '', 'link_type' => 'tasks:teamup'],
                ['id' => 'pt-center', 'label' => '地推中心', 'note' => '校园推广', 'mark' => '推', 'tone' => 'green', 'icon_url' => '', 'link_type' => 'push'],
                ['id' => 'cat-material-share', 'label' => '资料共享', 'note' => '投喂求资料', 'mark' => '资', 'tone' => 'violet', 'icon_url' => '', 'link_type' => 'material'],
            ],
            'recommendation' => ['section_title' => '推荐任务', 'section_subtitle' => '校园互助，从身边的一件小事开始', 'title' => '完成新手任务', 'description' => '完善资料、熟悉流程，开启你的第一份奖励', 'tag' => '简单', 'reward_text' => '最高 +300 积分', 'time_text' => '约 3 分钟', 'icon_text' => '新', 'icon_url' => '', 'background_url' => '', 'link_type' => 'tasks'],
            'product_section' => ['section_title' => '精选兑换', 'section_subtitle' => '做任务赚积分，兑心仪好物', 'cards' => [
                ['id' => 'points-choice', 'eyebrow' => '积分好物', 'title' => '日常精选', 'price_text' => '积分兑换', 'tone' => 'blue', 'image_url' => '', 'background_url' => '', 'link_type' => 'products'],
                ['id' => 'life-choice', 'eyebrow' => '限时推荐', 'title' => '生活补给', 'price_text' => '现金 / 积分', 'tone' => 'warm', 'image_url' => '', 'background_url' => '', 'link_type' => 'products'],
            ]],
            'trust' => ['title' => '每一笔资产都有记录', 'description' => '积分来源、有效期、退款和余额变动清晰可查', 'icon_url' => '', 'background_url' => '', 'link_type' => 'assets'],
        ]);
    }

    /**
     * 以最新版本值中是否已包含标记来判定幂等；未发布过才走草稿+发布。
     */
    private function publishSettingOnce(SystemSettingService $service, AdminUser $admin, string $key, string $marker, array $value): void
    {
        $definition = SystemSettingDefinition::query()->where('setting_key', $key)->first();
        if ($definition === null) {
            return;
        }
        $latest = SystemSettingVersion::query()
            ->where('setting_definition_id', $definition->id)
            ->latest('version')
            ->first();
        $encoded = json_encode($latest?->value, JSON_UNESCAPED_UNICODE) ?: '';
        if ($latest !== null && str_contains($encoded, $marker)) {
            return;
        }

        $service->saveDraft($key, $value, '首页与任务大厅线上内容装修 v1', $admin);
        $service->publish($key, $admin);
    }

    /** @return array<int,array<string,mixed>> */
    private function definitions(): array
    {
        return [
            [
                'category' => 'errand',
                'title' => '西门菜鸟驿站取2个快递，送到3号宿舍楼',
                'description' => '两个小包裹不重，菜鸟取件码我私发，送到 3 号宿舍楼楼下即可，加急，辛苦费好说～',
                'tags' => ['跑腿代办'],
                'points' => 15, 'quota_total' => 40, 'views' => 86,
                'publisher' => '张同学', 'avatar' => '/content/personas/student-boy-orange.png', 'credit_score' => 735,
                'location' => '西门 · 3号宿舍楼', 'bounty' => true, 'urgent' => true,
                'cover' => '/content/tasks/errand-courier.png', 'minutes_ago' => 5,
                'steps' => [
                    ['id' => 'pickup-code', 'kind' => 'form_input', 'title' => '填写取件完成情况', 'description' => '取件后填写快递单号后四位与送达时间。', 'input_type' => 'text', 'required' => true, 'placeholder' => '单号后四位、送达时间'],
                    ['id' => 'delivery-proof', 'kind' => 'verification_image', 'title' => '上传送达凭证', 'description' => '包裹放到宿舍楼指定位置后拍一张照。', 'input_type' => 'image', 'required' => true, 'min_files' => 1, 'max_files' => 2],
                ],
            ],
            [
                'category' => 'study',
                'title' => '帮打印20页复习资料，送到图书馆北门',
                'description' => '黑白双面 20 页，PDF 发你，打印费我出另算辛苦费，长期可约，送到图书馆北门交接。',
                'tags' => ['学习助力', '线下', '长期可约'],
                'points' => 6, 'quota_total' => 60, 'views' => 52,
                'publisher' => '李同学', 'avatar' => '/content/personas/student-boy-blue.png', 'credit_score' => 708,
                'location' => '图书馆北门',
                'cover' => '/content/tasks/study-print.png', 'minutes_ago' => 12,
                'steps' => [
                    ['id' => 'print-proof', 'kind' => 'verification_image', 'title' => '上传打印资料照片', 'description' => '打印完成后拍一张资料整体照片。', 'input_type' => 'image', 'required' => true, 'min_files' => 1, 'max_files' => 2],
                    ['id' => 'handover-note', 'kind' => 'form_input', 'title' => '填写交接说明', 'description' => '填写实际打印费用与交接时间。', 'input_type' => 'text', 'required' => true, 'placeholder' => '打印费、交接时间'],
                ],
            ],
            [
                'category' => 'teamup',
                'title' => '高数期中复习搭子，每晚7-9点图书馆',
                'description' => '期中前组 3 人复习小队，每晚 7-9 点图书馆三楼一起刷题互讲，坚持一周，积分一起拿。',
                'tags' => ['组队', '学习助力'],
                'points' => 30, 'quota_total' => 20, 'views' => 143,
                'publisher' => '王同学', 'avatar' => '/content/personas/student-girl.png', 'credit_score' => 752,
                'location' => '图书馆三楼',
                'cover' => '/content/tasks/study-buddy.png', 'minutes_ago' => 26,
                'steps' => [
                    ['id' => 'join-confirm', 'kind' => 'confirmation', 'title' => '确认参加复习小队', 'description' => '确认每晚 7-9 点可以参加，入队后请按时到场。', 'input_type' => 'none', 'required' => true],
                ],
            ],
            [
                'category' => 'errand',
                'title' => '求带饭！二食堂三楼黄焖鸡，送到6号楼508',
                'description' => "求带饭！二食堂三楼的黄焖鸡米饭，加辣加蛋，送到 6 号楼 508，辛苦费好说～\n#跑腿代办",
                'tags' => ['跑腿代办'],
                'points' => 12, 'quota_total' => 30, 'views' => 67,
                'publisher' => '林小满', 'avatar' => '/content/personas/student-girl.png', 'credit_score' => 720,
                'location' => '二食堂 · 6号楼',
                'cover' => '/content/tasks/errand-meal.png', 'minutes_ago' => 12,
                'gallery' => ['/content/feed/meal-ramen.png', '/content/feed/meal-tea.png', '/content/feed/meal-salad.png'],
                'steps' => [
                    ['id' => 'meal-proof', 'kind' => 'verification_image', 'title' => '上传餐品凭证', 'description' => '取餐后拍一张餐品照片。', 'input_type' => 'image', 'required' => true, 'min_files' => 1, 'max_files' => 2],
                    ['id' => 'deliver-note', 'kind' => 'form_input', 'title' => '填写送达说明', 'description' => '填写实际餐费与送达时间。', 'input_type' => 'text', 'required' => true, 'placeholder' => '餐费、送达时间'],
                ],
            ],
            [
                'category' => 'lost_found',
                'title' => '图书馆三楼丢了一串钥匙，带棕色小熊挂件',
                'description' => '今晚在图书馆三楼自习区丢了一串钥匙（约 3 把，带棕色小熊毛绒挂件），拾到请联系，万分感谢！',
                'tags' => ['失物招领'],
                'points' => 20, 'quota_total' => 20, 'views' => 210,
                'publisher' => '陈同学', 'avatar' => '/content/personas/student-boy-blue.png', 'credit_score' => 695,
                'location' => '图书馆三楼',
                'cover' => '/content/tasks/lost-keys.png', 'minutes_ago' => 48,
                'steps' => [
                    ['id' => 'item-photo', 'kind' => 'verification_image', 'title' => '上传物品照片', 'description' => '拍一张钥匙整体照片，注意遮挡敏感信息。', 'input_type' => 'image', 'required' => true, 'min_files' => 1, 'max_files' => 2],
                    ['id' => 'found-note', 'kind' => 'form_input', 'title' => '填写拾取说明', 'description' => '填写拾取的时间与地点。', 'input_type' => 'text', 'required' => true, 'placeholder' => '拾取时间、地点'],
                ],
            ],
            [
                'category' => 'skill',
                'title' => '自行车掉链/刹车调节，校门口当面帮修',
                'description' => '动手能力强，自行车掉链、刹车松紧这类小问题可以帮忙，工具我有，校门口当面处理，十几分钟搞定。',
                'tags' => ['技能服务'],
                'points' => 25, 'quota_total' => 15, 'views' => 98,
                'publisher' => '赵同学', 'avatar' => '/content/personas/student-boy-orange.png', 'credit_score' => 761,
                'location' => '校门口修车点',
                'cover' => '/content/tasks/skill-repair.png', 'minutes_ago' => 75,
                'steps' => [
                    ['id' => 'repair-proof', 'kind' => 'verification_image', 'title' => '上传维修照片', 'description' => '维修完成后拍一张车辆照片。', 'input_type' => 'image', 'required' => true, 'min_files' => 1, 'max_files' => 2],
                    ['id' => 'repair-note', 'kind' => 'form_input', 'title' => '填写维修说明', 'description' => '说明故障原因与处理结果。', 'input_type' => 'text', 'required' => true, 'placeholder' => '故障、处理结果'],
                ],
            ],
            [
                'category' => 'research',
                'title' => '3分钟食堂满意度匿名问卷，奶茶基金靠它了',
                'description' => '关于食堂口味、排队和价格的小调研，全程匿名约 3 分钟，有效提交即可拿积分。',
                'tags' => ['问卷调研'],
                'points' => 8, 'quota_total' => 200, 'views' => 324,
                'publisher' => '周同学', 'avatar' => '/content/personas/student-girl.png', 'credit_score' => 702,
                'location' => '线上',
                'cover' => '/content/tasks/research-survey.png', 'minutes_ago' => 130,
                'steps' => [
                    ['id' => 'survey-confirm', 'kind' => 'confirmation', 'title' => '确认已完成问卷', 'description' => '请按真实想法填写全部题目后再确认提交。', 'input_type' => 'none', 'required' => true],
                    ['id' => 'survey-score', 'kind' => 'form_input', 'title' => '填写总体评分', 'description' => '填写你给出的总体满意度评分（1-5 分）。', 'input_type' => 'text', 'required' => true, 'placeholder' => '1-5 分'],
                ],
            ],
        ];
    }
}
