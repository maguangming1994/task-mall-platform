<?php

/**
 * 订阅消息投递链路验证脚本
 *
 * 用法（在仓库根目录执行）：
 *   php scripts/verify-notification-delivery.php                 # 只读检查，不修改任何数据
 *   php scripts/verify-notification-delivery.php --user-id=123   # 追加检查指定用户的微信身份与订阅授权
 *   php scripts/verify-notification-delivery.php --approve-post=POST_PUBLIC_ID   # 触发一次「审核通过」并检查投递记录
 *   php scripts/verify-notification-delivery.php --approve-post=POST_PUBLIC_ID --deliver  # 同上，并真实调用微信接口发送
 *
 * 退出码：0 = 无 FAIL 项；1 = 存在 FAIL 项。
 */

use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\CommunityPostVersion;
use App\Modules\Notification\Services\NotificationSceneConfigService;
use App\Modules\Notification\Services\NotificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../apps/api/vendor/autoload.php';
$app = require_once __DIR__.'/../apps/api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['user-id::', 'approve-post::', 'deliver']);
$failures = 0;

function pass(string $message): void { echo "[PASS] {$message}\n"; }
function info(string $message): void { echo "[INFO] {$message}\n"; }
function warn(string $message): void { echo "[WARN] {$message}\n"; }
function fail(string $message): void
{
    global $failures;
    $failures++;
    echo "[FAIL] {$message}\n";
}
function section(string $title): void { echo "\n=== {$title} ===\n"; }

// 场景 => 期望渠道
$scenes = [
    'post_published' => ['in_app', 'wechat_mini', 'wechat_oa'],
    'post_review_approved' => ['in_app', 'wechat_mini', 'wechat_oa'],
    'community_post_commented' => ['in_app', 'wechat_mini', 'wechat_oa'],
    'community_comment_replied' => ['in_app', 'wechat_mini', 'wechat_oa'],
];

section('1. 场景渠道配置');
foreach ($scenes as $scene => $expectedChannels) {
    $config = DB::table('notification_scene_configs')->where('scene_code', $scene)->first();
    if ($config === null) {
        fail("场景 {$scene} 没有配置记录，请确认迁移已执行（php artisan migrate）");
        continue;
    }
    $channels = json_decode((string) $config->channels, true) ?: [];
    $missing = array_diff($expectedChannels, $channels);
    $enabled = (bool) $config->enabled;
    if ($missing === [] && $enabled) {
        pass("{$scene} 已启用，渠道=".implode(',', $channels));
    } else {
        $detail = $missing !== [] ? '缺少渠道: '.implode(',', $missing) : '场景被禁用';
        fail("{$scene} 配置不完整（{$detail}），当前渠道=".implode(',', $channels));
    }
}

section('2. 已发布模板（微信渠道）');
foreach ($scenes as $scene => $expectedChannels) {
    foreach (['wechat_mini', 'wechat_oa'] as $channel) {
        $template = DB::table('notification_templates')
            ->where('scene_code', $scene)
            ->where('channel', $channel)
            ->where('status', 'published')
            ->first();
        $label = "{$scene} / {$channel}";
        if ($template === null) {
            warn("{$label} 未发布模板，该渠道投递会被跳过（CHANNEL_TEMPLATE_NOT_READY）");
            continue;
        }
        $version = DB::table('notification_template_versions')
            ->where('notification_template_id', $template->id)
            ->where('version_no', $template->current_version_no)
            ->first();
        $providerId = $version?->provider_template_id;
        if (empty($providerId)) {
            fail("{$label} 已发布但未填写微信模板 ID（provider_template_id）");
        } else {
            pass("{$label} 模板 ID={$providerId}");
        }
    }
}

section('3. 公众号订阅模板列表（H5 订阅页数据源）');
$oaTemplates = app(NotificationSceneConfigService::class)->wechatOfficialAccountTemplates();
if ($oaTemplates === []) {
    warn('公众号模板列表为空，H5 订阅页不会展示任何可订阅模板');
} else {
    foreach ($oaTemplates as $template) {
        pass("公众号模板 {$template['template_id']}，场景=".implode(',', $template['scenes']));
    }
}

section('4. 微信凭据');
$driver = config('wechat_auth.driver');
$driver === 'wechat'
    ? pass('wechat_auth.driver=wechat（真实调用微信接口）')
    : warn("wechat_auth.driver={$driver}，不会真实发送到微信");
