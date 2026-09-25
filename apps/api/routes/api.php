<?php

use App\Http\Controllers\ClientCapabilitiesController;
use App\Modules\Account\Http\Controllers\AdminUserCenterController;
use App\Modules\Account\Http\Controllers\UserAddressController;
use App\Modules\Account\Http\Controllers\UserAssetOverviewController;
use App\Modules\Account\Http\Controllers\UserAvatarController;
use App\Modules\Account\Http\Controllers\UserProfileController;
use App\Modules\Admin\Http\Controllers\AdminAuthController;
use App\Modules\Admin\Http\Controllers\AdminCurrentUserController;
use App\Modules\Admin\Http\Controllers\AdminMfaController;
use App\Modules\Admin\Http\Controllers\AdminRoleController;
use App\Modules\Admin\Http\Controllers\AdminUserManagementController;
use App\Modules\Admin\Http\Controllers\IdentityConflictController;
use App\Modules\Assets\Http\Controllers\AdminAssetAdjustmentController;
use App\Modules\Assets\Http\Controllers\AdminCouponTemplateController;
use App\Modules\Assets\Http\Controllers\AdminWalletRechargeController;
use App\Modules\Assets\Http\Controllers\PayoutNotificationController;
use App\Modules\Assets\Http\Controllers\UserAssetLedgerController;
use App\Modules\Assets\Http\Controllers\WalletRechargeController;
use App\Modules\Audit\Http\Controllers\AdminAuditLogController;
use App\Modules\Auth\Http\Controllers\AdminSmsProviderController;
use App\Modules\Auth\Http\Controllers\AdminWechatProviderController;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\CurrentUserController;
use App\Modules\Auth\Http\Controllers\PhoneBindingController;
use App\Modules\Auth\Http\Controllers\PhoneVerificationController;
use App\Modules\Auth\Http\Controllers\WechatAuthController;
use App\Modules\Campus\Http\Controllers\AdminCampusController;
use App\Modules\Campus\Http\Controllers\CampusController;
use App\Modules\Cart\Http\Controllers\CartController;
use App\Modules\Community\Http\Controllers\CommunityActivityController;
use App\Modules\Community\Http\Controllers\CommunityCommentController;
use App\Modules\Community\Http\Controllers\CommunityEngagementController;
use App\Modules\Community\Http\Controllers\CommunityHomepageController;
use App\Modules\Community\Http\Controllers\CommunityModerationController;
use App\Modules\Community\Http\Controllers\CommunityPostController;
use App\Modules\Fulfillment\Http\Controllers\FulfillmentController;
use App\Modules\Growth\Http\Controllers\DailyCheckInController;
use App\Modules\Growth\Http\Controllers\ReferralController;
use App\Modules\Identity\Http\Controllers\AdminIdentityVerificationSettingsController;
use App\Modules\Identity\Http\Controllers\UserIdentityVerificationController;
use App\Modules\Inventory\Http\Controllers\AdminInventoryController;
use App\Modules\Inventory\Http\Controllers\AdminShippingTemplateController;
use App\Modules\Inventory\Http\Controllers\ShippingQuoteController;
use App\Modules\Legal\Http\Controllers\AdminLegalDocumentController;
use App\Modules\Legal\Http\Controllers\LegalDocumentController;
use App\Modules\Legal\Http\Controllers\UserLegalConsentController;
use App\Modules\Media\Http\Controllers\AdminMediaController;
use App\Modules\Media\Http\Controllers\MediaAssetController;
use App\Modules\Notification\Http\Controllers\NotificationController;
use App\Modules\Operations\Http\Controllers\AdminOperationsController;
use App\Modules\Operations\Http\Controllers\AdminProductionReadinessController;
use App\Modules\Operations\Http\Controllers\HealthController;
use App\Modules\Operations\Http\Controllers\MonitoringController;
use App\Modules\Order\Http\Controllers\AdminOrderController;
use App\Modules\Order\Http\Controllers\CheckoutController;
use App\Modules\Order\Http\Controllers\OrderController;
use App\Modules\Payment\Http\Controllers\AdminReconciliationController;
use App\Modules\Payment\Http\Controllers\AdminWechatPaymentSettingsController;
use App\Modules\Payment\Http\Controllers\PaymentController;
use App\Modules\Payment\Http\Controllers\WechatPaymentNotificationController;
use App\Modules\Privacy\Http\Controllers\AccountCancellationController;
use App\Modules\Privacy\Http\Controllers\AdminPrivacyRequestController;
use App\Modules\Privacy\Http\Controllers\PrivacyRequestController;
use App\Modules\Product\Http\Controllers\AdminProductController;
use App\Modules\Product\Http\Controllers\ProductController;
use App\Modules\Refund\Http\Controllers\AfterSaleReasonController;
use App\Modules\Refund\Http\Controllers\ExchangeController;
use App\Modules\Refund\Http\Controllers\RefundController;
use App\Modules\Refund\Http\Controllers\ReturnShippingCompensationController;
use App\Modules\Refund\Http\Controllers\WechatRefundNotificationController;
use App\Modules\Settings\Http\Controllers\AdminSystemSettingController;
use App\Modules\Settings\Http\Controllers\AppearanceContentController;
use App\Modules\Settings\Http\Controllers\AppNavigationController;
use App\Modules\Settings\Http\Controllers\HomeContentController;
use App\Modules\Support\Http\Controllers\AdminSupportTicketController;
use App\Modules\Support\Http\Controllers\SupportTicketController;
use App\Modules\Task\Http\Controllers\AdminRewardBudgetController;
use App\Modules\Task\Http\Controllers\AdminTaskAppealController;
use App\Modules\Task\Http\Controllers\AdminTaskController;
use App\Modules\Task\Http\Controllers\AdminTaskDataBatchController;
use App\Modules\Task\Http\Controllers\AdminTaskRiskController;
use App\Modules\Task\Http\Controllers\AdminTaskSubmissionController;
use App\Modules\Task\Http\Controllers\AdminTaskTemplateController;
use App\Modules\Task\Http\Controllers\TaskAppealController;
use App\Modules\Task\Http\Controllers\TaskHallController;
use App\Modules\Task\Http\Controllers\TaskPublisherAuthController;
use App\Modules\Task\Http\Controllers\TaskPublisherMediaController;
use App\Modules\Task\Http\Controllers\TaskPublisherTaskController;
use App\Modules\Task\Http\Controllers\TaskRiskController;
use App\Modules\Task\Http\Controllers\UserTaskSubmissionController;
use App\Modules\Upload\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'live'])->name('api.health');
Route::get('/health/live', [HealthController::class, 'live'])->name('api.health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('api.health.ready');
Route::get('/internal/metrics', [MonitoringController::class, 'metrics'])->middleware('throttle:60,1');

Route::get('/client-capabilities', [ClientCapabilitiesController::class, 'show']);
Route::get('/campuses', [CampusController::class, 'index'])->middleware('throttle:campus-list');
Route::post('/campuses/nearby', [CampusController::class, 'nearby'])->middleware('throttle:campus-nearby');
Route::get('/home-content', [HomeContentController::class, 'show']);
Route::get('/appearance-content', [AppearanceContentController::class, 'show']);
Route::get('/app-navigation', [AppNavigationController::class, 'show']);
Route::get('/legal-documents', [LegalDocumentController::class, 'index']);
Route::get('/tasks', [TaskHallController::class, 'index']);
Route::get('/tasks/{task}', [TaskHallController::class, 'show']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/product-categories', [ProductController::class, 'categories']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/media/assets/{mediaAsset}', [MediaAssetController::class, 'show']);
Route::get('/avatars/{file}', [UserAvatarController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('user-avatars.show');
Route::get('/community/home', [CommunityHomepageController::class, 'show']);
Route::get('/community/categories', [CommunityPostController::class, 'categories']);
Route::get('/community/posts', [CommunityPostController::class, 'index'])->middleware('auth.optional');
Route::get('/community/posts/{post}/comments', [CommunityCommentController::class, 'index']);
Route::get('/community/posts/{post}', [CommunityPostController::class, 'show'])->middleware('auth.optional');
Route::get('/community/media/{file}', [CommunityPostController::class, 'media']);
Route::get('/community/activity-categories', [CommunityActivityController::class, 'categories']);
Route::get('/community/activities', [CommunityActivityController::class, 'index'])->middleware('auth.optional');
Route::get('/community/activities/media/{file}', [CommunityActivityController::class, 'media']);
Route::get('/community/activities/{activity}', [CommunityActivityController::class, 'show'])->middleware('auth.optional');
Route::get('/after-sale-reasons', [AfterSaleReasonController::class, 'index']);
Route::put('/files/{file}/content', [UploadController::class, 'proxyUpload'])->middleware('throttle:30,1');
Route::post('/payment-notifications/wechat', [WechatPaymentNotificationController::class, 'store'])->middleware('throttle:120,1');
Route::post('/refund-notifications/wechat', [WechatRefundNotificationController::class, 'store'])->middleware('throttle:120,1');
Route::post('/payout-notifications', [PayoutNotificationController::class, 'store'])->middleware('throttle:120,1');

Route::prefix('auth')->group(function () {
    Route::post('/wechat-mini/login', [WechatAuthController::class, 'mini'])->middleware('throttle:10,1');
    Route::post('/wechat-h5/login', [WechatAuthController::class, 'officialAccount'])->middleware('throttle:10,1');
    Route::post('/phone/verification-codes', [PhoneVerificationController::class, 'store'])
        ->middleware('throttle:5,1');
    Route::post('/phone/login', [AuthController::class, 'loginWithPhone'])
        ->middleware('throttle:10,1');
    Route::post('/refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:20,1');
});

Route::post('/task-publisher/session', [TaskPublisherAuthController::class, 'store'])->middleware('throttle:10,1');
Route::middleware('auth.publisher')->prefix('task-publisher')->group(function () {
    Route::delete('/session', [TaskPublisherAuthController::class, 'destroy']);
    Route::get('/profile', [TaskPublisherAuthController::class, 'profile']);
    Route::get('/budget-pools', [TaskPublisherTaskController::class, 'budgets']);
    Route::post('/media/assets/upload', [TaskPublisherMediaController::class, 'upload'])->middleware('throttle:30,1');
    Route::post('/media/assets/import-url', [TaskPublisherMediaController::class, 'importUrl'])->middleware('throttle:20,1');
    Route::get('/tasks', [TaskPublisherTaskController::class, 'index']);
    Route::post('/tasks', [TaskPublisherTaskController::class, 'store']);
    Route::put('/tasks/{task}', [TaskPublisherTaskController::class, 'update']);
    Route::get('/tasks/{task}', [TaskPublisherTaskController::class, 'show']);
    Route::get('/tasks/{task}/versions', [TaskPublisherTaskController::class, 'versions']);
    Route::get('/tasks/{task}/audit', [TaskPublisherTaskController::class, 'audit']);
    Route::post('/tasks/{task}/status', [TaskPublisherTaskController::class, 'status']);
});

Route::middleware('auth.access')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [CurrentUserController::class, 'show']);
    Route::put('/me', [UserProfileController::class, 'update'])->middleware('legal.consent:account');
    Route::put('/me/campus', [CampusController::class, 'select']);
    Route::get('/me/identity-verification', [UserIdentityVerificationController::class, 'show'])
        ->middleware('legal.consent:account');
    Route::post('/me/identity-verification', [UserIdentityVerificationController::class, 'store'])
        ->middleware(['legal.consent:account', 'throttle:5,60']);
    Route::get('/me/asset-overview', [UserAssetOverviewController::class, 'show'])->middleware('legal.consent:account');
    Route::get('/points/account', [UserAssetLedgerController::class, 'pointAccount'])->middleware('legal.consent:account');
    Route::get('/points/batches', [UserAssetLedgerController::class, 'pointBatches'])->middleware('legal.consent:account');
    Route::get('/points/transactions', [UserAssetLedgerController::class, 'pointTransactions'])->middleware('legal.consent:account');
    Route::get('/points/debts', [UserAssetLedgerController::class, 'pointDebts'])->middleware('legal.consent:account');
    Route::get('/wallet/account', [UserAssetLedgerController::class, 'walletAccount'])->middleware('legal.consent:wallet');
    Route::get('/wallet/transactions', [UserAssetLedgerController::class, 'walletTransactions'])->middleware('legal.consent:wallet');
    Route::get('/wallet/withdrawals', [UserAssetLedgerController::class, 'withdrawals'])->middleware('legal.consent:wallet');
    Route::get('/wallet/withdrawal-quote', [UserAssetLedgerController::class, 'withdrawalQuote'])->middleware('legal.consent:wallet');
    Route::post('/wallet/withdrawals', [UserAssetLedgerController::class, 'createWithdrawal'])->middleware(['legal.consent:wallet', 'throttle:10,1']);
    Route::get('/wallet/recharges', [WalletRechargeController::class, 'index'])->middleware('legal.consent:wallet');
    Route::post('/wallet/recharges', [WalletRechargeController::class, 'store'])->middleware(['legal.consent:wallet', 'throttle:10,1']);
    Route::get('/wallet/recharges/{walletRecharge}', [WalletRechargeController::class, 'show'])->middleware('legal.consent:wallet');
    Route::post('/wallet/recharges/{walletRecharge}/payments', [WalletRechargeController::class, 'pay'])->middleware(['legal.consent:wallet', 'throttle:20,1']);
    Route::get('/coupons', [UserAssetLedgerController::class, 'coupons'])->middleware('legal.consent:account');
    Route::get('/growth/referrals', [ReferralController::class, 'index'])->middleware('legal.consent:account');
    Route::get('/growth/check-in', [DailyCheckInController::class, 'show'])->middleware('legal.consent:account');
    Route::post('/growth/check-in', [DailyCheckInController::class, 'store'])->middleware(['legal.consent:account', 'throttle:10,1']);
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('legal.consent:account');
    Route::get('/support-tickets', [SupportTicketController::class, 'index'])->middleware('legal.consent:account');
    Route::post('/support-tickets', [SupportTicketController::class, 'store'])->middleware(['legal.consent:account', 'throttle:10,1']);
    Route::get('/support-tickets/{supportTicket}', [SupportTicketController::class, 'show'])->middleware('legal.consent:account');
    Route::post('/support-tickets/{supportTicket}/messages', [SupportTicketController::class, 'reply'])->middleware(['legal.consent:account', 'throttle:30,1']);
    Route::post('/support-tickets/{supportTicket}/close', [SupportTicketController::class, 'close'])->middleware('legal.consent:account');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('legal.consent:account');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->middleware('legal.consent:account');
    Route::get('/notification-preferences', [NotificationController::class, 'preference'])->middleware('legal.consent:account');
    Route::put('/notification-preferences', [NotificationController::class, 'updatePreference'])->middleware('legal.consent:account');
    Route::get('/notification-subscriptions/wechat', [NotificationController::class, 'wechatSubscriptionOptions'])->middleware('legal.consent:account');
    Route::post('/notification-subscriptions/wechat', [NotificationController::class, 'recordWechatSubscriptions'])->middleware(['legal.consent:account', 'throttle:20,1']);
    Route::post('/growth/referrals', [ReferralController::class, 'bind'])->middleware(['legal.consent:account', 'throttle:5,1']);
    Route::get('/community/my-posts', [CommunityPostController::class, 'mine'])->middleware('legal.consent:community');
    Route::post('/community/posts', [CommunityPostController::class, 'store'])->middleware(['legal.consent:community', 'throttle:20,1']);
    Route::put('/community/posts/{post}', [CommunityPostController::class, 'update'])->middleware('legal.consent:community');
    Route::delete('/community/posts/{post}', [CommunityPostController::class, 'destroy'])->middleware('legal.consent:community');
    Route::post('/community/posts/{post}/comments', [CommunityCommentController::class, 'store'])->middleware(['legal.consent:community', 'throttle:60,1']);
    Route::post('/community/posts/{post}/like', [CommunityEngagementController::class, 'like'])->middleware(['legal.consent:community', 'throttle:120,1']);
    Route::delete('/community/posts/{post}/like', [CommunityEngagementController::class, 'unlike'])->middleware(['legal.consent:community', 'throttle:120,1']);
    Route::post('/community/activities', [CommunityActivityController::class, 'store'])->middleware(['legal.consent:community', 'throttle:10,1']);
    Route::post('/community/activities/{activity}/join', [CommunityActivityController::class, 'join'])->middleware(['legal.consent:community', 'throttle:30,1']);
    Route::delete('/community/activities/{activity}/join', [CommunityActivityController::class, 'leave'])->middleware(['legal.consent:community', 'throttle:30,1']);
    Route::get('/community/my-activities', [CommunityActivityController::class, 'mine'])->middleware('legal.consent:community');
    Route::post('/community/content/{contentType}/{contentId}/reports', [CommunityModerationController::class, 'report'])->middleware(['legal.consent:community', 'throttle:20,1']);
    Route::post('/community/moderation-actions/{action}/appeals', [CommunityModerationController::class, 'appeal'])->middleware(['legal.consent:community', 'throttle:10,1']);
    Route::get('/community/my-moderation-actions', [CommunityModerationController::class, 'mine'])->middleware('legal.consent:community');
    Route::post('/tasks/{task}/claims', [TaskHallController::class, 'claim'])->middleware(['legal.consent:task', 'throttle:20,1']);
    Route::post('/tasks/{task}/reports', [TaskRiskController::class, 'report'])->middleware(['legal.consent:task', 'throttle:10,1']);
    Route::get('/task-claims', [TaskHallController::class, 'myClaims'])->middleware('legal.consent:task');
    Route::get('/task-claims/{taskClaim}', [TaskHallController::class, 'showClaim'])->middleware('legal.consent:task');
    Route::post('/task-claims/{taskClaim}/cancel', [TaskHallController::class, 'cancel'])->middleware('legal.consent:task');
    Route::get('/task-claims/{taskClaim}/submissions', [UserTaskSubmissionController::class, 'index'])->middleware('legal.consent:task');
    Route::post('/task-claims/{taskClaim}/submissions', [UserTaskSubmissionController::class, 'store'])->middleware(['legal.consent:task', 'throttle:20,1']);
    Route::post('/task-submissions/{taskSubmission}/withdraw', [UserTaskSubmissionController::class, 'withdraw'])->middleware('legal.consent:task');
    Route::get('/task-claims/{taskClaim}/rewards', [UserTaskSubmissionController::class, 'rewards'])->middleware('legal.consent:task');
    Route::post('/task-claims/{taskClaim}/appeals', [TaskAppealController::class, 'store'])->middleware(['legal.consent:task', 'throttle:10,1']);
    Route::get('/addresses', [UserAddressController::class, 'index'])->middleware('legal.consent:account');
    Route::post('/addresses', [UserAddressController::class, 'store'])->middleware('legal.consent:account');
    Route::put('/addresses/{address}', [UserAddressController::class, 'update'])->middleware('legal.consent:account');
    Route::delete('/addresses/{address}', [UserAddressController::class, 'destroy'])->middleware('legal.consent:account');
    Route::get('/cart', [CartController::class, 'show'])->middleware('legal.consent:account');
    Route::post('/cart/items', [CartController::class, 'store'])->middleware('legal.consent:account');
    Route::put('/cart/items/{cartItem}', [CartController::class, 'update'])->middleware('legal.consent:account');
    Route::delete('/cart/items/{cartItem}', [CartController::class, 'destroy'])->middleware('legal.consent:account');
    Route::post('/shipping/quote', [ShippingQuoteController::class, 'show'])->middleware('legal.consent:account');
    Route::post('/checkout/preview', [CheckoutController::class, 'preview'])->middleware('legal.consent:account');
    Route::get('/orders', [OrderController::class, 'index'])->middleware('legal.consent:account');
    Route::post('/orders', [CheckoutController::class, 'store'])->middleware('legal.consent:account');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('legal.consent:account');
    Route::post('/orders/{order}/complete', [FulfillmentController::class, 'complete'])->middleware('legal.consent:account');
    Route::get('/orders/{order}/payments', [PaymentController::class, 'index'])->middleware('legal.consent:account');
    Route::post('/orders/{order}/payments', [PaymentController::class, 'store'])->middleware(['legal.consent:account', 'throttle:20,1']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->middleware('legal.consent:account');
    Route::get('/refunds', [RefundController::class, 'index'])->middleware('legal.consent:account');
    Route::post('/refunds', [RefundController::class, 'store'])->middleware('legal.consent:account');
    Route::post('/refunds/{refund}/return-shipment', [RefundController::class, 'submitReturn'])->middleware('legal.consent:account');
    Route::post('/exchanges', [ExchangeController::class, 'store'])->middleware('legal.consent:account');
    Route::get('/exchanges', [ExchangeController::class, 'index'])->middleware('legal.consent:account');
    Route::get('/exchanges/{exchange}', [ExchangeController::class, 'show'])->middleware('legal.consent:account');
    Route::post('/exchanges/{exchange}/return-shipment', [ExchangeController::class, 'submitReturn'])->middleware('legal.consent:account');
    Route::post('/exchanges/{exchange}/complete', [ExchangeController::class, 'complete'])->middleware('legal.consent:account');
    Route::get('/order-batches/{orderBatch}', [OrderController::class, 'batch'])->middleware('legal.consent:account');
    Route::get('/me/legal-consents', [UserLegalConsentController::class, 'index']);
    Route::get('/me/legal-consents/pending', [UserLegalConsentController::class, 'pending']);
    Route::post('/me/legal-consents', [UserLegalConsentController::class, 'store']);
    Route::put('/me/phone', [PhoneBindingController::class, 'store'])
        ->middleware('legal.consent:account');
    Route::put('/me/phone/wechat', [PhoneBindingController::class, 'storeFromWechat'])
        ->middleware(['legal.consent:account', 'throttle:10,1']);
    Route::get('/me/privacy-requests', [PrivacyRequestController::class, 'index'])->middleware('legal.consent:account');
    Route::post('/me/privacy-requests', [PrivacyRequestController::class, 'store'])->middleware('legal.consent:account');
    Route::get('/me/account-cancellation/eligibility', [AccountCancellationController::class, 'eligibility'])->middleware('legal.consent:account_cancellation');
    Route::get('/me/account-cancellation', [AccountCancellationController::class, 'show'])->middleware('legal.consent:account_cancellation');
    Route::post('/me/account-cancellation', [AccountCancellationController::class, 'store'])->middleware('legal.consent:account_cancellation');
    Route::delete('/me/account-cancellation', [AccountCancellationController::class, 'destroy'])->middleware('legal.consent:account_cancellation');
    Route::post('/files/upload-tickets', [UploadController::class, 'ticket'])->middleware('throttle:20,1');
    Route::post('/files/{file}/complete', [UploadController::class, 'complete'])->middleware('throttle:30,1');
    Route::get('/files/{file}', [UploadController::class, 'show']);
    Route::get('/files/{file}/content', [UploadController::class, 'download'])->middleware('throttle:60,1');
});

Route::prefix('admin')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:20,1');
        Route::post('/refresh', [AdminAuthController::class, 'refresh'])->middleware('throttle:30,1');
        Route::post('/mfa/verify', [AdminAuthController::class, 'verifyMfa'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth.admin', 'admin.campus'])->group(function () {
        Route::post('/auth/logout', [AdminAuthController::class, 'logout']);
        Route::post('/auth/verify', [AdminAuthController::class, 'verifyRecent'])->middleware('throttle:10,1');
        Route::put('/auth/password', [AdminAuthController::class, 'changePassword'])->middleware('throttle:5,1');
        Route::get('/me', [AdminCurrentUserController::class, 'show']);
        Route::get('/navigation', [AdminCurrentUserController::class, 'navigation']);
        Route::get('/campuses', [AdminCampusController::class, 'index'])->middleware('admin.permission:campus.manage');
        Route::post('/campuses', [AdminCampusController::class, 'store'])->middleware('admin.permission:rbac.manage');
        Route::put('/campuses/{campus}', [AdminCampusController::class, 'update'])->middleware('admin.permission:campus.manage');
        Route::put('/campuses/{campus}/agents', [AdminCampusController::class, 'agents'])->middleware('admin.permission:rbac.manage');
        Route::get('/campus-agent-options', [AdminCampusController::class, 'agentOptions'])->middleware('admin.permission:rbac.manage');
        Route::get('/user-center/overview', [AdminUserCenterController::class, 'overview'])->middleware('admin.permission:user.view_masked|user.view_sensitive');
        Route::get('/user-center/users', [AdminUserCenterController::class, 'users'])->middleware('admin.permission:user.view_masked|user.view_sensitive');
        Route::get('/user-center/referrals', [AdminUserCenterController::class, 'referrals'])->middleware('admin.permission:user.view_masked|user.view_sensitive');
        Route::get('/user-center/verifications', [AdminUserCenterController::class, 'verifications'])->middleware('admin.permission:user.view_masked|user.view_sensitive');
        Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])->middleware('admin.permission:audit.view|audit.view_own');
        Route::post('/mfa/totp/enroll', [AdminMfaController::class, 'enroll'])->middleware('admin.recent_verification');
        Route::post('/mfa/totp/confirm', [AdminMfaController::class, 'confirm'])->middleware('admin.recent_verification');
        Route::get('/admin-users', [AdminUserManagementController::class, 'index'])->middleware('admin.permission:rbac.manage');
        Route::post('/admin-users', [AdminUserManagementController::class, 'store'])->middleware(['admin.permission:rbac.manage', 'admin.recent_verification']);
        Route::put('/admin-users/{adminUser}/roles', [AdminUserManagementController::class, 'roles'])->middleware(['admin.permission:rbac.manage', 'admin.recent_verification']);
        Route::put('/admin-users/{adminUser}/status', [AdminUserManagementController::class, 'status'])->middleware(['admin.permission:rbac.manage', 'admin.recent_verification']);
        Route::post('/admin-users/{adminUser}/revoke-sessions', [AdminUserManagementController::class, 'revokeSessions'])->middleware(['admin.permission:rbac.manage', 'admin.recent_verification']);
        Route::post('/admin-users/{adminUser}/reset-mfa', [AdminUserManagementController::class, 'resetMfa'])->middleware(['admin.permission:rbac.manage', 'admin.recent_verification']);
        Route::get('/roles', [AdminRoleController::class, 'index'])->middleware('admin.permission:rbac.manage');
        Route::get('/permissions', [AdminRoleController::class, 'permissions'])->middleware('admin.permission:rbac.manage');
        Route::post('/roles', [AdminRoleController::class, 'store'])->middleware(['admin.permission:rbac.manage', 'admin.recent_verification']);
        Route::put('/roles/{role:code}', [AdminRoleController::class, 'update'])->middleware(['admin.permission:rbac.manage', 'admin.recent_verification']);
        Route::get('/identity-conflicts', [IdentityConflictController::class, 'index'])
            ->middleware('admin.permission:identity.resolve');
        Route::post('/identity-conflicts/{identityConflict}/resolve', [IdentityConflictController::class, 'resolve'])
            ->middleware('admin.permission:identity.resolve');
        Route::get('/legal-documents', [AdminLegalDocumentController::class, 'index'])
            ->middleware('admin.permission:legal.manage');
        Route::post('/legal-documents', [AdminLegalDocumentController::class, 'store'])
            ->middleware('admin.permission:legal.manage');
        Route::put('/legal-documents/{legalDocument}', [AdminLegalDocumentController::class, 'update'])
            ->middleware('admin.permission:legal.manage');
        Route::post('/legal-documents/{legalDocument}/publish', [AdminLegalDocumentController::class, 'publish'])
            ->middleware(['admin.permission:legal.manage', 'admin.recent_verification']);
        Route::get('/privacy-requests', [AdminPrivacyRequestController::class, 'index'])
            ->middleware('admin.permission:privacy.manage');
        Route::post('/privacy-requests/{privacyRequest}/resolve', [AdminPrivacyRequestController::class, 'resolve'])
            ->middleware(['admin.permission:privacy.manage', 'admin.recent_verification']);
        Route::get('/settings', [AdminSystemSettingController::class, 'index'])
            ->middleware('admin.permission:settings.manage');
        Route::get('/sms/settings', [AdminSmsProviderController::class, 'show'])
            ->middleware('admin.permission:settings.manage');
        Route::put('/sms/settings', [AdminSmsProviderController::class, 'update'])
            ->middleware(['admin.permission:settings.manage', 'admin.recent_verification']);
        Route::post('/sms/settings/test', [AdminSmsProviderController::class, 'test'])
            ->middleware(['admin.permission:settings.manage', 'admin.recent_verification', 'throttle:30,1']);
        Route::get('/wechat/settings', [AdminWechatProviderController::class, 'show'])
            ->middleware('admin.permission:settings.manage');
        Route::put('/wechat/settings/{provider}', [AdminWechatProviderController::class, 'update'])
            ->whereIn('provider', ['mini', 'official_account'])
            ->middleware(['admin.permission:settings.manage', 'admin.recent_verification']);
        Route::post('/wechat/settings/{provider}/test', [AdminWechatProviderController::class, 'test'])
            ->whereIn('provider', ['mini', 'official_account'])
            ->middleware(['admin.permission:settings.manage', 'admin.recent_verification', 'throttle:10,1']);
        Route::post('/wechat/verification-files', [AdminWechatProviderController::class, 'uploadVerificationFile'])
            ->middleware(['admin.permission:settings.manage', 'admin.recent_verification', 'throttle:10,1']);
        Route::delete('/wechat/verification-files/{filename}', [AdminWechatProviderController::class, 'deleteVerificationFile'])
            ->where('filename', 'MP_verify_[A-Za-z0-9_-]{6,64}\.txt')
            ->middleware(['admin.permission:settings.manage', 'admin.recent_verification']);
        Route::get('/wechat-payment/settings', [AdminWechatPaymentSettingsController::class, 'show'])
            ->middleware('admin.permission:settings.manage');
        Route::put('/wechat-payment/settings', [AdminWechatPaymentSettingsController::class, 'update'])
            ->middleware(['admin.permission:settings.manage', 'admin.recent_verification']);
        Route::post('/wechat-payment/credentials', [AdminWechatPaymentSettingsController::class, 'credentials'])
            ->middleware(['admin.permission:settings.manage', 'admin.recent_verification', 'throttle:10,1']);
        Route::post('/wechat-payment/settings/test', [AdminWechatPaymentSettingsController::class, 'test'])
            ->middleware(['admin.permission:settings.manage', 'admin.recent_verification', 'throttle:10,1']);
        Route::get('/identity-verification/settings', [AdminIdentityVerificationSettingsController::class, 'show'])
            ->middleware('admin.permission:settings.manage');
        Route::put('/identity-verification/settings', [AdminIdentityVerificationSettingsController::class, 'update'])
            ->middleware(['admin.permission:settings.manage', 'admin.recent_verification']);
        Route::get('/media/storage/providers', [AdminMediaController::class, 'providers'])->middleware('admin.permission:settings.manage');
        Route::put('/media/storage/providers/{mediaStorageProvider}', [AdminMediaController::class, 'configure'])->middleware(['admin.permission:settings.manage', 'admin.recent_verification']);
        Route::post('/media/storage/providers/{mediaStorageProvider}/test', [AdminMediaController::class, 'test'])->middleware(['admin.permission:settings.manage', 'admin.recent_verification']);
        Route::post('/media/storage/providers/{mediaStorageProvider}/activate', [AdminMediaController::class, 'activate'])->middleware(['admin.permission:settings.manage', 'admin.recent_verification']);
        Route::get('/media/storage/migrations', [AdminMediaController::class, 'migrations'])->middleware('admin.permission:settings.manage');
        Route::post('/media/storage/migrations', [AdminMediaController::class, 'migrate'])->middleware(['admin.permission:settings.manage', 'admin.recent_verification']);
        Route::post('/media/assets/upload', [AdminMediaController::class, 'upload'])->middleware(['admin.permission:product.manage|task.manage|community.review|legal.manage|privacy.manage|settings.manage', 'throttle:60,1']);
        Route::post('/media/assets/import-url', [AdminMediaController::class, 'importUrl'])->middleware(['admin.permission:product.manage|task.manage|community.review|legal.manage|privacy.manage|settings.manage', 'throttle:30,1']);
        Route::get('/production-readiness', [AdminProductionReadinessController::class, 'show'])
            ->middleware('admin.permission:settings.manage');
        Route::get('/operations/dashboard', [AdminOperationsController::class, 'dashboard'])->middleware('admin.permission:operations.manage');
        Route::get('/operations/resilience', [AdminOperationsController::class, 'resilience'])->middleware('admin.permission:operations.manage');
        Route::get('/operations/uat-runs', [AdminOperationsController::class, 'uatRuns'])->middleware('admin.permission:operations.manage');
        Route::get('/operations/release-control', [AdminOperationsController::class, 'releaseControl'])->middleware('admin.permission:deployment.manage');
        Route::put('/operations/feature-flags/{flag:code}', [AdminOperationsController::class, 'updateFeatureFlag'])->middleware(['admin.permission:deployment.manage', 'admin.recent_verification']);
        Route::post('/operations/releases/{release}/transition', [AdminOperationsController::class, 'transitionRelease'])->middleware(['admin.permission:deployment.manage', 'admin.recent_verification']);
        Route::get('/notification-rules', [AdminOperationsController::class, 'notificationRules'])->middleware('admin.permission:operations.manage');
        Route::put('/notification-rules/{sceneCode}', [AdminOperationsController::class, 'updateNotificationRule'])->middleware(['admin.permission:operations.manage', 'admin.recent_verification']);
        Route::get('/notification-templates', [AdminOperationsController::class, 'templates'])->middleware('admin.permission:operations.manage');
        Route::get('/notification-templates/{notificationTemplate}', [AdminOperationsController::class, 'template'])->middleware('admin.permission:operations.manage');
        Route::post('/notification-templates', [AdminOperationsController::class, 'saveTemplate'])->middleware('admin.permission:operations.manage');
        Route::get('/notification-campaigns', [AdminOperationsController::class, 'campaigns'])->middleware('admin.permission:operations.manage');
        Route::post('/notification-campaigns', [AdminOperationsController::class, 'createCampaign'])->middleware('admin.permission:operations.manage');
        Route::post('/notification-campaigns/{notificationCampaign}/cancel', [AdminOperationsController::class, 'cancelCampaign'])->middleware('admin.permission:operations.manage');
        Route::get('/notification-deliveries', [AdminOperationsController::class, 'deliveries'])->middleware('admin.permission:operations.manage');
        Route::post('/notification-deliveries/{delivery}/retry', [AdminOperationsController::class, 'retryDelivery'])->whereNumber('delivery')->middleware('admin.permission:operations.manage');
        Route::get('/reconciliation-runs', [AdminReconciliationController::class, 'index'])->middleware('admin.permission:finance.manage');
        Route::get('/orders', [AdminOrderController::class, 'index'])->middleware('admin.permission:fulfillment.manage|after_sale.review|finance.manage');
        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->middleware('admin.permission:fulfillment.manage|after_sale.review|finance.manage');
        Route::post('/orders/{order}/fulfillments', [FulfillmentController::class, 'store'])->middleware('admin.permission:fulfillment.manage');
        Route::post('/refunds/{refund}/review', [RefundController::class, 'review'])->middleware(['admin.permission:after_sale.review', 'admin.recent_verification']);
        Route::get('/refunds', [RefundController::class, 'adminIndex'])->middleware('admin.permission:after_sale.review');
        Route::post('/refunds/{refund}/receive-return', [RefundController::class, 'receiveReturn'])->middleware(['admin.permission:after_sale.review', 'admin.recent_verification']);
        Route::post('/refunds/{refund}/sync-provider', [RefundController::class, 'syncProvider'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::get('/after-sale-reasons', [AfterSaleReasonController::class, 'adminIndex'])->middleware('admin.permission:after_sale.review');
        Route::put('/after-sale-reasons/{reason}', [AfterSaleReasonController::class, 'update'])->middleware(['admin.permission:after_sale.review', 'admin.recent_verification']);
        Route::post('/exchanges/{exchange}/review', [ExchangeController::class, 'review'])->middleware(['admin.permission:after_sale.review', 'admin.recent_verification']);
        Route::get('/exchanges', [ExchangeController::class, 'adminIndex'])->middleware('admin.permission:after_sale.review|fulfillment.manage');
        Route::post('/exchanges/{exchange}/receive-return', [ExchangeController::class, 'receiveReturn'])->middleware('admin.permission:fulfillment.manage');
        Route::post('/exchanges/{exchange}/replacement-fulfillment', [ExchangeController::class, 'fulfill'])->middleware('admin.permission:fulfillment.manage');
        Route::get('/return-shipping-compensations', [ReturnShippingCompensationController::class, 'index'])->middleware('admin.permission:after_sale.review');
        Route::get('/return-shipping-compensations/{compensation}/proof', [ReturnShippingCompensationController::class, 'proof'])->middleware('admin.permission:after_sale.review');
        Route::post('/return-shipping-compensations/{compensation}/review', [ReturnShippingCompensationController::class, 'review'])->middleware(['admin.permission:after_sale.review', 'admin.recent_verification']);
        Route::post('/reconciliation-runs', [AdminReconciliationController::class, 'store'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::get('/reconciliation-runs/{reconciliationRun}', [AdminReconciliationController::class, 'show'])->middleware('admin.permission:finance.manage');
        Route::post('/reconciliation-entries/{reconciliationEntry}/resolve', [AdminReconciliationController::class, 'resolve'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::get('/reward-budget-pools', [AdminRewardBudgetController::class, 'index'])->middleware('admin.permission:task.view');
        Route::get('/reward-coupon-templates', [AdminRewardBudgetController::class, 'couponTemplates'])->middleware('admin.permission:task.view');
        Route::post('/reward-budget-pools', [AdminRewardBudgetController::class, 'store'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::post('/reward-budget-pools/{budgetPool}/adjustments', [AdminRewardBudgetController::class, 'adjust'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::get('/tasks', [AdminTaskController::class, 'index'])->middleware('admin.permission:task.view');
        Route::post('/tasks', [AdminTaskController::class, 'store'])->middleware('admin.permission:task.manage');
        Route::get('/tasks/{task}', [AdminTaskController::class, 'show'])->middleware('admin.permission:task.view');
        Route::put('/tasks/{task}', [AdminTaskController::class, 'update'])->middleware('admin.permission:task.manage');
        Route::get('/tasks/{task}/versions', [AdminTaskController::class, 'versions'])->middleware('admin.permission:task.view');
        Route::post('/tasks/{task}/versions/{versionNo}/rollback', [AdminTaskController::class, 'rollback'])->middleware('admin.permission:task.manage');
        Route::post('/tasks/{task}/status', [AdminTaskController::class, 'status'])->middleware(['admin.permission:task.manage', 'admin.recent_verification']);
        Route::post('/tasks/{task}/publish-approval', [AdminTaskController::class, 'reviewPublishApproval'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::get('/task-data-batches', [AdminTaskDataBatchController::class, 'index'])->middleware('admin.permission:task.view');
        Route::post('/task-data-batches', [AdminTaskDataBatchController::class, 'store'])->middleware('admin.permission:task.manage');
        Route::get('/task-data-batches/{taskDataBatch}', [AdminTaskDataBatchController::class, 'show'])->middleware('admin.permission:task.view');
        Route::get('/task-data-batches/{taskDataBatch}/export', [AdminTaskDataBatchController::class, 'export'])->middleware('admin.permission:task.view');
        Route::get('/task-templates', [AdminTaskTemplateController::class, 'index'])->middleware('admin.permission:task.view');
        Route::post('/task-templates', [AdminTaskTemplateController::class, 'store'])->middleware('admin.permission:task.manage');
        Route::get('/task-templates/{taskTemplate}', [AdminTaskTemplateController::class, 'show'])->middleware('admin.permission:task.view');
        Route::put('/task-templates/{taskTemplate}', [AdminTaskTemplateController::class, 'update'])->middleware('admin.permission:task.manage');
        Route::post('/task-templates/{taskTemplate}/copy', [AdminTaskTemplateController::class, 'copy'])->middleware('admin.permission:task.manage');
        Route::post('/task-templates/{taskTemplate}/status', [AdminTaskTemplateController::class, 'status'])->middleware('admin.permission:task.manage');
        Route::get('/task-templates/{taskTemplate}/versions', [AdminTaskTemplateController::class, 'versions'])->middleware('admin.permission:task.view');
        Route::post('/task-templates/{taskTemplate}/create-task', [AdminTaskTemplateController::class, 'createTask'])->middleware('admin.permission:task.manage');
        Route::get('/task-submissions', [AdminTaskSubmissionController::class, 'index'])->middleware('admin.permission:task.review');
        Route::get('/task-review-reasons', [AdminTaskSubmissionController::class, 'reviewReasons'])->middleware('admin.permission:task.review');
        Route::get('/task-reward-deliveries', [AdminTaskSubmissionController::class, 'rewardDeliveries'])->middleware('admin.permission:task.review');
        Route::get('/task-submissions/{taskSubmission}/files/{file}', [AdminTaskSubmissionController::class, 'file'])->middleware('admin.permission:task.review');
        Route::post('/task-submissions/{taskSubmission}/start-review', [AdminTaskSubmissionController::class, 'startReview'])->middleware('admin.permission:task.review');
        Route::post('/task-submissions/{taskSubmission}/release-review', [AdminTaskSubmissionController::class, 'releaseReview'])->middleware('admin.permission:task.review');
        Route::post('/task-submissions/{taskSubmission}/transfer-review', [AdminTaskSubmissionController::class, 'transferReview'])->middleware('admin.permission:task.review');
        Route::post('/task-submissions/{taskSubmission}/review', [AdminTaskSubmissionController::class, 'review'])->middleware('admin.permission:task.review');
        Route::post('/task-reward-deliveries/{taskRewardDelivery}/retry', [AdminTaskSubmissionController::class, 'retryReward'])->middleware('admin.permission:task.review');
        Route::get('/task-appeals', [AdminTaskAppealController::class, 'index'])->middleware('admin.permission:task.review');
        Route::post('/task-appeals/{taskAppeal}/review', [AdminTaskAppealController::class, 'review'])->middleware('admin.permission:task.review');
        Route::get('/task-risk-cases', [AdminTaskRiskController::class, 'index'])->middleware('admin.permission:task.review');
        Route::post('/task-risk-cases/{taskRiskCase}/review', [AdminTaskRiskController::class, 'review'])->middleware('admin.permission:task.review');
        Route::get('/coupon-templates', [AdminCouponTemplateController::class, 'index'])->middleware('admin.permission:product.manage');
        Route::get('/coupon-templates/{couponTemplate}', [AdminCouponTemplateController::class, 'show'])->middleware('admin.permission:product.manage');
        Route::get('/community/posts', [CommunityPostController::class, 'adminIndex'])->middleware('admin.permission:community.review');
        Route::post('/community/posts/{post}/review', [CommunityPostController::class, 'review'])->middleware('admin.permission:community.review');
        Route::get('/community/comments', [CommunityCommentController::class, 'adminIndex'])->middleware('admin.permission:community.review');
        Route::post('/community/comments/{comment}/review', [CommunityCommentController::class, 'review'])->middleware('admin.permission:community.review');
        Route::get('/community/reports', [CommunityModerationController::class, 'reports'])->middleware('admin.permission:community.review');
        Route::post('/community/{contentType}/{contentId}/moderate', [CommunityModerationController::class, 'moderate'])->middleware('admin.permission:community.review');
        Route::get('/community/appeals', [CommunityModerationController::class, 'appeals'])->middleware('admin.permission:community.review');
        Route::post('/community/appeals/{appeal}/review', [CommunityModerationController::class, 'reviewAppeal'])->middleware('admin.permission:community.review');
        Route::get('/community/activities', [CommunityActivityController::class, 'adminIndex'])->middleware('admin.permission:community.review');
        Route::get('/community/activities/media/{file}', [CommunityActivityController::class, 'adminMedia'])->middleware('admin.permission:community.review');
        Route::post('/community/activities/{activity}/review', [CommunityActivityController::class, 'review'])->middleware('admin.permission:community.review');
        Route::post('/community/activities/{activity}/close', [CommunityActivityController::class, 'close'])->middleware('admin.permission:community.review');
        Route::post('/community/activities/{activity}/participants/{participant}/remove', [CommunityActivityController::class, 'removeParticipant'])->middleware('admin.permission:community.review');
        Route::get('/community/config', [CommunityHomepageController::class, 'adminShow'])->middleware('admin.permission:community.review');
        Route::get('/support-tickets', [AdminSupportTicketController::class, 'index'])->middleware('admin.permission:after_sale.review|privacy.manage');
        Route::get('/support-tickets/{supportTicket}', [AdminSupportTicketController::class, 'show'])->middleware('admin.permission:after_sale.review|privacy.manage');
        Route::post('/support-tickets/{supportTicket}/messages', [AdminSupportTicketController::class, 'reply'])->middleware('admin.permission:after_sale.review|privacy.manage');
        Route::put('/support-tickets/{supportTicket}', [AdminSupportTicketController::class, 'update'])->middleware('admin.permission:after_sale.review|privacy.manage');
        Route::put('/community/config', [CommunityHomepageController::class, 'update'])->middleware('admin.permission:community.review');
        Route::post('/coupon-templates', [AdminCouponTemplateController::class, 'store'])->middleware(['admin.permission:product.manage', 'admin.recent_verification']);
        Route::post('/coupon-templates/{couponTemplate}/versions', [AdminCouponTemplateController::class, 'createVersion'])->middleware(['admin.permission:product.manage', 'admin.recent_verification']);
        Route::post('/coupon-templates/{couponTemplate}/status', [AdminCouponTemplateController::class, 'status'])->middleware(['admin.permission:product.manage', 'admin.recent_verification']);
        Route::get('/product-categories', [AdminProductController::class, 'categories'])->middleware('admin.permission:product.manage');
        Route::post('/product-categories', [AdminProductController::class, 'storeCategory'])->middleware('admin.permission:product.manage');
        Route::put('/product-categories/{category}', [AdminProductController::class, 'updateCategory'])->middleware('admin.permission:product.manage');
        Route::delete('/product-categories/{category}', [AdminProductController::class, 'destroyCategory'])->middleware('admin.permission:product.manage');
        Route::get('/products', [AdminProductController::class, 'index'])->middleware('admin.permission:product.manage');
        Route::post('/products', [AdminProductController::class, 'store'])->middleware('admin.permission:product.manage');
        Route::get('/products/{product}', [AdminProductController::class, 'show'])->middleware('admin.permission:product.manage');
        Route::put('/products/{product}', [AdminProductController::class, 'update'])->middleware('admin.permission:product.manage');
        Route::post('/products/{product}/status', [AdminProductController::class, 'status'])->middleware('admin.permission:product.manage');
        Route::put('/products/{product}/skus/{sku}/purchase-options/{purchaseMode}', [AdminProductController::class, 'updateOption'])->middleware('admin.permission:product.manage');
        Route::get('/inventories', [AdminInventoryController::class, 'index'])->middleware('admin.permission:product.manage');
        Route::post('/inventories/{sku}/adjustments', [AdminInventoryController::class, 'adjust'])->middleware('admin.permission:product.manage');
        Route::get('/shipping-templates', [AdminShippingTemplateController::class, 'index'])->middleware('admin.permission:product.manage');
        Route::post('/shipping-templates', [AdminShippingTemplateController::class, 'store'])->middleware('admin.permission:product.manage');
        Route::put('/shipping-templates/{template}', [AdminShippingTemplateController::class, 'update'])->middleware('admin.permission:product.manage');
        Route::get('/users/{userId}/assets', [AdminAssetAdjustmentController::class, 'userAssets'])->middleware('admin.permission:user.view_masked');
        Route::get('/asset-adjustments', [AdminAssetAdjustmentController::class, 'index'])->middleware('admin.permission:finance.manage');
        Route::post('/users/{userId}/asset-adjustments', [AdminAssetAdjustmentController::class, 'store'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::post('/asset-adjustments/{assetAdjustment}/review', [AdminAssetAdjustmentController::class, 'review'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::post('/user-coupons/{userCoupon}/revoke', [AdminAssetAdjustmentController::class, 'revokeCoupon'])->middleware(['admin.permission:product.manage', 'admin.recent_verification']);
        Route::get('/wallet-withdrawals', [AdminAssetAdjustmentController::class, 'withdrawals'])->middleware('admin.permission:finance.manage');
        Route::post('/wallet-withdrawals/{walletWithdrawal}/review', [AdminAssetAdjustmentController::class, 'reviewWithdrawal'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::post('/wallet-withdrawals/{walletWithdrawal}/dispatch', [AdminAssetAdjustmentController::class, 'dispatchWithdrawal'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::post('/wallet-withdrawals/{walletWithdrawal}/sync', [AdminAssetAdjustmentController::class, 'syncWithdrawal'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::get('/wallet-recharges', [AdminWalletRechargeController::class, 'index'])->middleware('admin.permission:finance.manage');
        Route::get('/wallet-recharges/{walletRecharge}/proofs/{file}', [AdminWalletRechargeController::class, 'proof'])->middleware('admin.permission:finance.manage');
        Route::post('/wallet-recharges/{walletRecharge}/review', [AdminWalletRechargeController::class, 'review'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::get('/wallet-recharge-cards', [AdminWalletRechargeController::class, 'cards'])->middleware('admin.permission:finance.manage');
        Route::post('/wallet-recharge-cards', [AdminWalletRechargeController::class, 'createCards'])->middleware(['admin.permission:finance.manage', 'admin.recent_verification']);
        Route::get('/settings/{settingKey}', [AdminSystemSettingController::class, 'show'])
            ->middleware('admin.permission:settings.manage');
        Route::put('/settings/{settingKey}/draft', [AdminSystemSettingController::class, 'saveDraft'])
            ->middleware('admin.permission:settings.manage');
        Route::post('/settings/{settingKey}/review', [AdminSystemSettingController::class, 'review'])
            ->middleware('admin.permission:settings.approve');
        Route::post('/settings/{settingKey}/publish', [AdminSystemSettingController::class, 'publish'])
            ->middleware('admin.permission:settings.manage');
        Route::get('/settings/{settingKey}/versions', [AdminSystemSettingController::class, 'versions'])
            ->middleware('admin.permission:settings.manage');
        Route::post('/settings/{settingKey}/rollback', [AdminSystemSettingController::class, 'rollback'])
            ->middleware('admin.permission:settings.manage');
    });
});
