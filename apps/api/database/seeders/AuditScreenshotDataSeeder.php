<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Account\Models\UserAddress;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Auth\Services\PhoneVerificationService;
use App\Modules\Campus\Models\Campus;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Community\Models\CommunityActivity;
use App\Modules\Community\Models\CommunityActivityParticipant;
use App\Modules\Fulfillment\Models\Fulfillment;
use App\Modules\Order\Models\CheckoutPreview;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderBatch;
use App\Modules\Product\Models\ProductSku;
use App\Modules\Refund\Models\AfterSaleReason;
use App\Modules\Refund\Models\Refund;
use App\Modules\SecondHand\Models\SecondHandCategory;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandOrder;
use App\Modules\SecondHand\Models\SecondHandRefund;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AuditScreenshotDataSeeder extends Seeder
{
    private User $user;

    private int $campusId;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Audit screenshot data can only be seeded in local or testing environments.');
        }

        $this->campusId = (int) (Campus::query()->where('name', '南昌大学')->value('id')
            ?? Campus::query()->where('name', 'like', '%南昌大学%')->value('id'));

        $this->user = $this->user();
        $address = $this->address();
        $sellerA = User::query()->whereKey(1)->first() ?? User::query()->orderBy('id')->firstOrFail();
        $buyerB = User::query()->where('nickname', '林小满')->first() ?? User::query()->whereKeyNot($this->user->id)->orderByDesc('id')->firstOrFail();
        $chatB = User::query()->where('nickname', '阿澈')->first() ?? User::query()->whereKeyNot([$this->user->id, $buyerB->id])->orderBy('id')->firstOrFail();

        $myItems = $this->secondHandItems();
        $theirItems = $this->secondHandItemsForSeller($sellerA);
        $orders = $this->secondHandOrders($myItems, $theirItems, $sellerA, $buyerB, $address);
        $this->secondHandRefund($orders['buy_paid_refund']);
        $mall = $this->mallOrders($address);
        $this->mallRefund($mall['shipping']);
        $this->coupons();
        $this->cart();
        $this->activities($buyerB);
        $this->chats($myItems['on_sale_1'], $buyerB, $chatB);

        $this->command?->info(sprintf(
            'Audit screenshot data ready for user #%d (%s). Mall shipped order: %s, completed order: %s. SH completed order: %s, paid seller order: %s, refund: %s, mall refund: %s.',
            $this->user->id,
            $this->user->nickname,
            $mall['shipping']->public_id,
            $mall['completed']->public_id,
            $orders['buy_completed']->public_id,
            $orders['sell_paid']->public_id,
            $orders['buy_paid_refund']->refund->public_id,
            $mall['shipping']->refunds->first()?->public_id,
        ));
    }

    private function user(): User
    {
        $phones = app(PhoneVerificationService::class);
        $user = User::query()->firstOrCreate(
            ['phone_hash' => $phones->phoneHash('13911112222')],
            [
                'nickname' => '麻广明',
                'phone' => '13911112222',
                'phone_verified_at' => now(),
                'profile_completed_at' => now(),
                'status' => 'active',
                'campus_id' => $this->campusId,
            ],
        );
        DB::table('users')->where('id', $user->id)->update([
            'nickname' => '麻广明',
            'campus_id' => $this->campusId,
            'can_publish_tasks' => true,
            'task_publish_permission_granted_at' => now(),
            'status' => 'active',
            'profile_completed_at' => $user->profile_completed_at ?? now(),
            'updated_at' => now(),
        ]);

        $assets = app(AssetLedgerService::class);
        $admin = AdminUser::query()->first();
        $assets->grantPoints($user, 8000, 'manual', 'audit-screenshot', 'UI 截图审计初始积分', 'audit-screenshot-points-v1', $admin);
        $assets->changeWallet($user, 50000, 'UI 截图审计初始余额', 'audit-screenshot-wallet-v1', $admin, 'adjust');

        return $user->fresh();
    }

    private function address(): UserAddress
    {
        return UserAddress::query()->firstOrCreate(
            ['idempotency_key_hash' => hash('sha256', 'audit-screenshot-address-v1')],
            [
                'user_id' => $this->user->id,
                'recipient_name' => '麻广明',
                'phone' => '13911112222',
                'province' => '江西省',
                'city' => '南昌市',
                'district' => '红谷滩区',
                'detail' => '南昌大学前湖校区学生宿舍 3 栋 502',
                'postal_code' => '330031',
                'is_default' => true,
            ],
        );
    }

    /** @return array<string, SecondHandItem> */
    private function secondHandItems(): array
    {
        $cat = fn (string $name) => SecondHandCategory::query()->where('name', $name)->value('id');

        $definitions = [
            'on_sale_1' => ['title' => '九成新蓝牙耳机 降噪款', 'price' => 4500, 'condition' => 'like_new', 'status' => 'on_sale', 'category_id' => $cat('数码电子'), 'location' => '南昌大学前湖校区', 'days_ago' => 2],
            'on_sale_2' => ['title' => '考研英语全套资料 2026 版', 'price' => 3000, 'condition' => 'very_good', 'status' => 'on_sale', 'category_id' => $cat('教材书籍'), 'location' => '南昌大学图书馆', 'days_ago' => 3],
            'sold_1' => ['title' => '宜家小台灯 暖光', 'price' => 1500, 'condition' => 'good', 'status' => 'sold', 'category_id' => $cat('生活用品'), 'location' => '南昌大学前湖校区', 'days_ago' => 10],
            'off_shelf_1' => ['title' => '折叠自行车 20 寸', 'price' => 12000, 'condition' => 'good', 'status' => 'off_shelf', 'category_id' => $cat('运动户外'), 'location' => '南昌大学前湖校区', 'days_ago' => 15],
            'reserved_1' => ['title' => '机械键盘 青轴 87 键', 'price' => 8000, 'condition' => 'very_good', 'status' => 'reserved', 'category_id' => $cat('数码电子'), 'location' => '南昌大学前湖校区', 'days_ago' => 4],
        ];

        $items = [];
        foreach ($definitions as $key => $definition) {
            $item = SecondHandItem::query()->firstOrCreate(
                ['seller_user_id' => $this->user->id, 'idempotency_key_hash' => hash('sha256', "audit-sh-item-v1-{$key}")],
                [
                    'campus_id' => $this->campusId,
                    'category_id' => $definition['category_id'],
                    'title' => $definition['title'],
                    'description' => $definition['title'].'，自用闲置，成色如实描述，支持校内当面验货交易。',
                    'price' => $definition['price'],
                    'condition' => $definition['condition'],
                    'images' => [],
                    'trade_type' => 'meetup',
                    'location' => $definition['location'],
                    'status' => $definition['status'],
                    'audit_status' => 'approved',
                    'view_count' => 36,
                    'favorite_count' => 4,
                ],
            );
            if ($item->wasRecentlyCreated) {
                $item->timestamps = false;
                $item->forceFill(['created_at' => now()->subDays($definition['days_ago']), 'updated_at' => now()->subDays($definition['days_ago'])])->save();
                $item->timestamps = true;
            }
            $items[$key] = $item;
        }

        return $items;
    }

    /** @return array<string, SecondHandItem> */
    private function secondHandItemsForSeller(User $seller): array
    {
        $cat = SecondHandCategory::query()->where('name', '数码电子')->value('id');
        $definitions = [
            'for_pending' => ['title' => 'iPad mini 6 64G 深空灰', 'price' => 150000, 'status' => 'on_sale'],
            'for_shipped' => ['title' => '宿舍用小冰箱 38L', 'price' => 20000, 'status' => 'reserved'],
            'for_completed' => ['title' => '高等数学教材 同济第八版', 'price' => 1000, 'status' => 'sold'],
            'for_refund' => ['title' => '索尼头戴降噪耳机 XM4', 'price' => 50000, 'status' => 'reserved'],
        ];

        $items = [];
        foreach ($definitions as $key => $definition) {
            $items[$key] = SecondHandItem::query()->firstOrCreate(
                ['seller_user_id' => $seller->id, 'idempotency_key_hash' => hash('sha256', "audit-sh-peer-item-v1-{$key}")],
                [
                    'campus_id' => $this->campusId,
                    'category_id' => $cat,
                    'title' => $definition['title'],
                    'description' => $definition['title'].'，毕业出闲置，校内自提优先。',
                    'price' => $definition['price'],
                    'condition' => 'good',
                    'images' => [],
                    'trade_type' => 'meetup',
                    'location' => '南昌大学前湖校区',
                    'status' => $definition['status'],
                    'audit_status' => 'approved',
                    'view_count' => 58,
                    'favorite_count' => 7,
                ],
            );
        }

        return $items;
    }

    /**
     * @param  array<string, SecondHandItem>  $myItems
     * @param  array<string, SecondHandItem>  $theirItems
     * @return array<string, SecondHandOrder>
     */
    private function secondHandOrders(array $myItems, array $theirItems, User $sellerA, User $buyerB, UserAddress $address): array
    {
        $snapshot = [
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'province' => $address->province,
            'city' => $address->city,
            'district' => $address->district,
            'detail' => $address->detail,
        ];
        $make = function (string $key, SecondHandItem $item, User $buyer, User $seller, string $status, array $times) use ($snapshot): SecondHandOrder {
            $order = SecondHandOrder::query()->firstOrCreate(
                ['idempotency_key_hash' => hash('sha256', "audit-sh-order-v1-{$key}")],
                [
                    'buyer_user_id' => $buyer->id,
                    'seller_user_id' => $seller->id,
                    'item_id' => $item->id,
                    'title_snapshot' => $item->title,
                    'cover_snapshot' => $item->cover_file_public_id,
                    'price' => $item->price,
                    'trade_type' => 'meetup',
                    'address_snapshot' => $snapshot,
                    'buyer_message' => '下课后面交，麻烦预留。',
                    'status' => $status,
                    'cash_payment_method' => 'wallet',
                    'paid_amount' => $status === 'pending_payment' ? 0 : $item->price,
                    'paid_at' => $times['paid'] ?? null,
                    'shipped_at' => $times['shipped'] ?? null,
                    'completed_at' => $times['completed'] ?? null,
                ],
            );
            if ($order->wasRecentlyCreated) {
                $order->timestamps = false;
                $order->forceFill(['created_at' => $times['created'], 'updated_at' => $times['created']])->save();
                $order->timestamps = true;
            }

            return $order;
        };

        return [
            'buy_pending' => $make('buy-pending', $theirItems['for_pending'], $this->user, $sellerA, 'pending_payment', ['created' => now()->subHours(6)]),
            'buy_shipped' => $make('buy-shipped', $theirItems['for_shipped'], $this->user, $sellerA, 'shipped', ['created' => now()->subDays(3), 'paid' => now()->subDays(3)->addHour(), 'shipped' => now()->subDays(2)]),
            'buy_completed' => $make('buy-completed', $theirItems['for_completed'], $this->user, $sellerA, 'completed', ['created' => now()->subDays(6), 'paid' => now()->subDays(6)->addHour(), 'shipped' => now()->subDays(5), 'completed' => now()->subDays(2)]),
            'buy_paid_refund' => $make('buy-paid-refund', $theirItems['for_refund'], $this->user, $sellerA, 'paid', ['created' => now()->subDay(), 'paid' => now()->subDay()->addHour()]),
            'sell_paid' => $make('sell-paid', $myItems['reserved_1'], $buyerB, $this->user, 'paid', ['created' => now()->subHours(20), 'paid' => now()->subHours(19)]),
        ];
    }

    private function secondHandRefund(SecondHandOrder $order): SecondHandRefund
    {
        return SecondHandRefund::query()->firstOrCreate(
            ['order_id' => $order->id],
            [
                'buyer_user_id' => $order->buyer_user_id,
                'seller_user_id' => $order->seller_user_id,
                'amount' => $order->price,
                'reason' => '与描述不符，耳机续航明显不足，申请退款。',
                'images' => [],
                'status' => 'pending',
                'auto_approve_at' => now()->addDays(3),
            ],
        );
    }

    /** @return array{shipping: Order, completed: Order} */
    private function mallOrders(UserAddress $address): array
    {
        $admin = AdminUser::query()->firstOrFail();
        $snapshot = [
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'province' => $address->province,
            'city' => $address->city,
            'district' => $address->district,
            'detail' => $address->detail,
            'postal_code' => $address->postal_code,
        ];

        $preview = CheckoutPreview::query()->firstOrCreate(
            ['token_hash' => hash('sha256', 'audit-mall-preview-v1')],
            [
                'user_id' => $this->user->id,
                'request_hash' => hash('sha256', 'audit-mall-preview-request-v1'),
                'request_snapshot' => ['source' => 'audit-screenshot-seeder'],
                'pricing_snapshot' => ['items_points' => 3400, 'payable_points' => 3400],
                'expires_at' => now()->addHour(),
                'consumed_at' => now(),
            ],
        );

        $batch = OrderBatch::query()->firstOrCreate(
            ['idempotency_key_hash' => hash('sha256', 'audit-mall-batch-v1')],
            [
                'batch_no' => 'B'.now()->format('YmdHis').strtoupper(Str::random(10)),
                'checkout_preview_id' => $preview->id,
                'user_id' => $this->user->id,
                'client_platform' => 'h5-browser',
                'capability_version' => 1,
                'status' => 'active',
                'items_cash_amount' => 0,
                'items_points' => 3400,
                'cash_discount_amount' => 0,
                'points_discount' => 0,
                'shipping_amount' => 0,
                'payable_cash_amount' => 0,
                'payable_points' => 3400,
            ],
        );

        $skuShip = ProductSku::query()->with('product', 'purchaseOptions')->where('fulfillment_type', 'logistics')->whereHas('product', fn ($q) => $q->where('status', 'on_sale'))->orderBy('id')->firstOrFail();
        $skuVirtual = ProductSku::query()->with('product', 'purchaseOptions')->where('fulfillment_type', 'virtual_delivery')->whereHas('product', fn ($q) => $q->where('status', 'on_sale'))->orderBy('id')->firstOrFail();

        $shipping = $this->mallOrder($batch, $skuShip, 'shipping', 'fulfilled', $snapshot, [
            'created' => now()->subDays(3), 'paid' => now()->subDays(3), 'shipped' => now()->subDays(2),
        ]);
        $completed = $this->mallOrder($batch, $skuVirtual, 'completed', 'completed', $snapshot, [
            'created' => now()->subDays(4), 'paid' => now()->subDays(4), 'shipped' => now()->subDays(4), 'completed' => now()->subDay(),
        ]);

        if ($shipping->fulfillments()->doesntExist()) {
            $fulfillment = Fulfillment::query()->create([
                'fulfillment_no' => 'F'.now()->format('YmdHis').strtoupper(Str::random(8)),
                'order_id' => $shipping->id,
                'method' => 'logistics',
                'status' => 'shipped',
                'carrier_code' => 'ZTO',
                'carrier_name' => '中通快递',
                'tracking_no' => 'ZT'.now()->format('Ymd').'668801',
                'idempotency_key_hash' => hash('sha256', 'audit-mall-fulfillment-v1-shipping'),
                'created_by_admin_id' => $admin->id,
                'fulfilled_at' => now()->subDays(2),
            ]);
            DB::table('fulfillment_items')->insert([
                'fulfillment_id' => $fulfillment->id,
                'order_item_id' => $shipping->items->first()->id,
                'quantity' => $shipping->items->first()->quantity,
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ]);
        }
        if ($completed->fulfillments()->doesntExist()) {
            $fulfillment = Fulfillment::query()->create([
                'fulfillment_no' => 'F'.now()->format('YmdHis').strtoupper(Str::random(8)),
                'order_id' => $completed->id,
                'method' => 'virtual_delivery',
                'status' => 'delivered',
                'no_logistics_reason' => '电子兑换券即时到账',
                'virtual_delivery_snapshot' => ['voucher_no' => 'LMTEA-2026-1001', 'note' => '凭兑换码到店核销'],
                'idempotency_key_hash' => hash('sha256', 'audit-mall-fulfillment-v1-completed'),
                'created_by_admin_id' => $admin->id,
                'fulfilled_at' => now()->subDays(4),
            ]);
            DB::table('fulfillment_items')->insert([
                'fulfillment_id' => $fulfillment->id,
                'order_item_id' => $completed->items->first()->id,
                'quantity' => $completed->items->first()->quantity,
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subDays(4),
            ]);
        }

        return ['shipping' => $shipping->fresh(['items', 'fulfillments']), 'completed' => $completed->fresh(['items', 'fulfillments'])];
    }

    private function mallOrder(OrderBatch $batch, ProductSku $sku, string $key, string $status, array $snapshot, array $times): Order
    {
        $option = $sku->purchaseOptions->firstWhere('purchase_mode', 'points') ?? $sku->purchaseOptions->first();
        $mode = $option?->purchase_mode ?? 'points';
        $unitCash = (int) ($option?->cash_amount ?? 0);
        $unitPoints = (int) ($option?->points ?? 1000);
        $quantity = 1;

        $order = Order::query()->firstOrCreate(
            ['order_no' => 'OAUDIT'.str_pad((string) (crc32($key) % 100000000000), 12, '0', STR_PAD_LEFT)],
            [
                'order_batch_id' => $batch->id,
                'user_id' => $this->user->id,
                'fulfillment_type' => $sku->fulfillment_type,
                'status' => $status,
                'cash_payment_method' => 'wallet',
                'address_snapshot' => $snapshot,
                'items_cash_amount' => $unitCash * $quantity,
                'items_points' => $unitPoints * $quantity,
                'cash_discount_amount' => 0,
                'points_discount' => 0,
                'shipping_amount' => 0,
                'payable_cash_amount' => $unitCash * $quantity,
                'payable_points' => $unitPoints * $quantity,
                'paid_wallet_amount' => 0,
                'paid_external_amount' => 0,
                'paid_points' => $unitPoints * $quantity,
                'paid_at' => $times['paid'],
                'shipped_at' => $times['shipped'] ?? null,
                'auto_complete_at' => $status === 'fulfilled' ? now()->addDays(5) : null,
                'completed_at' => $times['completed'] ?? null,
            ],
        );
        if ($order->wasRecentlyCreated || $order->items()->doesntExist()) {
            $order->items()->create([
                'product_sku_id' => $sku->id,
                'product_public_id' => $sku->product->public_id,
                'sku_public_id' => $sku->public_id,
                'product_name' => $sku->product->name,
                'sku_name' => $sku->name,
                'specs_snapshot' => $sku->specs,
                'image_file_public_id' => $sku->image_file_public_id ?? null,
                'purchase_mode' => $mode,
                'fulfillment_type' => $sku->fulfillment_type,
                'quantity' => $quantity,
                'unit_cash_amount' => $unitCash,
                'unit_points' => $unitPoints,
                'cash_subtotal' => $unitCash * $quantity,
                'points_subtotal' => $unitPoints * $quantity,
                'cash_discount_allocated' => 0,
                'points_discount_allocated' => 0,
                'shipping_allocated' => 0,
                'paid_cash_amount' => $unitCash * $quantity,
                'paid_points' => $unitPoints * $quantity,
                'purchase_limit_snapshot' => ['per_order' => $sku->limit_per_order ?? null, 'per_day' => $sku->limit_per_day ?? null, 'campaign' => null, 'lifetime' => null],
            ]);
            $order->timestamps = false;
            $order->forceFill(['created_at' => $times['created'], 'updated_at' => $times['created']])->save();
            $order->timestamps = true;
            DB::table('order_status_changes')->insert([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => $status,
                'event' => 'created',
                'reason' => 'UI 截图审计种子订单。',
                'created_at' => $times['created'],
                'updated_at' => $times['created'],
            ]);
        }

        return $order->fresh('items');
    }

    private function mallRefund(Order $order): Refund
    {
        $reason = AfterSaleReason::query()->where('code', 'buyer_request')->firstOrFail();
        $item = $order->items->firstOrFail();

        $refund = Refund::query()->firstOrCreate(
            ['idempotency_key_hash' => hash('sha256', 'audit-mall-refund-v1')],
            [
                'refund_no' => 'R'.now()->format('YmdHis').strtoupper(Str::random(8)),
                'order_id' => $order->id,
                'user_id' => $this->user->id,
                'service_type' => 'refund_only',
                'reason_code' => $reason->code,
                'reason_name_snapshot' => $reason->name,
                'description' => '拍错了，想换成其他商品，申请仅退款。',
                'status' => 'submitted',
                'refund_cash_amount' => 0,
                'refund_wallet_amount' => 0,
                'refund_external_amount' => 0,
                'refund_points' => $item->paid_points,
                'coupon_returned' => false,
                'responsibility_party' => $reason->default_responsibility,
                'return_shipping_payer' => $reason->return_shipping_payer,
                'return_method' => null,
                'reason_rule_snapshot' => [
                    'code' => $reason->code,
                    'name' => $reason->name,
                    'service_types' => $reason->service_types,
                    'default_responsibility' => $reason->default_responsibility,
                    'return_shipping_payer' => $reason->return_shipping_payer,
                ],
                'evidence_file_ids' => [],
                'original_shipping_refunded' => false,
                'request_fingerprint_hash' => hash('sha256', 'audit-mall-refund-fingerprint-v1'),
            ],
        );
        if ($refund->wasRecentlyCreated) {
            DB::table('refund_items')->insert([
                'refund_id' => $refund->id,
                'order_item_id' => $item->id,
                'quantity' => $item->quantity,
                'refund_cash_amount' => 0,
                'refund_points' => $item->paid_points,
                'remaining_refundable_snapshot' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $refund;
    }

    private function coupons(): void
    {
        $admin = AdminUser::query()->firstOrFail();
        $assets = app(AssetLedgerService::class);

        $definitions = [
            'cash5' => ['name' => '满 20 减 5 元现金券', 'cash_discount_cents' => 500, 'points_discount' => 0, 'minimum_cash_cents' => 2000, 'minimum_points' => 0, 'issue' => true],
            'cash2' => ['name' => '满 10 减 2 元现金券', 'cash_discount_cents' => 200, 'points_discount' => 0, 'minimum_cash_cents' => 1000, 'minimum_points' => 0, 'issue' => true],
            'points100' => ['name' => '100 积分抵扣券', 'cash_discount_cents' => 0, 'points_discount' => 100, 'minimum_cash_cents' => 0, 'minimum_points' => 200, 'issue' => true],
            'cash10' => ['name' => '满 50 减 10 元现金券', 'cash_discount_cents' => 1000, 'points_discount' => 0, 'minimum_cash_cents' => 5000, 'minimum_points' => 0, 'issue' => false],
            'points300' => ['name' => '300 积分抵扣券', 'cash_discount_cents' => 0, 'points_discount' => 300, 'minimum_cash_cents' => 0, 'minimum_points' => 1000, 'issue' => false],
            'free_shipping' => ['name' => '免运费券（抵 6 元）', 'cash_discount_cents' => 600, 'points_discount' => 0, 'minimum_cash_cents' => 0, 'minimum_points' => 0, 'issue' => false],
        ];

        foreach ($definitions as $key => $definition) {
            $template = CouponTemplate::query()->firstOrCreate(
                ['idempotency_key_hash' => hash('sha256', "audit-coupon-template-v1-{$key}")],
                [
                    'name' => $definition['name'],
                    'cash_discount_cents' => $definition['cash_discount_cents'],
                    'points_discount' => $definition['points_discount'],
                    'minimum_cash_cents' => $definition['minimum_cash_cents'],
                    'minimum_points' => $definition['minimum_points'],
                    'valid_days' => 30,
                    'total_quantity' => 500,
                    'status' => 'active',
                    'created_by_admin_id' => $admin->id,
                ],
            );
            if ($definition['issue']) {
                $assets->issueCoupons($this->user, $template, 1, 'UI 截图审计发放优惠券', "audit-coupon-issue-v1-{$key}", $admin);
            }
        }
    }

    private function cart(): void
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $this->user->id]);
        $skus = ProductSku::query()->whereHas('product', fn ($q) => $q->where('status', 'on_sale'))->orderBy('id')->limit(3)->get();
        $rows = [
            ['sku' => $skus->get(0), 'purchase_mode' => 'points', 'quantity' => 1],
            ['sku' => $skus->get(1) ?? $skus->get(0), 'purchase_mode' => 'cash', 'quantity' => 2],
        ];
        foreach ($rows as $row) {
            CartItem::query()->firstOrCreate(
                ['cart_id' => $cart->id, 'product_sku_id' => $row['sku']->id, 'purchase_mode' => $row['purchase_mode']],
                ['quantity' => $row['quantity'], 'selected' => true],
            );
        }
    }

    private function activities(User $partner): void
    {
        $admin = AdminUser::query()->first();
        $definitions = [
            'study' => [
                'category_code' => 'study',
                'title' => '期末图书馆自习打卡小队',
                'details' => "每天晚上 19:00-22:00 在图书馆三楼自习区组队打卡，坚持 7 天。\n奖励：完成全部打卡的成员平分 2000 积分，并赠送「自律达人」徽章。\n规则：需到场拍照打卡，中途退出不退还报名积分；请保持安静，遵守图书馆秩序。",
                'location' => '南昌大学前湖校区图书馆三楼自习区',
                'starts_at' => now()->addDays(2)->setTime(19, 0),
                'participant_limit' => 20,
                'participant_count' => 5,
            ],
            'sports' => [
                'category_code' => 'sports',
                'title' => '周末羽毛球双打约球',
                'details' => "周六下午体育馆羽毛球场双打局，水平不限，轮流上场。\n奖励：参与即可获得 50 积分，赢球组合额外 100 积分。\n规则：自带球拍，场地费 AA；请提前 10 分钟到场热身。",
                'location' => '南昌大学前湖校区体育馆 2 号羽毛球场',
                'starts_at' => now()->addDays(4)->setTime(15, 0),
                'participant_limit' => 8,
                'participant_count' => 2,
            ],
        ];

        foreach ($definitions as $key => $definition) {
            $activity = CommunityActivity::query()->firstOrCreate(
                ['idempotency_key_hash' => hash('sha256', "audit-activity-v1-{$key}")],
                [
                    'campus_id' => $this->campusId,
                    'creator_user_id' => $this->user->id,
                    'category_code' => $definition['category_code'],
                    'title' => $definition['title'],
                    'details' => $definition['details'],
                    'location' => $definition['location'],
                    'starts_at' => $definition['starts_at'],
                    'participant_limit' => $definition['participant_limit'],
                    'participant_count' => $definition['participant_count'],
                    'status' => 'approved',
                    'reviewed_by_admin_id' => $admin?->id,
                    'reviewed_at' => now()->subDay(),
                ],
            );
            CommunityActivityParticipant::query()->firstOrCreate(
                ['community_activity_id' => $activity->id, 'user_id' => $this->user->id],
                ['status' => 'joined', 'joined_at' => now()->subDay()],
            );
            if ($key === 'study') {
                CommunityActivityParticipant::query()->firstOrCreate(
                    ['community_activity_id' => $activity->id, 'user_id' => $partner->id],
                    ['status' => 'joined', 'joined_at' => now()->subHours(20)],
                );
            }
        }
    }

    private function chats(SecondHandItem $item, User $buyerB, User $chatB): void
    {
        $me = $this->user;

        $conv1 = ChatConversation::query()->firstOrCreate(
            ['user_a_id' => $me->id, 'user_b_id' => $buyerB->id, 'item_id' => $item->id],
            ['last_message_type' => 'text'],
        );
        if ($conv1->messages()->count() === 0) {
            $script = [
                [$buyerB, '你好，键盘还在吗？可以校内自提吗？', true, 26],
                [$me, '在的，前湖校区随时可以自提。', true, 25],
                [$buyerB, '能便宜一点吗？学生党预算有限。', true, 24],
                [$buyerB, '75 块我现在就可以下单～', false, 2],
                [$buyerB, '在忙吗？看到麻烦回复一下', false, 1],
            ];
            $this->seedMessages($conv1, $script);
        }
        $this->syncConversation($conv1, 2, 0);

        $conv2 = ChatConversation::query()->firstOrCreate(
            ['user_a_id' => $me->id, 'user_b_id' => $chatB->id, 'item_id' => null],
            ['last_message_type' => 'text'],
        );
        if ($conv2->messages()->count() === 0) {
            $script = [
                [$chatB, '明天的自习打卡活动你报名了吗？', true, 30],
                [$me, '报了，图书馆三楼见。', true, 29],
                [$chatB, '好，到时候帮你占座。', true, 28],
            ];
            $this->seedMessages($conv2, $script);
        }
        $this->syncConversation($conv2, 0, 0);
    }

    private function syncConversation(ChatConversation $conversation, int $unreadA, int $unreadB): void
    {
        $last = ChatMessage::query()->where('conversation_id', $conversation->id)->orderByDesc('id')->first();
        if ($last === null) {
            return;
        }
        $conversation->update([
            'last_message' => $last->content,
            'last_message_type' => 'text',
            'last_message_id' => $last->id,
            'last_message_at' => $last->created_at,
            'unread_a' => $unreadA,
            'unread_b' => $unreadB,
        ]);
    }

    /** @param array<int, array{0: User, 1: string, 2: bool, 3: int}> $script */
    private function seedMessages(ChatConversation $conversation, array $script): void
    {
        foreach ($script as [$sender, $content, $read, $hoursAgo]) {
            $message = ChatMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'type' => 'text',
                'content' => $content,
                'read_at' => $read ? now()->subHours($hoursAgo)->addMinutes(5) : null,
            ]);
            $message->timestamps = false;
            $message->forceFill(['created_at' => now()->subHours($hoursAgo), 'updated_at' => now()->subHours($hoursAgo)])->save();
            $message->timestamps = true;
        }
    }
}
