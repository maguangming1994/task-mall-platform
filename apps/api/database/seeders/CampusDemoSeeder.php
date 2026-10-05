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
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductCategory;
use App\Modules\Product\Services\ProductCatalogService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 校园场景演示数据（生产可执行、幂等）。
 *
 * 覆盖：积分商城商品（3 种购买方式 + 库存 + 运费模板）、校园墙帖子 + 评论、
 * 演示用户（含积分/余额/收货地址）。admin 复用现有超管，不新建账号。
 * 幂等键统一前缀 campus-demo-*-v1，可重复执行不产生重复数据。
 */
class CampusDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $admin = $this->admin();
        $users = $this->users();

        $this->assetsAndAddress($users[0], $admin);
        $this->products($admin);
        $this->community($users, $admin);

        $this->command?->info('Campus demo data is ready: 7 products, 8 community posts with comments, and funded campus users.');
    }

    private function admin(): AdminUser
    {
        if (app()->environment('production')) {
            $admin = AdminUser::query()
                ->whereHas('roles', fn ($q) => $q->where('code', 'super_admin'))
                ->where('status', 'active')
                ->orderBy('id')
                ->first();

            if ($admin === null) {
                throw new RuntimeException('生产环境缺少 super_admin 角色的管理员，无法执行校园演示数据填充。');
            }

            return $admin;
        }

        $admin = AdminUser::query()->firstOrCreate(
            ['account' => 'preview_admin'],
            ['display_name' => '预览管理员', 'password' => 'TaskMall2026!Preview', 'status' => 'active'],
        );
        $admin->roles()->syncWithoutDetaching(
            Role::query()->whereIn('code', ['platform_admin', 'super_admin'])->pluck('id')
        );

        return $admin;
    }

    /** @return array<int, User> */
    private function users(): array
    {
        $phones = app(PhoneVerificationService::class);
        $definitions = [
            ['phone' => '13800000001', 'nickname' => '滨江校区体验官'],
            ['phone' => '13800000002', 'nickname' => '小满同学'],
            ['phone' => '13800000003', 'nickname' => '阿澈'],
            ['phone' => '13800000004', 'nickname' => '一只橘座'],
            ['phone' => '13800000005', 'nickname' => '林小满'],
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
        $assets->grantPoints($user, 1280, 'manual', 'campus-demo', '校园演示初始积分', 'campus-demo-user-points-v1', $admin);
        $assets->changeWallet($user, 5680, '校园演示初始余额', 'campus-demo-user-wallet-v1', $admin, 'adjust');

        UserAddress::query()->firstOrCreate(
            ['idempotency_key_hash' => hash('sha256', 'campus-demo-user-address-v1')],
            [
                'user_id' => $user->id,
                'recipient_name' => '体验用户',
                'phone' => '13800000001',
                'province' => '浙江省',
                'city' => '杭州市',
                'district' => '滨江区',
                'detail' => '滨江校区 8 号楼 508 室',
                'postal_code' => '310000',
                'is_default' => true,
            ],
        );
    }

    private function products(AdminUser $admin): void
    {
        $catalog = app(ProductCatalogService::class);
        $shipping = app(ShippingTemplateService::class);
        $inventory = app(InventoryService::class);

        // 真实校园商城分类（与在线 UI 设计稿一致）：每个分类至少挂一个在售商品，前端金刚区才会展示。
        $categoryDefinitions = [
            ['slug' => 'snacks-drinks', 'name' => '零食饮料', 'sort_order' => 1],
            ['slug' => 'digital-accessories', 'name' => '数码配件', 'sort_order' => 2],
            ['slug' => 'study-supplies', 'name' => '学习用品', 'sort_order' => 3],
            ['slug' => 'daily-goods', 'name' => '生活日用', 'sort_order' => 4],
            ['slug' => 'vouchers', 'name' => '卡券中心', 'sort_order' => 5],
        ];
        $categories = collect($categoryDefinitions)->mapWithKeys(function (array $item): array {
            $category = ProductCategory::query()->firstOrCreate(
                ['slug' => $item['slug']],
                ['name' => $item['name'], 'status' => 'active', 'sort_order' => $item['sort_order']],
            );
            // 旧环境可能已存在同名分类，补齐名称与排序。
            if ($category->name !== $item['name'] || (int) $category->sort_order !== $item['sort_order']) {
                $category->update(['name' => $item['name'], 'sort_order' => $item['sort_order'], 'status' => 'active']);
            }

            return [$item['slug'] => $category];
        });

        $shippingTemplate = $shipping->create($admin, [
            'name' => '校园内免运费',
            'charge_type' => 'fixed',
            'fixed_amount' => 0,
            'regions' => [['region_codes' => ['*'], 'deliverable' => true, 'remote_surcharge_amount' => 0]],
            'free_rules' => [['type' => 'cash_threshold', 'threshold_amount' => 0, 'target_ids' => []]],
            'enabled' => true,
        ], 'campus-demo-shipping-template-v1');

        // key 沿用历史幂等序号（1-6 为生产已有商品，7 为新增咖啡），不可调整顺序以免重复建品。
        // rank 决定商城展示顺序（按 published_at 倒序）：秒杀位=柠檬茶/咖啡/能量碗，榜单位=保温杯/耳机/词汇书。
        $products = [
            [
                'key' => 1, 'rank' => 7, 'category' => 'snacks-drinks', 'image' => '/content/products/lemon-tea.png',
                'name' => '手打柠檬茶', 'subtitle' => '校园人气饮品 · 到店兑换', 'description' => '凭券到校内茶饮店兑换手打柠檬茶一杯，现打现做，酸甜清爽，夏日必备。',
                'sku' => 'CAMPUS-LEMON-001', 'sku_name' => '标准杯 500ml', 'specs' => ['规格' => '标准杯', '容量' => '500ml'],
                'fulfillment_type' => 'virtual_delivery', 'shipping_template_id' => null, 'weight_grams' => null,
                'prices' => [990, 1200, 500, 800], 'stock' => 200,
            ],
            [
                'key' => 2, 'rank' => 4, 'category' => 'daily-goods', 'image' => '/content/products/thermos-bottle.png',
                'name' => 'ins 风保温杯 500ml', 'subtitle' => '高颜值随行杯', 'description' => 'ins 风简约设计，316 不锈钢内胆，保温 12 小时，适合图书馆自习。',
                'sku' => 'CAMPUS-CUP-001', 'sku_name' => '奶油白 500ml', 'specs' => ['颜色' => '奶油白', '容量' => '500ml'],
                'fulfillment_type' => 'logistics', 'shipping_template_id' => $shippingTemplate->public_id, 'weight_grams' => 350,
                'prices' => [3200, 3200, 1600, 1600], 'stock' => 120,
            ],
            [
                'key' => 3, 'rank' => 3, 'category' => 'digital-accessories', 'image' => '/content/products/noise-cancelling-headphones.png',
                'name' => '头戴式降噪耳机', 'subtitle' => '图书馆专注神器', 'description' => '主动降噪，佩戴舒适，续航 30 小时，帮你隔绝嘈杂专注学习。',
                'sku' => 'CAMPUS-HEADSET-001', 'sku_name' => '经典黑', 'specs' => ['颜色' => '经典黑', '续航' => '30h'],
                'fulfillment_type' => 'logistics', 'shipping_template_id' => $shippingTemplate->public_id, 'weight_grams' => 280,
                'prices' => [8990, 8990, 4500, 4500], 'stock' => 50,
            ],
            [
                'key' => 4, 'rank' => 2, 'category' => 'study-supplies', 'image' => '/content/products/english-vocab-book.png',
                'name' => '考研英语词汇书', 'subtitle' => '二手九成新', 'description' => '考研英语高频词汇手册，九成新无笔记，附赠记忆卡片。',
                'sku' => 'CAMPUS-BOOK-001', 'sku_name' => '第 3 版', 'specs' => ['版本' => '第 3 版', '成色' => '九成新'],
                'fulfillment_type' => 'logistics', 'shipping_template_id' => $shippingTemplate->public_id, 'weight_grams' => 500,
                'prices' => [1500, 1500, 800, 800], 'stock' => 30,
            ],
            [
                'key' => 5, 'rank' => 1, 'category' => 'vouchers', 'image' => '/content/products/print-voucher.png',
                'name' => '打印店 20 页打印券', 'subtitle' => '图书馆北门打印店', 'description' => '凭券到图书馆北门打印店兑换 20 页黑白打印，复习资料好帮手。',
                'sku' => 'CAMPUS-PRINT-001', 'sku_name' => '20 页黑白', 'specs' => ['页数' => '20页', '颜色' => '黑白'],
                'fulfillment_type' => 'virtual_delivery', 'shipping_template_id' => null, 'weight_grams' => null,
                'prices' => [600, 600, 300, 300], 'stock' => 500,
            ],
            [
                'key' => 6, 'rank' => 5, 'category' => 'snacks-drinks', 'image' => '/content/products/energy-salad-bowl.png',
                'name' => '轻食能量碗', 'subtitle' => '健康轻食套餐', 'description' => '鸡胸肉、牛油果、藜麦搭配，低脂高蛋白，健身后补充能量。',
                'sku' => 'CAMPUS-SALAD-001', 'sku_name' => '标准份', 'specs' => ['规格' => '标准份', '热量' => '约 350kcal'],
                'fulfillment_type' => 'logistics', 'shipping_template_id' => $shippingTemplate->public_id, 'weight_grams' => 400,
                'prices' => [1590, 1590, 800, 800], 'stock' => 80,
            ],
            [
                'key' => 7, 'rank' => 6, 'category' => 'snacks-drinks', 'image' => '/content/products/hand-drip-coffee.png',
                'name' => '精品手冲咖啡', 'subtitle' => '校园咖啡角现磨', 'description' => '中深烘焙阿拉比卡豆，校园咖啡角现磨手冲，坚果与黑巧风味，醇厚回甘。',
                'sku' => 'CAMPUS-COFFEE-001', 'sku_name' => '标准杯 350ml', 'specs' => ['规格' => '标准杯', '容量' => '350ml'],
                'fulfillment_type' => 'virtual_delivery', 'shipping_template_id' => null, 'weight_grams' => null,
                'prices' => [1290, 1500, 650, 800], 'stock' => 150,
            ],
        ];

        foreach ($products as $definition) {
            [$cash, $points, $mixedCash, $mixedPoints] = $definition['prices'];
            $product = $catalog->create($admin, [
                'category_id' => $categories[$definition['category']]->public_id,
                'name' => $definition['name'],
                'subtitle' => $definition['subtitle'],
                'description' => $definition['description'],
                'gallery_images' => [[
                    'url' => $definition['image'],
                    'alt' => $definition['name'],
                    'sort_order' => 0,
                ]],
                'skus' => [[
                    'sku_code' => $definition['sku'],
                    'name' => $definition['sku_name'],
                    'specs' => $definition['specs'],
                    'fulfillment_type' => $definition['fulfillment_type'],
                    'shipping_template_id' => $definition['shipping_template_id'],
                    'weight_grams' => $definition['weight_grams'],
                    'free_shipping' => $definition['fulfillment_type'] !== 'logistics',
                    'low_stock_threshold' => 10,
                    'limit_per_order' => 5,
                    'purchase_options' => [
                        ['purchase_mode' => 'cash', 'cash_amount' => $cash, 'points' => 0, 'enabled' => true],
                        ['purchase_mode' => 'points', 'cash_amount' => 0, 'points' => $points, 'enabled' => true],
                        ['purchase_mode' => 'cash_points', 'cash_amount' => $mixedCash, 'points' => $mixedPoints, 'enabled' => true],
                    ],
                ]],
            ], 'campus-demo-product-create-v1-'.$definition['key']);

            $sku = $product->skus()->firstOrFail();
            $stock = $inventory->ensure($sku);
            $inventory->adjust($admin, $sku, 'set', $definition['stock'], $stock->version, '设置校园演示库存', 'campus-demo-inventory-v1-'.$definition['key']);
            $catalog->transition($admin, $product, 'publish', '发布校园演示商品', 'campus-demo-product-publish-v1-'.$definition['key']);
        }

        // 旧数据回填：早期演示商品无挂图且统一挂在「校园精选」分类。
        // 幂等创建会直接返回旧记录，这里按 SKU 编码把真实分类、商品图、名称与展示顺序补到已有商品上，可重复执行。
        foreach ($products as $definition) {
            $product = Product::query()
                ->whereHas('skus', fn ($query) => $query->where('sku_code', $definition['sku']))
                ->first();
            if ($product === null) {
                continue;
            }

            $gallery = [['url' => $definition['image'], 'alt' => $definition['name'], 'sort_order' => 0]];
            $product->fill([
                'product_category_id' => $categories[$definition['category']]->id,
                'name' => $definition['name'],
                'subtitle' => $definition['subtitle'],
                'gallery_images' => $gallery,
            ]);
            if ($product->status === 'on_sale') {
                $product->published_at = now()->startOfDay()->addMinutes(60 + $definition['rank']);
            }
            $product->save();
        }
    }

    /** @param array<int, User> $users */
    private function community(array $users, AdminUser $admin): void
    {
        $community = app(CommunityContentService::class);

        $posts = [
            ['宿舍楼下的橘座又来巡逻了，今天心情好像不错，主动蹭了我三次 #校园日常', '一只橘座'],
            ['出租屋下午四点的阳光真的绝了，周末宅家幸福感拉满 #周末日常', '阿澈'],
            ['图书馆三楼靠窗的位置今天居然没人，学习效率直接翻倍', '小满同学'],
            ['二食堂三楼的黄焖鸡米饭加辣加蛋，我可以连吃一周', '林小满'],
            ['求带饭！二食堂三楼的黄焖鸡，送到 6 号楼 508，辛苦费好说～ #跑腿代办', '林小满'],
            ['高数期中复习搭子，每晚 7-9 点图书馆，互相监督刷题', '小满同学'],
            ['西门菜鸟驿站取 2 个快递，送到 3 号宿舍楼，加急', '阿澈'],
            ['失物招领：图书馆三楼丢了一串钥匙，带棕色小熊挂件，拾到请联系', '一只橘座'],
        ];

        foreach ($posts as $index => [$title, $nickname]) {
            $author = collect($users)->firstWhere('nickname', $nickname) ?? $users[$index % count($users)];
            $post = $community->createPost($author, ['title' => $title, 'content' => $title], 'campus-demo-community-post-v1-'.($index + 1));
            if ($post->status === 'pending_review') {
                $post = $community->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '校园演示内容审核通过。']);
            }

            $commenter = $users[($index + 1) % count($users)];
            $comments = [
                '这也太可爱了吧，明天我也去蹲点',
                '阳光正好，适合发呆',
                '三楼靠窗 yyds，就是插座有点少',
                '黄焖鸡 +1，加蛋加辣是灵魂',
                '已接，马上到',
                '我来！每晚都能到',
                '取件码私我',
                '钥匙找到了，联系管理员领取',
            ];
            $community->createComment($commenter, $post, ['content' => $comments[$index] ?? '顶一个'], 'campus-demo-community-comment-v1-'.($index + 1));
        }
    }
}
