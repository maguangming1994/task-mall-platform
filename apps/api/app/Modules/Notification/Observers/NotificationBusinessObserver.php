<?php

namespace App\Modules\Notification\Observers;

use App\Models\User;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\CommunityPostVersion;
use App\Modules\Community\Models\ContentAppeal;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Fulfillment\Models\Fulfillment;
use App\Modules\Notification\Services\NotificationBusinessEventService;
use App\Modules\Order\Models\Order;
use App\Modules\Refund\Models\Refund;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Support\TaskClaimState;

class NotificationBusinessObserver
{
    public function __construct(private readonly NotificationBusinessEventService $events) {}

    public function created(object $model): void
    {
        if ($model instanceof TaskClaim) {
            $this->task($model, 'task_claimed');
        } elseif ($model instanceof Order) {
            if ($model->status === 'pending_payment') {
                $this->order($model, 'order_pending_payment');
            } elseif ($model->status === 'pending_fulfillment') {
                $this->order($model, 'order_paid');
            }
        } elseif ($model instanceof Fulfillment) {
            $this->fulfillment($model);
        } elseif ($model instanceof CommunityPostVersion) {
            $scene = match ($model->review_status) {
                'pending' => 'post_review_pending',
                'approved' => 'post_published',
                default => null,
            };
            if ($scene !== null) {
                $this->postVersion($model, $scene);
            }
        } elseif ($model instanceof ModerationAction && $model->content_type === 'post') {
            $scene = match ($model->action_type) {
                'warning' => 'post_warning',
                'hide' => 'post_hidden',
                'delete' => 'post_deleted',
                default => null,
            };
            if ($scene !== null) {
                $post = CommunityPost::withTrashed()->findOrFail($model->content_id);
                $this->post($post, $scene, $model->note, 'moderation-action:'.$model->public_id.':'.$scene);
            }
        } elseif ($model instanceof ContentAppeal) {
            $action = ModerationAction::query()->findOrFail($model->moderation_action_id);
            if ($action->content_type === 'post') {
                $post = CommunityPost::withTrashed()->findOrFail($action->content_id);
                $this->post($post, 'post_appeal_submitted', $model->reason, 'content-appeal:'.$model->public_id.':submitted');
            }
        }
    }

    public function updated(object $model): void
    {
        if ($model instanceof TaskClaim) {
            if ($model->wasChanged('status')) {
                $scene = match ($model->status) {
                    'submitted' => 'task_submission_received',
                    'reviewing' => 'task_review_started',
                    'withdrawn' => 'task_submission_withdrawn',
                    'rejected' => 'task_revision_required',
                    'approved' => $model->getOriginal('status') === 'appealed' ? 'task_appeal_approved' : 'task_completed',
                    'cancelled' => 'task_claim_cancelled',
                    'expired' => 'task_claim_expired',
                    'appealed' => 'task_appeal_submitted',
                    'appeal_rejected' => 'task_appeal_rejected',
                    default => null,
                };
                if ($scene !== null) {
                    $this->task($model, $scene);
                }
            }
            if ($model->wasChanged('reward_status')) {
                $scene = match ($model->reward_status) {
                    'delivered' => 'task_reward_delivered',
                    'delivery_failed', 'partially_delivered' => 'task_reward_failed',
                    default => null,
                };
                if ($scene !== null) {
                    $this->task($model, $scene);
                }
            }
            if (! $model->wasChanged('status') && $model->wasChanged('execution_status')) {
                $scene = match ($model->execution_status) {
                    TaskClaimState::EXECUTION_IN_PROGRESS => 'task_progress_started',
                    TaskClaimState::EXECUTION_READY_TO_SUBMIT => 'task_ready_to_submit',
                    default => null,
                };
                if ($scene !== null) {
                    $this->task($model, $scene);
                }
            }

            return;
        }

        if ($model instanceof Order) {
            if ($model->wasChanged('status')) {
                $scene = match ($model->status) {
                    'pending_fulfillment' => 'order_paid',
                    'fulfilled' => 'order_fulfilled',
                    'completed' => 'order_completed',
                    'cancelled' => 'order_cancelled',
                    default => null,
                };
                if ($scene !== null) {
                    $this->order($model, $scene);
                }
            } elseif ($model->status === 'pending_fulfillment' && $model->wasChanged('paid_at')) {
                $this->order($model, 'order_paid');
            }

            return;
        }

        if ($model instanceof Refund && $model->wasChanged('status') && $model->status === 'succeeded') {
            $this->refund($model);

            return;
        }

        if ($model instanceof CommunityPostVersion && $model->wasChanged('review_status')) {
            $scene = match ($model->review_status) {
                'approved' => 'post_review_approved',
                'rejected' => 'post_review_rejected',
                default => null,
            };
            if ($scene !== null) {
                $this->postVersion($model, $scene);
            }

            return;
        }

        if ($model instanceof ContentAppeal && $model->wasChanged('status') && in_array($model->status, ['approved', 'rejected'], true)) {
            $action = ModerationAction::query()->findOrFail($model->moderation_action_id);
            if ($action->content_type === 'post') {
                $post = CommunityPost::withTrashed()->findOrFail($action->content_id);
                $scene = $model->status === 'approved' ? 'post_restored' : 'post_appeal_rejected';
                $this->post($post, $scene, $model->review_note, 'content-appeal:'.$model->public_id.':'.$model->status);
            }
        }
    }

