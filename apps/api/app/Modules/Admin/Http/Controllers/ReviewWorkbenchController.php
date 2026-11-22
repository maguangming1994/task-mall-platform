<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Community\Models\CommunityComment;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\ContentReport;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\Material\Models\Material;
use App\Modules\Material\Models\MaterialReport;
use App\Modules\Refund\Models\Refund;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandRefund;
use App\Modules\Task\Models\TaskSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 移动审核工作台汇总。
 *
 * 按当前管理员拥有的审核权限返回待办数量，没有对应权限的键不下发，
 * 供小程序审核工作台首页三类卡片直接渲染。
 */
class ReviewWorkbenchController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $canCommunity = $admin->hasPermission('community.review');
        $canTask = $admin->hasPermission('task.review');
        $canIdentity = $admin->hasPermission('identity.review');
        $canMaterial = $admin->hasPermission('material.manage');
        $canAfterSale = $admin->hasPermission('after_sale.review');

        $summary = [];

        if ($canCommunity) {
            // 闲置商品待审核（与 SecondHandItemController::adminIndex 的 pending 口径一致）
            $summary['second_hand_pending'] = SecondHandItem::query()
                ->where('audit_status', 'pending')
                ->count();

            // 社区帖子待审核：当前版本且版本审核状态为 pending
            $summary['community_posts_pending'] = CommunityPost::query()
                ->whereHas('versions', function ($query) {
                    $query->where('review_status', 'pending')
                        ->whereColumn('community_post_versions.version_no', 'community_posts.current_version_no');
                })
                ->count();

            // 社区评论待审核（与 CommunityCommentController::adminIndex 口径一致）
            $summary['community_comments_pending'] = CommunityComment::query()
                ->where('status', 'pending_review')
                ->count();

            // 待处理举报（与 CommunityModerationController::reports 口径一致）
            $summary['community_reports_pending'] = ContentReport::query()
                ->where('status', 'submitted')
                ->count();
        }

        if ($canTask) {
            // 任务提交待办：已提交待领取 + 审核中
            $summary['task_submissions_pending'] = TaskSubmission::query()
                ->whereIn('status', ['submitted', 'reviewing'])
                ->count();
        }

        if ($canIdentity) {
            // 与 AdminIdentityVerificationController::index 的 pending 默认口径一致
            $summary['identity_verifications_pending'] = UserIdentityVerification::query()
                ->where('status', 'pending')
                ->count();
        }

        if ($canMaterial) {
            $summary['materials_pending'] = Material::query()->where('status', 'pending')->count();
            $summary['material_reports_pending'] = MaterialReport::query()->where('status', 'pending')->count();
        }

        if ($canAfterSale) {
            // 商城退款待处理；闲置退款需仲裁（卖家拒绝或仲裁中）
            $summary['refunds_pending'] = Refund::query()->where('status', 'submitted')->count();
            $summary['second_hand_refunds_pending'] = SecondHandRefund::query()
                ->whereIn('status', ['seller_rejected', 'arbitrating'])
                ->count();
        }

        $summary['permissions'] = [
            'community' => $canCommunity,
            'task' => $canTask,
            'identity' => $canIdentity,
            'material' => $canMaterial,
            'after_sale' => $canAfterSale,
        ];

        return ApiResponse::success($summary);
    }
}
