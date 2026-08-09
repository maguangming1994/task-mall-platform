<?php

namespace Database\Seeders;

use App\Modules\Settings\Models\SystemSettingDefinition;
use Illuminate\Database\Seeder;

class SystemSettingDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            ['points.global_validity_days', 'points', '积分默认有效期', 'integer', '天', 365, ['min' => 1, 'max' => 3650], 'normal'],
            ['points.expiry_reminder_days', 'points', '积分到期提醒节点', 'multi_enum', '天', ['7', '1'], null, 'normal', ['30', '15', '7', '3', '1']],
            ['points.source_validity_days', 'points', '各来源积分有效期', 'object', '天', ['sign_in' => 90, 'post' => 90, 'comment' => 60, 'profile' => 365, 'referral' => 365, 'first_task' => 365], ['required_keys' => ['sign_in', 'post', 'comment', 'profile', 'referral', 'first_task'], 'properties' => ['sign_in' => ['type' => 'integer', 'min' => 1, 'max' => 3650], 'post' => ['type' => 'integer', 'min' => 1, 'max' => 3650], 'comment' => ['type' => 'integer', 'min' => 1, 'max' => 3650], 'profile' => ['type' => 'integer', 'min' => 1, 'max' => 3650], 'referral' => ['type' => 'integer', 'min' => 1, 'max' => 3650], 'first_task' => ['type' => 'integer', 'min' => 1, 'max' => 3650]]], 'normal'],
            ['points.source_reward_amounts', 'points', '各来源积分奖励数量', 'object', '积分', ['sign_in' => 5, 'post' => 10, 'comment' => 2, 'profile' => 100, 'referral' => 500], ['required_keys' => ['sign_in', 'post', 'comment', 'profile', 'referral'], 'properties' => ['sign_in' => ['type' => 'integer', 'min' => 0, 'max' => 100000], 'post' => ['type' => 'integer', 'min' => 0, 'max' => 100000], 'comment' => ['type' => 'integer', 'min' => 0, 'max' => 100000], 'profile' => ['type' => 'integer', 'min' => 0, 'max' => 100000], 'referral' => ['type' => 'integer', 'min' => 0, 'max' => 100000]]], 'high'],
            ['tasks.default_submission_limit', 'tasks', '任务默认提交次数', 'integer', '次', 3, ['min' => 1, 'max' => 20], 'normal'],
            ['tasks.default_review_hours', 'tasks', '任务默认审核期限', 'integer', '小时', 48, ['min' => 1, 'max' => 720], 'normal'],
            ['tasks.default_appeal_days', 'tasks', '任务默认申诉期限', 'integer', '天', 7, ['min' => 1, 'max' => 90], 'normal'],
            ['tasks.frontend_publish_enabled', 'tasks', '用户端任务发布中心', 'boolean', null, true, null, 'high'],
            ['tasks.referral_completion_milestones', 'tasks', '推荐新人达标条件', 'multi_enum', null, ['first_cash_order', 'first_task'], null, 'high', ['first_cash_order', 'first_task']],
            ['tasks.wallet_reward_second_review_cents', 'tasks', '单用户余额奖励二次审核门槛', 'money', '分', 20000, ['min' => 0, 'max' => 100000000], 'high'],
            ['tasks.wallet_budget_second_review_cents', 'tasks', '任务余额总预算二次审核门槛', 'money', '分', 500000, ['min' => 0, 'max' => 1000000000], 'high'],
            ['tasks.referral_monthly_cap', 'tasks', '推荐新人每月奖励人数上限', 'integer', '人', 5, ['min' => 0, 'max' => 10000], 'normal'],
            ['orders.unpaid_close_minutes', 'orders', '待支付订单关闭时间', 'integer', '分钟', 15, ['min' => 5, 'max' => 1440], 'normal'],
            ['orders.inventory_hold_minutes', 'orders', '库存预占时间', 'integer', '分钟', 15, ['min' => 5, 'max' => 1440], 'normal'],
            ['orders.low_stock_threshold', 'orders', '默认低库存阈值', 'integer', '件', 10, ['min' => 0, 'max' => 100000], 'normal'],
            ['payment.wallet_enabled', 'payment', '余额支付开关', 'boolean', null, true, null, 'high'],
            ['payment.wallet_cash_combination', 'payment', '余额与现金组合支付', 'boolean', null, false, ['const' => false], 'high'],
            ['payment.shipping_cash_only', 'payment', '运费仅现金支付', 'boolean', null, true, ['const' => true], 'high'],
            ['pricing.shared_across_clients', 'payment', '多端共用价格', 'boolean', null, true, ['const' => true], 'high'],
            ['wallet.withdrawal_enabled', 'wallet', '余额提现开关', 'boolean', null, true, null, 'high'],
            ['wallet.withdraw_fee_rate', 'wallet', '提现手续费率', 'decimal', '比例', 0.006, ['min' => 0, 'max' => 0.2], 'high'],
            ['wallet.min_withdrawal_cents', 'wallet', '单笔最低提现金额', 'money', '分', 1000, ['min' => 100, 'max' => 100000000], 'high'],
            ['wallet.max_withdrawal_cents', 'wallet', '单笔最高提现金额', 'money', '分', 100000, ['min' => 100, 'max' => 100000000], 'high'],
            ['wallet.daily_withdrawal_count', 'wallet', '每日提现次数', 'integer', '次', 3, ['min' => 1, 'max' => 100], 'high'],
            ['wallet.daily_withdrawal_cents', 'wallet', '每日提现累计上限', 'money', '分', 200000, ['min' => 100, 'max' => 100000000], 'high'],
            ['after_sales.user_action_days', 'after_sales', '售后用户操作期限', 'integer', '天', 7, ['min' => 1, 'max' => 30], 'normal'],
            ['after_sales.return_shipping_compensation_cents', 'after_sales', '退货运费补偿默认上限', 'money', '分', 3000, ['min' => 0, 'max' => 100000], 'high'],
            ['after_sales.original_shipping_refundable', 'after_sales', '退货退还原运费', 'boolean', null, false, ['const' => false], 'high'],
            ['after_sales.no_logistics_enabled', 'after_sales', '无需物流交付方式', 'boolean', null, true, ['const' => true], 'high'],
            ['community.post_review_mode', 'community', '帖子审核模式', 'enum', null, 'pre_review', null, 'normal', ['pre_review', 'auto_publish']],
            ['community.comment_review_mode', 'community', '评论审核模式', 'enum', null, 'sensitive_filter', null, 'normal', ['sensitive_filter', 'pre_review']],
            ['community.post_title_min_length', 'community', '帖子标题最短长度', 'integer', '字', 5, ['min' => 1, 'max' => 60], 'normal'],
            ['community.post_title_max_length', 'community', '帖子标题最长长度', 'integer', '字', 60, ['min' => 5, 'max' => 120], 'normal'],
            ['community.post_content_min_length', 'community', '帖子正文最短长度', 'integer', '字', 20, ['min' => 1, 'max' => 500], 'normal'],
            ['community.post_content_max_length', 'community', '帖子正文最长长度', 'integer', '字', 5000, ['min' => 100, 'max' => 20000], 'normal'],
            ['community.comment_min_length', 'community', '评论最短长度', 'integer', '字', 2, ['min' => 1, 'max' => 100], 'normal'],
            ['community.comment_max_length', 'community', '评论最长长度', 'integer', '字', 500, ['min' => 20, 'max' => 2000], 'normal'],
            ['community.daily_post_limit', 'community', '每日发帖上限', 'integer', '篇', 10, ['min' => 1, 'max' => 100], 'normal'],
            ['community.daily_comment_limit', 'community', '每日评论上限', 'integer', '条', 100, ['min' => 1, 'max' => 1000], 'normal'],
            ['community.daily_rewarded_comment_limit', 'community', '每日评论奖励上限', 'integer', '条', 10, ['min' => 0, 'max' => 100], 'normal'],
            ['community.comment_reward_min_length', 'community', '评论奖励最短长度', 'integer', '字', 10, ['min' => 1, 'max' => 500], 'normal'],
            ['community.sensitive_words', 'community', '社区敏感词', 'multi_enum', null, ['加微信', '代刷', '返现'], null, 'high', ['加微信', '代刷', '返现']],
            ['community.review_hours', 'community', '社区内容审核期限', 'integer', '小时', 24, ['min' => 1, 'max' => 168], 'normal'],
            ['community.appeal_days', 'community', '社区处置申诉期限', 'integer', '天', 7, ['min' => 1, 'max' => 90], 'normal'],
            ['community.revoke_reward_on_violation', 'community', '内容违规时撤销积分', 'boolean', null, true, null, 'high'],
            ['coupons.stacking_allowed', 'coupons', '优惠券叠加使用', 'boolean', null, false, ['const' => false], 'high'],
            ['coupons.full_refund_returns_coupon', 'coupons', '整单全额退款返还优惠券', 'boolean', null, true, null, 'high'],
            ['coupons.partial_refund_returns_coupon', 'coupons', '部分退款返还优惠券', 'boolean', null, false, null, 'high'],
            ['risk.cash_refund_second_review_cents', 'risk', '现金退款二次审核门槛', 'money', '分', 20000, ['min' => 0, 'max' => 100000000], 'high'],
            ['risk.points_adjustment_second_review', 'risk', '积分调整二次审核门槛', 'integer', '积分', 1000, ['min' => 0, 'max' => 100000000], 'high'],
            ['risk.wallet_adjustment_second_review_cents', 'risk', '余额调整二次审核门槛', 'money', '分', 20000, ['min' => 0, 'max' => 100000000], 'high'],
            ['risk.asset_clear_requires_review', 'risk', '资产清零始终二次审核', 'boolean', null, true, ['const' => true], 'high'],
            ['risk.task_high_response_hours', 'risk', '高危任务响应期限', 'integer', '小时', 2, ['min' => 1, 'max' => 72], 'normal'],
            ['risk.task_normal_response_hours', 'risk', '普通任务投诉响应期限', 'integer', '小时', 24, ['min' => 1, 'max' => 720], 'normal'],
            ['notifications.quiet_hours_start', 'notifications', '免打扰开始时间', 'time', null, '22:00', null, 'normal'],
            ['notifications.quiet_hours_end', 'notifications', '免打扰结束时间', 'time', null, '08:00', null, 'normal'],
            ['notifications.external_retry_count', 'notifications', '外部通知失败重试次数', 'integer', '次', 3, ['min' => 0, 'max' => 20], 'normal'],
            ['homepage.brand', 'homepage', '首页品牌与 Logo', 'object', null, ['logo_url' => '', 'logo_text' => 'TM', 'name' => '任务商城', 'caption' => 'TASK · REWARD · LIFE'], ['required_keys' => ['logo_url', 'logo_text', 'name', 'caption']], 'normal'],
            ['homepage.carousel', 'homepage', '首页轮播图', 'object', null, ['autoplay' => true, 'interval_ms' => 4500, 'items' => [
                ['id' => 'task-welcome', 'kicker' => '今天也有新收获', 'title' => '完成好任务，兑换好生活', 'description' => '做任务赚积分，现金、积分或组合方式都能兑换心仪商品。', 'button_text' => '去任务大厅', 'link_type' => 'tasks', 'image_url' => ''],
                ['id' => 'mall-choice', 'kicker' => '积分与现金灵活选', 'title' => '三种购买方式，价格各端一致', 'description' => '积分兑换、现金购买或现金加积分，按商品配置自由选择。', 'button_text' => '去积分商城', 'link_type' => 'products', 'image_url' => ''],
            ]], ['required_keys' => ['autoplay', 'interval_ms', 'items'], 'properties' => ['interval_ms' => ['type' => 'integer', 'min' => 1500, 'max' => 15000]]], 'normal'],
            ['homepage.announcement', 'homepage', '首页公告栏', 'object', null, ['enabled' => true, 'icon_text' => '公', 'title' => '平台公告', 'content' => '欢迎来到任务商城，完成任务即可领取奖励。', 'link_type' => 'notifications'], ['required_keys' => ['enabled', 'icon_text', 'title', 'content', 'link_type']], 'normal'],
            ['homepage.popup', 'homepage', '首页公告或海报弹窗', 'object', null, ['enabled' => true, 'display_once' => true, 'content_id' => 'welcome-v1', 'mode' => 'announcement', 'title' => '欢迎来到任务商城', 'content' => '新用户可以先完成新手任务，熟悉流程并领取第一份积分奖励。', 'image_url' => '', 'button_text' => '我知道了', 'link_type' => 'none'], ['required_keys' => ['enabled', 'display_once', 'content_id', 'mode', 'title', 'content', 'image_url', 'button_text', 'link_type']], 'normal'],
            ['homepage.modules', 'homepage', '首页图标与区块样式', 'object', null, [
                'page_background_url' => '', 'notification_icon_url' => '', 'quick_grid_background_url' => '',
                'quick_entries' => [
                    ['id' => 'tasks', 'label' => '任务大厅', 'note' => '领取奖励', 'mark' => '任', 'tone' => 'blue', 'icon_url' => '', 'link_type' => 'tasks'],
                    ['id' => 'products', 'label' => '积分商城', 'note' => '兑换好物', 'mark' => '兑', 'tone' => 'orange', 'icon_url' => '', 'link_type' => 'products'],
                    ['id' => 'community', 'label' => '社区成长', 'note' => '分享交流', 'mark' => '聊', 'tone' => 'green', 'icon_url' => '', 'link_type' => 'community'],
                    ['id' => 'orders', 'label' => '我的订单', 'note' => '查看进度', 'mark' => '单', 'tone' => 'violet', 'icon_url' => '', 'link_type' => 'orders'],
                ],
                'recommendation' => ['section_title' => '今日推荐', 'section_subtitle' => '从一件简单的小事开始', 'title' => '完成新手任务', 'description' => '完善资料、熟悉流程，开启你的第一份奖励', 'tag' => '简单', 'reward_text' => '最高 +300 积分', 'time_text' => '约 3 分钟', 'icon_text' => '新', 'icon_url' => '', 'background_url' => '', 'link_type' => 'tasks'],
                'product_section' => ['section_title' => '精选兑换', 'section_subtitle' => '三种购买方式，价格各端一致', 'cards' => [
                    ['id' => 'points-choice', 'eyebrow' => '积分好物', 'title' => '日常精选', 'price_text' => '积分兑换', 'tone' => 'blue', 'image_url' => '', 'background_url' => '', 'link_type' => 'products'],
                    ['id' => 'life-choice', 'eyebrow' => '限时推荐', 'title' => '生活补给', 'price_text' => '现金 / 积分', 'tone' => 'warm', 'image_url' => '', 'background_url' => '', 'link_type' => 'products'],
                ]],
                'trust' => ['title' => '每一笔资产都有记录', 'description' => '积分来源、有效期、退款和余额变动清晰可查', 'icon_url' => '', 'background_url' => '', 'link_type' => 'assets'],
            ], ['required_keys' => ['page_background_url', 'notification_icon_url', 'quick_grid_background_url', 'quick_entries', 'recommendation', 'product_section', 'trust']], 'normal'],
            ['navigation.bottom_tabs', 'navigation', '用户端底部导航栏', 'object', null, [
                'items' => [
                    ['id' => 'home', 'label' => '首页', 'target' => 'home', 'enabled' => true, 'icon_url' => '', 'active_icon_url' => ''],
                    ['id' => 'tasks', 'label' => '任务', 'target' => 'tasks', 'enabled' => true, 'icon_url' => '', 'active_icon_url' => ''],
                    ['id' => 'products', 'label' => '商城', 'target' => 'products', 'enabled' => true, 'icon_url' => '', 'active_icon_url' => ''],
                    ['id' => 'community', 'label' => '社区', 'target' => 'community', 'enabled' => true, 'icon_url' => '', 'active_icon_url' => ''],
                    ['id' => 'account', 'label' => '我的', 'target' => 'account', 'enabled' => true, 'icon_url' => '', 'active_icon_url' => ''],
                ],
                'style' => ['background_color' => '#FFFFFF', 'text_color' => '#7A859B', 'active_color' => '#2F67DF', 'border_color' => '#E7EBF2'],
            ], ['required_keys' => ['items', 'style'], 'properties' => ['items' => ['type' => 'array'], 'style' => ['type' => 'object']]], 'normal'],
            ['accounts.self_registration_enabled', 'accounts', '用户自主注册开关', 'boolean', null, true, null, 'high'],
            ['accounts.growth_levels_enabled', 'accounts', '用户成长等级开关', 'boolean', null, true, null, 'normal'],
            ['accounts.growth_active_task_count', 'accounts', '活跃用户任务达标数', 'integer', '个', 1, ['min' => 0, 'max' => 10000], 'normal'],
            ['accounts.growth_active_cash_order_count', 'accounts', '活跃用户现金订单达标数', 'integer', '单', 1, ['min' => 0, 'max' => 10000], 'normal'],
            ['accounts.growth_core_task_count', 'accounts', '核心用户任务达标数', 'integer', '个', 5, ['min' => 0, 'max' => 100000], 'normal'],
            ['accounts.growth_core_cash_order_count', 'accounts', '核心用户现金订单达标数', 'integer', '单', 3, ['min' => 0, 'max' => 100000], 'normal'],
            ['accounts.cancellation_cooling_days', 'accounts', '账号注销冷静期', 'integer', '天', 7, ['min' => 0, 'max' => 90], 'high'],
        ];

        foreach ($definitions as $definition) {
            [$key, $group, $name, $type, $unit, $default, $rules, $risk, $options] = array_pad($definition, 9, null);
            SystemSettingDefinition::query()->updateOrCreate(
                ['setting_key' => $key],
                [
                    'group_code' => $group,
                    'name' => $name,
                    'description' => str_starts_with($key, 'homepage.')
                        ? '由配置中心的“首页装修”分类维护，发布后立即更新用户端首页。图片字段支持本地上传或 HTTPS 图片网址导入。'
                        : (str_starts_with($key, 'navigation.')
                            ? '维护 H5 与小程序共用的底部导航文字、图标、位置、显示状态与跳转目标；发布后用户端自动刷新。'
                            : '由配置中心统一维护；发布后仅影响新产生的业务事实。'),
                    'value_type' => $type,
                    'unit' => $unit,
                    'options' => $options,
                    'validation_rules' => $rules,
                    'default_value' => $default,
                    'risk_level' => $risk,
                    'enabled' => true,
                ],
            );
        }
    }
}