    private function task(TaskClaim $claim, string $scene): void
    {
        $user = User::query()->findOrFail($claim->user_id);
        $taskReviewNote = $claim->reviews()->latest('id')->value('note');
        $appealResultReason = $claim->appeals()->latest('id')->value('result_reason');
        $reviewNote = (string) (in_array($scene, ['task_appeal_approved', 'task_appeal_rejected'], true)
            ? ($appealResultReason ?? $taskReviewNote)
            : ($taskReviewNote ?? $appealResultReason)
        ) ?: '请根据页面提示处理';
        $rewardSummary = collect($claim->task_snapshot['rewards'] ?? [])->map(function (array $reward): string {
            return match ($reward['type'] ?? '') {
                'points' => '积分 '.(int) ($reward['points'] ?? $reward['amount'] ?? 0),
                'wallet_balance', 'wallet_cash' => '余额 ¥'.number_format((int) ($reward['balance_amount'] ?? $reward['amount'] ?? 0) / 100, 2, '.', ''),
                'coupon' => '优惠券 × '.(int) ($reward['quantity'] ?? $reward['amount'] ?? 1),
                default => '奖励',
            };
        })->filter()->implode('、');
        $variables = [
            'task_title' => (string) ($claim->task_snapshot['title'] ?? '任务'),
            'status_text' => str_starts_with($scene, 'task_progress_') || $scene === 'task_ready_to_submit'
                ? TaskClaimState::executionLabel((string) $claim->execution_status)
                : $this->taskStatus($claim->status ?? ($scene === 'task_claimed' ? 'claimed' : '')),
            'task_claim_id' => $claim->public_id,
            'task_id' => $claim->task?->public_id ?? '',
            'deadline' => $claim->completion_deadline_at?->format('Y-m-d H:i') ?? '任务规定时间',
            'review_note' => $reviewNote,
            'reward_summary' => $rewardSummary !== '' ? $rewardSummary : '按任务规则发放',
            'progress_summary' => max(0, (int) $claim->progress_current).' / '.max(1, (int) $claim->progress_target),
            'occurred_at' => now()->format('Y-m-d H:i'),
        ];
        $eventKey = 'task:'.$claim->public_id.':'.$scene.':submission-'.$claim->submission_count.':appeal-'.$claim->appeal_count.':reward-'.$claim->reward_status;
        $this->events->record($user, $scene, $variables, $eventKey, ['task_claim_id' => $claim->public_id, 'task_id' => $variables['task_id']]);
    }

    private function order(Order $order, string $scene): void
    {
        $user = User::query()->findOrFail($order->user_id);
        $fulfillment = $order->fulfillments()->latest('id')->first();
        $tracking = $fulfillment?->tracking_no
            ? trim(($fulfillment->carrier_name ? $fulfillment->carrier_name.' ' : '').$fulfillment->tracking_no)
            : ($fulfillment?->method === 'virtual' ? '虚拟内容已交付' : '请在订单详情中查看');
        $variables = [
            'order_no' => $order->order_no,
            'status_text' => $this->orderStatus($order->status),
            'order_id' => $order->public_id,
            'amount' => number_format($order->payable_cash_amount / 100, 2, '.', ''),
            'tracking_text' => $tracking,
            'occurred_at' => now()->format('Y-m-d H:i'),
        ];
        $this->events->record($user, $scene, $variables, 'order:'.$order->public_id.':'.$scene, ['order_id' => $order->public_id]);
    }

