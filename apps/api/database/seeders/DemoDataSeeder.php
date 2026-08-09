<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Account\Models\UserAddress;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Auth\Services\PhoneVerificationService;
use App\Modules\Community\Services\CommunityContentService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\ShippingTemplateService;
use App\Modules\Product\Models\ProductCategory;
use App\Modules\Product\Services\ProductCatalogService;
use App\Modules\Task\Services\RewardBudgetService;
use App\Modules\Task\Services\TaskDefinitionService;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data can only be seeded in local or testing environments.');
        }

        $this->call(DatabaseSeeder::class);
        $admin = $this->admin();
        $users = $this->users();

        $this->assetsAndAddress($users[0], $admin);
        $this->tasks($admin);
        $this->products($admin);
        $this->community($users, $admin);

        $this->command?->info('Demo data is ready: 4 tasks, 3 products, 3 community posts and a funded preview user.');
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

    /** @return array<int, User> */
    private function users(): array
    {
        $phones = app(PhoneVerificationService::class);
        $definitions = [
            ['phone' => '13800000001', 'nickname' => '成长体验官'],
            ['phone' => '13800000002', 'nickname' => '小满同学'],
            ['phone' => '13800000003', 'nickname' => '行动派阿南'],
        ];

        return collect($definitions)->map(function (array $definition) use ($phones): User {
            $hash = $phones->phoneHash($definition['phone']);

            return User::query()->firstOrCreate(
                ['phone_hash' => $hash],
                [
                    'nickname' => $definition['nickname'],
                    'phone' => $definition['phone'],
                    'phone_verified_at' => now(),
                    'profile_completed_at' => now(),
                    'status' => 'active',
                ],
            );
        })->all();
    }

    private function assetsAndAddress(User $user, AdminUser $admin): void
    {
        $assets = app(AssetLedgerService::class);
        $assets->grantPoints($user, 3200, 'manual', 'demo-user', '本地预览初始积分', 'demo-user-points-v1', $admin);
        $assets->changeWallet($user, 8880, '本地预览初始余额', 'demo-user-wallet-v1', $admin, 'adjust');

        UserAddress::query()->firstOrCreate(
            ['idempotency_key_hash' => hash('sha256', 'demo-user-address-v1')],
            [
                'user_id' => $user->id,
                'recipient_name' => '体验用户',
                'phone' => '13800000001',
                'province' => '广东省',
                'city' => '深圳市',
                'district' => '南山区',
                'detail' => '科技园体验地址 1 号',
                'postal_code' => '518000',
                'is_default' => true,
            ],
        );
    }

    private function tasks(AdminUser $admin): void
    {
        $budgetService = app(RewardBudgetService::class);
        $taskService = app(TaskDefinitionService::class);
        $pool = $budgetService->create($admin, [
            'name' => '本地演示积分预算池',
            'asset_type' => 'points',
            'total_amount' => 200000,
            'reason' => '本地产品体验与全流程验收',
        ], 'demo-points-pool-v1');

        $definitions = [
            ['category' => 'onboarding', 'title' => '完成你的第一份成长计划', 'description' => '写下本周最想完成的三件事，并说明每件事的完成标准。', 'points' => 120, 'hours' => 24, 'tags' => ['新手', '成长']],
            ['category' => 'daily', 'title' => '记录一次专注学习', 'description' => '完成不少于 25 分钟的学习，并提交一段收获总结。', 'points' => 80, 'hours' => 12, 'tags' => ['日常', '学习']],
            ['category' => 'community', 'title' => '分享一条真实经验', 'description' => '在成长社区发布一条对其他用户有帮助的原创经验。', 'points' => 200, 'hours' => 48, 'tags' => ['社区', '分享']],
            ['category' => 'commerce', 'title' => '体验三种商品购买方式', 'description' => '浏览精选商城，比较现金、积分和现金＋积分三种价格。', 'points' => 60, 'hours' => 8, 'tags' => ['商城', '体验']],
        ];

        foreach ($definitions as $index => $definition) {
            $sequence = $index + 1;
            $content = [
                'visibility' => 'public',
                'category' => $definition['category'],
                'task_type' => 'one_time',
                'participation_mode' => 'claim_and_submit',
                'verification_mode' => 'manual',
                'title' => $definition['title'],
                'description' => $definition['description'],
                'tags' => $definition['tags'],
                'rewards' => [['type' => 'points', 'points' => $definition['points']]],
                'reward_budgets' => [['reward_sequence' => 1, 'budget_pool_id' => $pool->public_id]],
                'reward_quota_total' => 100,
                'completion_window_hours' => $definition['hours'],
                'submission_limit' => 3,
                'review_deadline_hours' => 48,
                'appeal_deadline_days' => 7,
                'appeal_limit' => 1,
                'claim_starts_at' => now()->subDay()->toIso8601String(),
                'claim_ends_at' => now()->addDays(30)->toIso8601String(),
                'submit_ends_at' => now()->addDays(35)->toIso8601String(),
                'rules' => [],
                'steps' => $this->taskSteps($definition['category']),
                'attachment_rules' => ['max_count' => 9, 'max_images' => 9, 'max_image_bytes' => 10485760],
            ];
            $task = $taskService->create($admin, $content, "demo-task-create-v1-{$sequence}");
            $taskService->publish($admin, $task, '发布本地演示任务', "demo-task-publish-v1-{$sequence}");
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function taskSteps(string $category): array
    {
        return match ($category) {
            'onboarding' => [
                ['id' => 'goals', 'title' => '填写本周目标', 'description' => '写下本周最想完成的三件事，并分别说明可验证的完成标准。', 'input_type' => 'text', 'required' => true],
                ['id' => 'plan', 'title' => '补充行动计划', 'description' => '说明你准备从哪一步开始，以及预计完成时间。', 'input_type' => 'text', 'required' => true],
            ],
            'daily' => [
                ['id' => 'focus-proof', 'title' => '上传专注记录', 'description' => '完成至少 25 分钟专注学习，上传包含时长的记录截图。', 'input_type' => 'image', 'required' => true],
                ['id' => 'learning-note', 'title' => '填写学习收获', 'description' => '用文字总结本次学习的重点和下一步行动。', 'input_type' => 'text', 'required' => true],
            ],
            'community' => [
                ['id' => 'publish-post', 'title' => '发布原创经验', 'description' => '在成长社区发布一条对其他用户有帮助的原创经验。', 'input_type' => 'none', 'required' => true],
                ['id' => 'post-proof', 'title' => '提交帖子凭证', 'description' => '填写帖子标题，并上传发布成功后的页面截图。', 'input_type' => 'text_image', 'required' => true],
            ],
            'commerce' => [
                ['id' => 'compare-prices', 'title' => '比较三种购买方式', 'description' => '选择同一 SKU，查看现金、积分和现金＋积分三种价格。', 'input_type' => 'none', 'required' => true],
                ['id' => 'price-proof', 'title' => '上传价格对比截图', 'description' => '上传能够同时说明三种购买方式的页面截图。', 'input_type' => 'image', 'required' => true],
                ['id' => 'experience-note', 'title' => '填写体验结论', 'description' => '说明你会选择哪种购买方式以及原因。', 'input_type' => 'text', 'required' => true],
            ],
            default => [['id' => 'result', 'title' => '提交任务结果', 'description' => '按照任务说明填写完成结果并上传必要凭证。', 'input_type' => 'text_image', 'required' => true]],
        };
    }

    private function products(AdminUser $admin): void
    {
        $catalog = app(ProductCatalogService::class);
        $shipping = app(ShippingTemplateService::class);
        $inventory = app(InventoryService::class);
        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'demo-selection'],
            ['name' => '精选好物', 'status' => 'active', 'sort_order' => 10],
        );
        $shippingTemplate = $shipping->create($admin, [
            'name' => '全国统一运费',
            'charge_type' => 'fixed',
            'fixed_amount' => 600,
            'regions' => [['region_codes' => ['*'], 'deliverable' => true, 'remote_surcharge_amount' => 0]],
            'free_rules' => [['type' => 'cash_threshold', 'threshold_amount' => 9900, 'target_ids' => []]],
            'enabled' => true,
        ], 'demo-shipping-template-v1');

        $products = [
            [
                'name' => '轻量随行保温杯', 'subtitle' => '通勤与学习都好用', 'description' => '食品接触级内胆，轻量杯身，适合日常随行。',
                'sku' => 'DEMO-CUP-001', 'sku_name' => '雾白色 450ml', 'specs' => ['颜色' => '雾白', '容量' => '450ml'],
                'fulfillment_type' => 'logistics', 'shipping_template_id' => $shippingTemplate->public_id, 'weight_grams' => 380,
                'prices' => [1990, 2200, 990, 1100], 'stock' => 120,
            ],
            [
                'name' => '精品咖啡电子兑换券', 'subtitle' => '兑换后即时到账', 'description' => '用于演示虚拟交付和无需填写物流的完整流程。',
                'sku' => 'DEMO-COFFEE-001', 'sku_name' => '30 元电子券', 'specs' => ['面值' => '30元', '有效期' => '30天'],
                'fulfillment_type' => 'virtual_delivery', 'shipping_template_id' => null, 'weight_grams' => null,
                'prices' => [1500, 1800, 500, 1200], 'stock' => 500,
            ],
            [
                'name' => '效率成长线上课程', 'subtitle' => '从计划到复盘的实用方法', 'description' => '用于演示无需物流商品、积分兑换和混合价格。',
                'sku' => 'DEMO-COURSE-001', 'sku_name' => '基础课程访问权', 'specs' => ['形式' => '线上', '课时' => '6节'],
                'fulfillment_type' => 'no_logistics', 'shipping_template_id' => null, 'weight_grams' => null,
                'prices' => [990, 1200, 390, 700], 'stock' => 300,
            ],
        ];

        foreach ($products as $index => $definition) {
            [$cash, $points, $mixedCash, $mixedPoints] = $definition['prices'];
            $product = $catalog->create($admin, [
                'category_id' => $category->public_id,
                'name' => $definition['name'],
                'subtitle' => $definition['subtitle'],
                'description' => $definition['description'],
                'skus' => [[
                    'sku_code' => $definition['sku'],
                    'name' => $definition['sku_name'],
                    'specs' => $definition['specs'],
                    'fulfillment_type' => $definition['fulfillment_type'],
                    'shipping_template_id' => $definition['shipping_template_id'],
                    'weight_grams' => $definition['weight_grams'],
                    'free_shipping' => false,
                    'low_stock_threshold' => 10,
                    'limit_per_order' => 5,
                    'purchase_options' => [
                        ['purchase_mode' => 'cash', 'cash_amount' => $cash, 'points' => 0, 'enabled' => true],
                        ['purchase_mode' => 'points', 'cash_amount' => 0, 'points' => $points, 'enabled' => true],
                        ['purchase_mode' => 'cash_points', 'cash_amount' => $mixedCash, 'points' => $mixedPoints, 'enabled' => true],
                    ],
                ]],
            ], 'demo-product-create-v1-'.($index + 1));
            $sku = $product->skus()->firstOrFail();
            $stock = $inventory->ensure($sku);
            $inventory->adjust($admin, $sku, 'set', $definition['stock'], $stock->version, '设置本地演示库存', 'demo-inventory-v1-'.($index + 1));
            $catalog->transition($admin, $product, 'publish', '发布本地演示商品', 'demo-product-publish-v1-'.($index + 1));
        }
    }

    /** @param array<int, User> $users */
    private function community(array $users, AdminUser $admin): void
    {
        $community = app(CommunityContentService::class);
        $posts = [
            ['把大目标拆成今天能完成的一步', '我最近开始把目标改写成二十分钟内可以启动的小动作，执行阻力明显降低，也更容易坚持复盘。'],
            ['分享我的每周复盘清单', '每周固定看目标进度、时间投入、意外收获和下周第一步，四个问题已经足够让计划持续迭代。'],
            ['积分兑换前我会先检查这三项', '先看积分有效期，再比较三种购买方式，最后确认商品是否需要物流，这样下单时会更从容。'],
        ];

        foreach ($posts as $index => [$title, $content]) {
            $post = $community->createPost($users[$index], ['title' => $title, 'content' => $content], 'demo-community-post-v1-'.($index + 1));
            if ($post->status === 'pending_review') {
                $post = $community->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '本地演示内容审核通过。']);
            }
            $commenter = $users[($index + 1) % count($users)];
            $community->createComment($commenter, $post, ['content' => '这个方法很实用，我也准备从今天开始尝试并记录效果。'], 'demo-community-comment-v1-'.($index + 1));
        }
    }
}
