<?php

namespace App\Modules\Notification\Services;

use InvalidArgumentException;

class NotificationSceneRegistry
{
    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return [
            'task_claimed' => $this->scene('task', '领取成功', '已领取任务', '你已领取“{{task_title}}”，请在 {{deadline}} 前完成。', ['in_app'], 10),
            'task_progress_started' => $this->scene('task', '任务进行中', '任务已开始', '“{{task_title}}”已开始，当前进度：{{progress_summary}}。', ['in_app'], 15),
            'task_ready_to_submit' => $this->scene('task', '等待提交', '任务可以提交', '“{{task_title}}”已达到提交条件，请及时提交完成材料。', ['in_app'], 18),
            'task_submission_received' => $this->scene('task', '材料已提交', '任务材料提交成功', '“{{task_title}}”的材料已提交，平台将尽快审核。', ['in_app'], 20),
            'task_review_started' => $this->scene('task', '开始审核', '任务进入审核', '“{{task_title}}”已进入审核，请留意后续结果。', ['in_app'], 30),
            'task_submission_withdrawn' => $this->scene('task', '撤回材料', '任务材料已撤回', '你已撤回“{{task_title}}”的本次提交，可修改后重新提交。', ['in_app'], 40),
            'task_revision_required' => $this->scene('task', '需要修改', '任务材料需要修改', '“{{task_title}}”暂未通过：{{review_note}}', ['in_app', 'wechat_mini'], 50),
            'task_completed' => $this->scene('task', '审核通过', '任务已完成', '“{{task_title}}”已审核通过，奖励将按规则发放。', ['in_app', 'wechat_mini'], 60),
            'task_claim_cancelled' => $this->scene('task', '取消领取', '任务领取已取消', '你已取消“{{task_title}}”，本次名额已释放。', ['in_app'], 70),
            'task_claim_expired' => $this->scene('task', '任务到期', '任务已到期', '“{{task_title}}”未在 {{deadline}} 前完成，本次领取已结束。', ['in_app', 'wechat_mini'], 80),
            'task_appeal_submitted' => $this->scene('task', '申诉已提交', '任务申诉已提交', '“{{task_title}}”的申诉已提交，请等待复核。', ['in_app'], 90),
            'task_appeal_approved' => $this->scene('task', '申诉通过', '任务申诉通过', '“{{task_title}}”的申诉已通过，任务按完成处理。', ['in_app', 'wechat_mini'], 100),
            'task_appeal_rejected' => $this->scene('task', '申诉未通过', '任务申诉未通过', '“{{task_title}}”的申诉未通过：{{review_note}}', ['in_app', 'wechat_mini'], 110),
            'task_reward_delivered' => $this->scene('task', '奖励到账', '任务奖励已到账', '“{{task_title}}”的奖励已发放：{{reward_summary}}。', ['in_app', 'wechat_mini'], 120, true),
            'task_reward_failed' => $this->scene('task', '奖励处理中', '任务奖励发放异常', '“{{task_title}}”的奖励暂未全部到账，平台正在处理。', ['in_app', 'wechat_mini'], 130, true),

            'order_pending_payment' => $this->scene('order', '等待支付', '订单待支付', '订单 {{order_no}} 已创建，请在有效期内完成支付。', ['in_app', 'wechat_mini'], 10),
            'order_paid' => $this->scene('order', '支付成功', '订单支付成功', '订单 {{order_no}} 已支付，正在准备履约。', ['in_app', 'wechat_mini'], 20, true),
            'order_fulfillment_updated' => $this->scene('order', '履约更新', '订单履约已更新', '订单 {{order_no}} 已更新履约信息：{{tracking_text}}', ['in_app'], 30),
            'order_fulfilled' => $this->scene('order', '已发货或交付', '订单已发货', '订单 {{order_no}} 已发货或交付：{{tracking_text}}', ['in_app', 'wechat_mini'], 40),
            'order_completed' => $this->scene('order', '订单完成', '订单已完成', '订单 {{order_no}} 已完成，感谢你的使用。', ['in_app'], 50),
            'order_cancelled' => $this->scene('order', '订单取消', '订单已取消', '订单 {{order_no}} 已取消，已锁定的库存、积分或优惠将按规则释放。', ['in_app', 'wechat_mini'], 60),
            'order_partially_refunded' => $this->scene('order', '部分退款', '订单部分退款完成', '订单 {{order_no}} 本次已退回 {{refund_summary}}，剩余部分仍可在订单中查看。', ['in_app', 'wechat_mini'], 70, true),
            'order_refunded' => $this->scene('order', '全部退款', '订单退款完成', '订单 {{order_no}} 本次已退回 {{refund_summary}}，订单已全部退款。', ['in_app', 'wechat_mini'], 80, true),

            'post_review_pending' => $this->scene('community', '等待审核', '帖子已提交审核', '“{{post_title}}”已提交，审核完成后会通知你。', ['in_app'], 10, false, 'pages/community/index?view=mine'),
            'post_published' => $this->scene('community', '发布成功', '帖子发布成功', '“{{post_title}}”已公开发布。', ['in_app'], 15),
            'post_review_approved' => $this->scene('community', '审核通过', '帖子审核通过', '“{{post_title}}”已通过审核并公开发布。', ['in_app', 'wechat_mini'], 20),
            'post_review_rejected' => $this->scene('community', '审核未通过', '帖子审核未通过', '“{{post_title}}”暂未通过：{{review_note}}', ['in_app', 'wechat_mini'], 30, false, 'pages/community/index?view=mine'),
            'post_warning' => $this->scene('community', '内容警告', '帖子收到平台警告', '“{{post_title}}”收到平台警告：{{review_note}}', ['in_app', 'wechat_mini'], 35, true),
            'post_hidden' => $this->scene('community', '内容已隐藏', '帖子已被隐藏', '“{{post_title}}”已被隐藏：{{review_note}}', ['in_app', 'wechat_mini'], 40, true, 'pages/community/index?view=mine'),
            'post_deleted' => $this->scene('community', '内容已删除', '帖子已删除', '“{{post_title}}”已删除：{{review_note}}', ['in_app'], 50, true, 'pages/community/index?view=mine'),
            'post_appeal_submitted' => $this->scene('community', '申诉已提交', '帖子申诉已提交', '“{{post_title}}”的申诉已提交，请等待复核。', ['in_app'], 60, false, 'pages/community/index?view=mine'),
            'post_restored' => $this->scene('community', '申诉通过', '帖子已恢复', '“{{post_title}}”的申诉已通过，内容已经恢复。', ['in_app', 'wechat_mini'], 70, true),
            'post_appeal_rejected' => $this->scene('community', '申诉未通过', '帖子申诉未通过', '“{{post_title}}”的申诉未通过：{{review_note}}', ['in_app', 'wechat_mini'], 80, true, 'pages/community/index?view=mine'),
        ];
    }

    /** @return array<string,mixed> */
    public function get(string $sceneCode): array
    {
        $definition = $this->all()[$sceneCode] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException("Unknown notification scene [{$sceneCode}].");
        }

        return ['scene_code' => $sceneCode, ...$definition];
    }

    public function has(string $sceneCode): bool
    {
        return array_key_exists($sceneCode, $this->all());
    }

    /** @return array<string,mixed> */
    private function scene(string $domain, string $name, string $subject, string $body, array $channels, int $sort, bool $essential = false, ?string $deepLink = null): array
    {
        $variables = match ($domain) {
            'task' => ['task_title', 'status_text', 'task_claim_id', 'task_id', 'deadline', 'review_note', 'reward_summary', 'progress_summary', 'occurred_at'],
            'order' => ['order_no', 'status_text', 'order_id', 'amount', 'refund_amount', 'refunded_points', 'refund_summary', 'tracking_text', 'occurred_at'],
            default => ['post_title', 'status_text', 'post_id', 'review_note', 'occurred_at'],
        };

        return [
            'domain' => $domain,
            'domain_name' => ['task' => '任务进度', 'order' => '订单状态', 'community' => '帖子发布'][$domain],
            'name' => $name,
            'category' => $domain === 'community' ? 'community' : $domain,
            'default_subject' => $subject,
            'default_body' => $body,
            'default_channels' => $channels,
            'allowed_channels' => ['in_app', 'wechat_mini', 'sms'],
            'allowed_variables' => $variables,
            'deep_link' => $deepLink ?? match ($domain) {
                'task' => 'pages/task-claim/index?id={{task_claim_id}}',
                'order' => 'pages/order-detail/index?id={{order_id}}',
                default => 'pages/community-detail/index?id={{post_id}}',
            },
            'subscription_context' => $domain,
            'essential' => $essential,
            'sort' => $sort,
        ];
    }
}