    private function refund(Refund $refund): void
    {
        $order = Order::query()->findOrFail($refund->order_id);
        $user = User::query()->findOrFail($refund->user_id);
        $summary = collect([
            $refund->refund_cash_amount > 0 ? '¥'.number_format($refund->refund_cash_amount / 100, 2, '.', '') : null,
            $refund->refund_points > 0 ? '积分 '.$refund->refund_points : null,
            $refund->coupon_returned ? '优惠券已退回' : null,
        ])->filter()->implode('、');
        $scene = $order->status === 'refunded' ? 'order_refunded' : 'order_partially_refunded';
        $this->events->record($user, $scene, [
            'order_no' => $order->order_no,
            'status_text' => $this->orderStatus($order->status),
            'order_id' => $order->public_id,
            'amount' => number_format($refund->refund_cash_amount / 100, 2, '.', ''),
            'refund_amount' => number_format($refund->refund_cash_amount / 100, 2, '.', ''),
            'refunded_points' => (string) $refund->refund_points,
            'refund_summary' => $summary !== '' ? $summary : '相关资产',
            'tracking_text' => '',
            'occurred_at' => now()->format('Y-m-d H:i'),
        ], 'refund:'.$refund->public_id.':succeeded', ['order_id' => $order->public_id, 'refund_id' => $refund->public_id]);
    }

    private function fulfillment(Fulfillment $fulfillment): void
    {
        $order = Order::query()->findOrFail($fulfillment->order_id);
        $user = User::query()->findOrFail($order->user_id);
        $tracking = $fulfillment->tracking_no
            ? trim(($fulfillment->carrier_name ? $fulfillment->carrier_name.' ' : '').$fulfillment->tracking_no)
            : ($fulfillment->method === 'virtual' ? '虚拟内容已交付' : '无需物流');
        $this->events->record($user, 'order_fulfillment_updated', [
            'order_no' => $order->order_no,
            'status_text' => '履约已更新',
            'order_id' => $order->public_id,
            'amount' => number_format($order->payable_cash_amount / 100, 2, '.', ''),
            'tracking_text' => $tracking,
            'occurred_at' => now()->format('Y-m-d H:i'),
        ], 'fulfillment:'.$fulfillment->public_id.':created', ['order_id' => $order->public_id, 'fulfillment_id' => $fulfillment->public_id]);
    }

    private function postVersion(CommunityPostVersion $version, string $scene): void
    {
        $post = CommunityPost::withTrashed()->findOrFail($version->community_post_id);
        $user = User::query()->findOrFail($post->user_id);
        $this->events->record($user, $scene, [
            'post_title' => $version->title,
            'status_text' => $version->review_status === 'approved' ? '审核通过' : ($version->review_status === 'rejected' ? '审核未通过' : '等待审核'),
            'post_id' => $post->public_id,
            'review_note' => (string) ($version->review_note ?: '请根据页面提示处理'),
            'occurred_at' => now()->format('Y-m-d H:i'),
        ], 'post-version:'.$version->public_id.':'.$scene, ['post_id' => $post->public_id, 'version_no' => $version->version_no]);
    }

    private function post(CommunityPost $post, string $scene, ?string $reviewNote = null, ?string $eventKey = null): void
    {
        $user = User::query()->findOrFail($post->user_id);
        $this->events->record($user, $scene, [
            'post_title' => $post->title,
            'status_text' => match ($scene) {
                'post_warning' => '已警告',
                'post_hidden' => '已隐藏',
                'post_deleted' => '已删除',
                'post_appeal_submitted' => '申诉中',
                'post_restored' => '已恢复',
                'post_appeal_rejected' => '申诉未通过',
                default => $post->status,
            },
            'post_id' => $post->public_id,
            'review_note' => (string) ($reviewNote ?: $post->review_note ?: '请根据页面提示处理'),
            'occurred_at' => now()->format('Y-m-d H:i'),
        ], $eventKey ?? 'post:'.$post->public_id.':'.$scene, ['post_id' => $post->public_id]);
    }

    private function taskStatus(string $status): string
    {
        return ['claimed' => '已领取', 'submitted' => '已提交', 'reviewing' => '审核中', 'withdrawn' => '已撤回', 'rejected' => '需要修改', 'approved' => '已完成', 'cancelled' => '已取消', 'expired' => '已到期', 'appealed' => '申诉中', 'appeal_rejected' => '申诉未通过'][$status] ?? $status;
    }

    private function orderStatus(string $status): string
    {
        return ['pending_payment' => '待支付', 'pending_fulfillment' => '待履约', 'fulfilled' => '已发货或交付', 'completed' => '已完成', 'cancelled' => '已取消', 'partially_refunded' => '部分退款', 'refunded' => '已退款'][$status] ?? $status;
    }
}
