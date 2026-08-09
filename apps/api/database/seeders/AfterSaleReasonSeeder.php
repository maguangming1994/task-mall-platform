<?php

namespace Database\Seeders;

use App\Modules\Refund\Models\AfterSaleReason;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AfterSaleReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['buyer_request', '买家申请退款', 'buyer', 'buyer', false],
            ['no_longer_wanted', '不喜欢/不想要了', 'buyer', 'buyer', false],
            ['ordered_wrong_item', '选错规格/买错商品', 'buyer', 'buyer', false],
            ['duplicate_purchase', '重复购买', 'buyer', 'buyer', false],
            ['seven_day_no_reason', '七天无理由退货', 'buyer', 'buyer', true],
            ['quality_problem', '商品存在质量问题', 'seller', 'seller', true],
            ['not_as_described', '与商品描述不符', 'seller', 'seller', true],
            ['wrong_item_sent', '卖家发错商品/规格', 'seller', 'seller', true],
            ['missing_parts', '少件/缺少配件或赠品', 'seller', 'seller', true],
            ['expired_or_unusable', '过期、变质或无法使用', 'seller', 'seller', true],
            ['damaged_in_transit', '运输破损/污染/漏液', 'carrier', 'carrier', true],
            ['package_lost', '包裹丢失/一直未收到', 'carrier', 'carrier', true],
            ['empty_or_short_package', '空包裹/包裹明显少件', 'pending', 'pending', true],
            ['other_negotiated', '其他/已与客服协商', 'negotiated', 'negotiated', false],
        ];
        foreach ($reasons as $sort => [$code, $name, $responsibility, $payer, $evidence]) {
            $reason = AfterSaleReason::query()->firstOrNew(['code' => $code]);
            $reason->public_id ??= (string) Str::ulid();
            $reason->fill([
                'name' => $name,
                'service_types' => ['refund_only', 'return_and_refund', 'exchange'],
                'default_responsibility' => $responsibility,
                'return_shipping_payer' => $payer,
                'evidence_required' => $evidence,
                'enabled' => true,
                'sort_order' => $sort + 1,
            ])->save();
        }
    }
}