foreach ([
    'official_account' => '公众号',
    'mini' => '小程序',
] as $key => $label) {
    $appId = (string) config("wechat_auth.{$key}.app_id", '');
    $secret = (string) config("wechat_auth.{$key}.app_secret", '');
    if ($appId !== '' && $secret !== '') {
        pass("{$label} app_id/secret 已配置");
    } else {
        warn("{$label} app_id 或 secret 未配置，{$label}渠道无法真实发送");
    }
}

section('5. 最近 24 小时投递统计');
$stats = DB::table('notification_deliveries')
    ->select('channel', 'status', DB::raw('count(*) as total'))
    ->where('created_at', '>=', now()->subDay())
    ->groupBy('channel', 'status')
    ->orderBy('channel')
    ->get();
if ($stats->isEmpty()) {
    info('最近 24 小时没有投递记录');
} else {
    foreach ($stats as $row) {
        info("{$row->channel} / {$row->status}: {$row->total}");
    }
}

section('6. 最近异常投递（最多 5 条）');
$errors = DB::table('notification_deliveries')
    ->whereNotNull('error_code')
    ->orderByDesc('id')
    ->limit(5)
    ->get(['channel', 'status', 'error_code', 'error_message', 'created_at']);
if ($errors->isEmpty()) {
    pass('没有异常投递记录');
} else {
    foreach ($errors as $row) {
        info("{$row->created_at} {$row->channel}/{$row->status} {$row->error_code}: {$row->error_message}");
    }
}

if (! empty($options['user-id'])) {
    $userId = (int) $options['user-id'];
    section("7. 用户 {$userId} 的微信身份与订阅授权");
    $identities = DB::table('user_identities')->where('user_id', $userId)->get(['provider', 'provider_user_value']);
    if ($identities->isEmpty()) {
        warn('该用户没有微信身份，微信渠道投递会被跳过');
    } else {
        foreach ($identities as $identity) {
            info("身份 {$identity->provider}: {$identity->provider_user_value}");
        }
    }
    $grants = DB::table('wechat_subscription_grants')->where('user_id', $userId)->get();
    if ($grants->isEmpty()) {
        info('该用户没有小程序订阅授权记录');
    }
    foreach ($grants as $grant) {
        info("授权 {$grant->provider_template_id}: status={$grant->status}, available={$grant->available_count}");
    }
}

if (! empty($options['approve-post'])) {
    $postId = (string) $options['approve-post'];
    section("8. 触发审核通过（{$postId}）");
    $post = CommunityPost::query()
        ->where('public_id', $postId)
        ->orWhere('id', is_numeric($postId) ? (int) $postId : 0)
        ->first();
    if ($post === null) {
        fail("找不到帖子 {$postId}");
    } else {
        $version = CommunityPostVersion::query()
            ->where('community_post_id', $post->id)
            ->orderByDesc('version_no')
            ->first();
        if ($version === null) {
            fail("帖子 {$postId} 没有版本记录");
        } else {
            $version->update(['review_status' => 'approved', 'reviewed_at' => now()]);
            pass("已将版本 {$version->id} 标记为 approved");
            $notification = DB::table('notifications')
                ->where('user_id', $post->user_id)
                ->where('scene_code', 'post_review_approved')
                ->orderByDesc('id')
                ->first();
            if ($notification === null) {
                fail('未生成 post_review_approved 通知，请检查观察者是否注册');
            } else {
                pass("已生成通知 #{$notification->id}");
                $deliveries = DB::table('notification_deliveries')->where('notification_id', $notification->id)->get();
                foreach ($deliveries as $delivery) {
                    $suffix = $delivery->error_code ? " error={$delivery->error_code}" : '';
                    info("投递 {$delivery->channel}: {$delivery->status}{$suffix}");
                }
                if (! empty($options['deliver'])) {
                    $sent = app(NotificationService::class)->deliverPending();
                    pass("deliverPending 处理 {$sent} 条，请核对微信是否收到消息");
                    foreach (DB::table('notification_deliveries')->where('notification_id', $notification->id)->get() as $delivery) {
                        info("投递后 {$delivery->channel}: {$delivery->status}".($delivery->error_code ? " error={$delivery->error_code}" : ''));
                    }
                } else {
                    info('未加 --deliver，不会真实调用微信接口；可稍后由 schedule:work 自动投递');
                }
            }
        }
    }
}

section('结果');
if ($failures > 0) {
    fail("共 {$failures} 项检查失败");
    exit(1);
}
pass('所有必检项通过（WARN 为可选项提醒）');
exit(0);
