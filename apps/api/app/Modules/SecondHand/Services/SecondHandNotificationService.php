<?php

namespace App\Modules\SecondHand\Services;

use App\Modules\Notification\Services\NotificationService;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandOrder;
use App\Modules\SecondHand\Models\SecondHandRating;
use App\Modules\SecondHand\Models\SecondHandRefund;

class SecondHandNotificationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function itemReviewed(SecondHandItem $item, bool $approved, ?string $reason): void
    {
        $seller = $item->seller()->firstOrFail();
        if ($approved) {
            $this->notifications->create(
                $seller, 'second_hand', 'second_hand_item_approved',
                '闲置商品审核通过',
                '你发布的「'.$item->title.'」已通过审核，公开展示中。',
                'sh-item-review:'.$item->public_id.':approved',
                false, ['in_app'],
                ['item_id' => $item->public_id],
            );
        } else {
            $this->notifications->create(
                $seller, 'second_hand', 'second_hand_item_rejected',
                '闲置商品审核未通过',
                '你发布的「'.$item->title.'」未通过审核：'.($reason ?: '请修改后重新发布'),
                'sh-item-review:'.$item->public_id.':rejected',
                false, ['in_app'],
                ['item_id' => $item->public_id],
            );
        }
    }

    public function orderCreated(SecondHandOrder $order): void
    {
        $item = $order->item()->firstOrFail();
        $this->notifications->create(
            $order->seller()->firstOrFail(), 'second_hand', 'second_hand_order_created',
            '闲置订单已创建',
            '你的「'.$item->title.'」被下单了，等待买家支付。',
            'sh-order-created:'.$order->public_id,
            false, ['in_app'],
            ['order_id' => $order->public_id, 'item_id' => $item->public_id],
        );
    }

    public function orderPaid(SecondHandOrder $order): void
    {
        $item = $order->item()->firstOrFail();
        $this->notifications->create(
            $order->seller()->firstOrFail(), 'second_hand', 'second_hand_order_paid',
            '买家已付款',
            '你的「'.$item->title.'」订单已支付 ¥'.number_format($order->paid_amount / 100, 2).'，请尽快发货。',
            'sh-order-paid:'.$order->public_id,
            false, ['in_app'],
            ['order_id' => $order->public_id, 'item_id' => $item->public_id],
        );
    }

    public function orderShipped(SecondHandOrder $order): void
    {
        $item = $order->item()->firstOrFail();
        $this->notifications->create(
            $order->buyer()->firstOrFail(), 'second_hand', 'second_hand_order_shipped',
            '卖家已发货',
            '你购买的「'.$item->title.'」已发货，收货后请及时确认。',
            'sh-order-shipped:'.$order->public_id,
            false, ['in_app'],
            ['order_id' => $order->public_id, 'item_id' => $item->public_id],
        );
    }

    public function orderCompleted(SecondHandOrder $order): void
    {
        $item = $order->item()->firstOrFail();
        $this->notifications->create(
            $order->seller()->firstOrFail(), 'second_hand', 'second_hand_order_completed',
            '货款已到账',
            '你的「'.$item->title.'」订单已完成，¥'.number_format($order->paid_amount / 100, 2).' 已结算到你的钱包。',
            'sh-order-completed:'.$order->public_id,
            false, ['in_app'],
            ['order_id' => $order->public_id, 'item_id' => $item->public_id],
        );
    }

    public function orderCancelled(SecondHandOrder $order): void
    {
        $item = $order->item()->firstOrFail();
        $this->notifications->create(
            $order->buyer()->firstOrFail(), 'second_hand', 'second_hand_order_cancelled',
            '闲置订单已取消',
            '你购买的「'.$item->title.'」订单已取消，款项已退回钱包。',
            'sh-order-cancelled:'.$order->public_id,
            false, ['in_app'],
            ['order_id' => $order->public_id, 'item_id' => $item->public_id],
        );
    }

    public function ratingReceived(SecondHandRating $rating): void
    {
        $ratee = $rating->ratee()->firstOrFail();
        $rater = $rating->rater()->firstOrFail();
        $this->notifications->create(
            $ratee, 'second_hand', 'second_hand_rating_received',
            '收到闲置订单评价',
            ($rater->nickname ?: '对方').'给了你一个 '.$rating->score.' 星评价。',
            'sh-rating:'.$rating->public_id,
            false, ['in_app'],
            ['order_id' => $rating->order?->public_id, 'rating_id' => $rating->public_id],
        );
    }

    public function refundRequested(SecondHandRefund $refund): void
    {
        $order = $refund->order()->firstOrFail();
        $item = $order->item()->firstOrFail();
        $this->notifications->create(
            $refund->seller()->firstOrFail(), 'second_hand', 'second_hand_refund_requested',
            '买家申请退款',
            '「'.$item->title.'」订单买家申请退款 ¥'.number_format($refund->amount / 100, 2).'，24 小时内未处理将自动同意。',
            'sh-refund-requested:'.$refund->public_id,
            false, ['in_app'],
            ['order_id' => $order->public_id, 'refund_id' => $refund->public_id],
        );
    }

    public function refundCancelled(SecondHandRefund $refund): void
    {
        $order = $refund->order()->firstOrFail();
        $item = $order->item()->firstOrFail();
        $this->notifications->create(
            $refund->seller()->firstOrFail(), 'second_hand', 'second_hand_refund_cancelled',
            '买家已撤销退款申请',
            '「'.$item->title.'」订单买家已撤销退款申请，订单继续履约。',
            'sh-refund-cancelled:'.$refund->public_id,
            false, ['in_app'],
            ['order_id' => $order->public_id, 'refund_id' => $refund->public_id],
        );
    }

    public function refundResponded(SecondHandRefund $refund, bool $approved): void
    {
        $order = $refund->order()->firstOrFail();
        $item = $order->item()->firstOrFail();
        $this->notifications->create(
            $refund->buyer()->firstOrFail(), 'second_hand',
            $approved ? 'second_hand_refund_approved' : 'second_hand_refund_rejected',
            $approved ? '退款已到账' : '退款申请被拒绝',
            $approved
                ? '「'.$item->title.'」退款 ¥'.number_format($refund->amount / 100, 2).' 已退回你的钱包。'
                : '「'.$item->title.'」退款申请被卖家拒绝'.($refund->seller_note ? '：'.$refund->seller_note : '').'，可申请平台介入。',
            'sh-refund-responded:'.$refund->public_id.':'.$refund->status,
            $approved, ['in_app'],
            ['order_id' => $order->public_id, 'refund_id' => $refund->public_id],
        );
    }

    public function refundArbitrated(SecondHandRefund $refund, bool $approved): void
    {
        $order = $refund->order()->firstOrFail();
        $item = $order->item()->firstOrFail();
        $buyerBody = $approved
            ? '「'.$item->title.'」平台仲裁支持退款，¥'.number_format($refund->amount / 100, 2).' 已退回你的钱包。'
            : '「'.$item->title.'」平台仲裁结果：不支持退款。'.($refund->admin_note ? '说明：'.$refund->admin_note : '');
        $this->notifications->create(
            $refund->buyer()->firstOrFail(), 'second_hand', 'second_hand_refund_arbitrated',
            '平台仲裁结果', $buyerBody,
            'sh-refund-arbitrated:'.$refund->public_id.':buyer',
            true, ['in_app'],
            ['order_id' => $order->public_id, 'refund_id' => $refund->public_id],
        );

        $this->notifications->create(
            $refund->seller()->firstOrFail(), 'second_hand', 'second_hand_refund_arbitrated',
            '平台仲裁结果',
            $approved
                ? '「'.$item->title.'」平台仲裁支持买家退款 ¥'.number_format($refund->amount / 100, 2).'，款项已从托管退回买家。'
                : '「'.$item->title.'」平台仲裁结果：不支持买家退款。',
            'sh-refund-arbitrated:'.$refund->public_id.':seller',
            true, ['in_app'],
            ['order_id' => $order->public_id, 'refund_id' => $refund->public_id],
        );
    }
}
