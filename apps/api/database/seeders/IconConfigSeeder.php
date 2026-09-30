<?php

namespace Database\Seeders;

use App\Modules\Operations\Models\IconConfig;
use Illuminate\Database\Seeder;

class IconConfigSeeder extends Seeder
{
    public function run(): void
    {
        $icons = [
            // TabBar
            ['code' => 'tabbar-home', 'name' => '首页', 'category' => 'tabbar', 'svg_path' => '/static/icons/tabbar-home.svg', 'fallback_char' => '⌂', 'label' => '首页', 'tone' => 'default', 'sort_order' => 1],
            ['code' => 'tabbar-home-active', 'name' => '首页-选中', 'category' => 'tabbar', 'svg_path' => '/static/icons/tabbar-home-active.svg', 'fallback_char' => '⌂', 'label' => '首页', 'tone' => 'default', 'sort_order' => 2],
            ['code' => 'tabbar-tasks', 'name' => '任务', 'category' => 'tabbar', 'svg_path' => '/static/icons/tabbar-tasks.svg', 'fallback_char' => '⌘', 'label' => '任务', 'tone' => 'default', 'sort_order' => 3],
            ['code' => 'tabbar-tasks-active', 'name' => '任务-选中', 'category' => 'tabbar', 'svg_path' => '/static/icons/tabbar-tasks-active.svg', 'fallback_char' => '⌘', 'label' => '任务', 'tone' => 'default', 'sort_order' => 4],
            ['code' => 'tabbar-mall', 'name' => '商城', 'category' => 'tabbar', 'svg_path' => '/static/icons/tabbar-mall.svg', 'fallback_char' => '⌑', 'label' => '商城', 'tone' => 'default', 'sort_order' => 5],
            ['code' => 'tabbar-mall-active', 'name' => '商城-选中', 'category' => 'tabbar', 'svg_path' => '/static/icons/tabbar-mall-active.svg', 'fallback_char' => '⌑', 'label' => '商城', 'tone' => 'default', 'sort_order' => 6],
            ['code' => 'tabbar-community', 'name' => '社区', 'category' => 'tabbar', 'svg_path' => '/static/icons/tabbar-community.svg', 'fallback_char' => '◎', 'label' => '社区', 'tone' => 'default', 'sort_order' => 7],
            ['code' => 'tabbar-community-active', 'name' => '社区-选中', 'category' => 'tabbar', 'svg_path' => '/static/icons/tabbar-community-active.svg', 'fallback_char' => '◎', 'label' => '社区', 'tone' => 'default', 'sort_order' => 8],
            ['code' => 'tabbar-account', 'name' => '我的', 'category' => 'tabbar', 'svg_path' => '/static/icons/tabbar-account.svg', 'fallback_char' => '◉', 'label' => '我的', 'tone' => 'default', 'sort_order' => 9],
            ['code' => 'tabbar-account-active', 'name' => '我的-选中', 'category' => 'tabbar', 'svg_path' => '/static/icons/tabbar-account-active.svg', 'fallback_char' => '◉', 'label' => '我的', 'tone' => 'default', 'sort_order' => 10],

            // 功能模块
            ['code' => 'module-tasks', 'name' => '任务大厅', 'category' => 'module', 'svg_path' => '/static/icons/module-tasks.svg', 'fallback_char' => '⌘', 'label' => '任务大厅', 'description' => '浏览并领取校园任务', 'tone' => 'yellow', 'sort_order' => 11],
            ['code' => 'module-task-publish', 'name' => '发布任务', 'category' => 'module', 'svg_path' => '/static/icons/module-task-publish.svg', 'fallback_char' => '✎', 'label' => '发布任务', 'description' => '发布你的专属任务', 'tone' => 'cyan', 'sort_order' => 12],
            ['code' => 'module-mall', 'name' => '积分商城', 'category' => 'module', 'svg_path' => '/static/icons/module-mall.svg', 'fallback_char' => '⌑', 'label' => '积分商城', 'description' => '兑换心仪好物', 'tone' => 'green', 'sort_order' => 13],
            ['code' => 'module-community', 'name' => '校园社区', 'category' => 'module', 'svg_path' => '/static/icons/module-community.svg', 'fallback_char' => '◎', 'label' => '校园社区', 'description' => '分享校园生活', 'tone' => 'blue', 'sort_order' => 14],
            ['code' => 'module-community-post', 'name' => '发帖', 'category' => 'module', 'svg_path' => '/static/icons/module-community-post.svg', 'fallback_char' => '✎', 'label' => '发帖', 'description' => '分享新鲜事', 'tone' => 'purple', 'sort_order' => 15],
            ['code' => 'module-wallet', 'name' => '钱包', 'category' => 'module', 'svg_path' => '/static/icons/module-wallet.svg', 'fallback_char' => '◈', 'label' => '钱包', 'description' => '余额与积分管理', 'tone' => 'yellow', 'sort_order' => 16],
            ['code' => 'module-wallet-recharge', 'name' => '充值', 'category' => 'module', 'svg_path' => '/static/icons/module-wallet-recharge.svg', 'fallback_char' => '↻', 'label' => '充值', 'description' => '余额充值', 'tone' => 'cyan', 'sort_order' => 17],
            ['code' => 'module-withdraw', 'name' => '提现', 'category' => 'module', 'svg_path' => '/static/icons/module-withdraw.svg', 'fallback_char' => '⇓', 'label' => '提现', 'description' => '余额提现', 'tone' => 'green', 'sort_order' => 18],
            ['code' => 'module-mall-trade', 'name' => '商城交易', 'category' => 'module', 'svg_path' => '/static/icons/module-mall-trade.svg', 'fallback_char' => '⌑', 'label' => '商城交易', 'description' => '订单与交易管理', 'tone' => 'blue', 'sort_order' => 19],
            ['code' => 'module-after-sale', 'name' => '售后服务', 'category' => 'module', 'svg_path' => '/static/icons/module-after-sale.svg', 'fallback_char' => '↺', 'label' => '售后服务', 'description' => '退款与售后', 'tone' => 'red', 'sort_order' => 20],
            ['code' => 'module-referrals', 'name' => '邀请推荐', 'category' => 'module', 'svg_path' => '/static/icons/module-referrals.svg', 'fallback_char' => '✧', 'label' => '邀请推荐', 'description' => '邀请好友得奖励', 'tone' => 'purple', 'sort_order' => 21],
            ['code' => 'module-check-in', 'name' => '每日签到', 'category' => 'module', 'svg_path' => '/static/icons/module-check-in.svg', 'fallback_char' => '✓', 'label' => '每日签到', 'description' => '签到领积分', 'tone' => 'yellow', 'sort_order' => 22],

            // 订单状态
            ['code' => 'order-pending', 'name' => '待付款', 'category' => 'order', 'svg_path' => '/static/icons/order-pending.svg', 'fallback_char' => '◔', 'label' => '待付款', 'tone' => 'yellow', 'sort_order' => 23],
            ['code' => 'order-review', 'name' => '待审核', 'category' => 'order', 'svg_path' => '/static/icons/order-review.svg', 'fallback_char' => '◑', 'label' => '待审核', 'tone' => 'cyan', 'sort_order' => 24],
            ['code' => 'order-completed', 'name' => '已完成', 'category' => 'order', 'svg_path' => '/static/icons/order-completed.svg', 'fallback_char' => '●', 'label' => '已完成', 'tone' => 'green', 'sort_order' => 25],
            ['code' => 'order-refund', 'name' => '退款/售后', 'category' => 'order', 'svg_path' => '/static/icons/order-refund.svg', 'fallback_char' => '↺', 'label' => '退款/售后', 'tone' => 'red', 'sort_order' => 26],

            // 校园社区
            ['code' => 'community-post', 'name' => '发帖', 'category' => 'community', 'svg_path' => '/static/icons/community-post.svg', 'fallback_char' => '✎', 'label' => '发帖', 'tone' => 'default', 'sort_order' => 27],
            ['code' => 'community-group', 'name' => '圈子', 'category' => 'community', 'svg_path' => '/static/icons/community-group.svg', 'fallback_char' => '◎', 'label' => '圈子', 'tone' => 'default', 'sort_order' => 28],
            ['code' => 'community-help', 'name' => '求助', 'category' => 'community', 'svg_path' => '/static/icons/community-help.svg', 'fallback_char' => '?', 'label' => '求助', 'tone' => 'default', 'sort_order' => 29],
            ['code' => 'community-study', 'name' => '学习', 'category' => 'community', 'svg_path' => '/static/icons/community-study.svg', 'fallback_char' => '✎', 'label' => '学习', 'tone' => 'default', 'sort_order' => 30],
            ['code' => 'community-showcase', 'name' => '展示', 'category' => 'community', 'svg_path' => '/static/icons/community-showcase.svg', 'fallback_char' => '☆', 'label' => '展示', 'tone' => 'default', 'sort_order' => 31],
            ['code' => 'community-qa', 'name' => '问答', 'category' => 'community', 'svg_path' => '/static/icons/community-qa.svg', 'fallback_char' => '??', 'label' => '问答', 'tone' => 'default', 'sort_order' => 32],

            // 常用服务
            ['code' => 'service-tasks', 'name' => '我的任务', 'category' => 'service', 'svg_path' => '/static/icons/service-tasks.svg', 'fallback_char' => '⌘', 'label' => '我的任务', 'tone' => 'default', 'sort_order' => 33],
            ['code' => 'service-orders', 'name' => '我的订单', 'category' => 'service', 'svg_path' => '/static/icons/service-orders.svg', 'fallback_char' => '⌑', 'label' => '我的订单', 'tone' => 'default', 'sort_order' => 34],
            ['code' => 'service-aftersale', 'name' => '售后/退款', 'category' => 'service', 'svg_path' => '/static/icons/service-aftersale.svg', 'fallback_char' => '↺', 'label' => '售后/退款', 'tone' => 'default', 'sort_order' => 35],
            ['code' => 'service-invite', 'name' => '邀请好友', 'category' => 'service', 'svg_path' => '/static/icons/service-invite.svg', 'fallback_char' => '✧', 'label' => '邀请好友', 'tone' => 'default', 'sort_order' => 36],
            ['code' => 'service-checkin', 'name' => '每日签到', 'category' => 'service', 'svg_path' => '/static/icons/service-checkin.svg', 'fallback_char' => '✓', 'label' => '每日签到', 'tone' => 'default', 'sort_order' => 37],
            ['code' => 'service-messages', 'name' => '消息中心', 'category' => 'service', 'svg_path' => '/static/icons/service-messages.svg', 'fallback_char' => '✉', 'label' => '消息中心', 'tone' => 'default', 'sort_order' => 38],
            ['code' => 'service-address', 'name' => '收货地址', 'category' => 'service', 'svg_path' => '/static/icons/service-address.svg', 'fallback_char' => '◈', 'label' => '收货地址', 'tone' => 'default', 'sort_order' => 39],
            ['code' => 'service-privacy', 'name' => '隐私设置', 'category' => 'service', 'svg_path' => '/static/icons/service-privacy.svg', 'fallback_char' => '◉', 'label' => '隐私设置', 'tone' => 'default', 'sort_order' => 40],
            ['code' => 'service-security', 'name' => '账号安全', 'category' => 'service', 'svg_path' => '/static/icons/service-security.svg', 'fallback_char' => '◊', 'label' => '账号安全', 'tone' => 'default', 'sort_order' => 41],
            ['code' => 'service-verify', 'name' => '实名认证', 'category' => 'service', 'svg_path' => '/static/icons/service-verify.svg', 'fallback_char' => '✓', 'label' => '实名认证', 'tone' => 'default', 'sort_order' => 42],
            ['code' => 'service-support', 'name' => '帮助与客服', 'category' => 'service', 'svg_path' => '/static/icons/service-support.svg', 'fallback_char' => '?', 'label' => '帮助与客服', 'tone' => 'default', 'sort_order' => 43],

            // 通用操作
            ['code' => 'icon-search', 'name' => '搜索', 'category' => 'common', 'svg_path' => '/static/icons/icon-search.svg', 'fallback_char' => '⌕', 'label' => '搜索', 'tone' => 'default', 'sort_order' => 44],
            ['code' => 'icon-location', 'name' => '定位', 'category' => 'common', 'svg_path' => '/static/icons/icon-location.svg', 'fallback_char' => '⌖', 'label' => '定位', 'tone' => 'default', 'sort_order' => 45],
            ['code' => 'icon-close', 'name' => '关闭', 'category' => 'common', 'svg_path' => '/static/icons/icon-close.svg', 'fallback_char' => '×', 'label' => '关闭', 'tone' => 'default', 'sort_order' => 46],
            ['code' => 'icon-chevron-right', 'name' => '右箭头', 'category' => 'common', 'svg_path' => '/static/icons/icon-chevron-right.svg', 'fallback_char' => '›', 'label' => '右箭头', 'tone' => 'default', 'sort_order' => 47],
            ['code' => 'icon-chevron-left', 'name' => '左箭头', 'category' => 'common', 'svg_path' => '/static/icons/icon-chevron-left.svg', 'fallback_char' => '‹', 'label' => '左箭头', 'tone' => 'default', 'sort_order' => 48],
            ['code' => 'icon-chevron-down', 'name' => '下箭头', 'category' => 'common', 'svg_path' => '/static/icons/icon-chevron-down.svg', 'fallback_char' => '⌄', 'label' => '下箭头', 'tone' => 'default', 'sort_order' => 49],
            ['code' => 'icon-info', 'name' => '信息', 'category' => 'common', 'svg_path' => '/static/icons/icon-info.svg', 'fallback_char' => 'i', 'label' => '信息', 'tone' => 'default', 'sort_order' => 50],
            ['code' => 'icon-check-circle', 'name' => '完成', 'category' => 'common', 'svg_path' => '/static/icons/icon-check-circle.svg', 'fallback_char' => '✓', 'label' => '完成', 'tone' => 'default', 'sort_order' => 51],
            ['code' => 'icon-plus', 'name' => '添加', 'category' => 'common', 'svg_path' => '/static/icons/icon-plus.svg', 'fallback_char' => '+', 'label' => '添加', 'tone' => 'default', 'sort_order' => 52],
        ];

        foreach ($icons as $icon) {
            IconConfig::query()->firstOrCreate(
                ['code' => $icon['code']],
                $icon,
            );
        }
    }
}
