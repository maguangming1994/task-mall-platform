<?php

namespace App\Providers;

use App\Modules\Assets\Contracts\PayoutProvider;
use App\Modules\Assets\Services\HttpPayoutProvider;
use App\Modules\Assets\Services\LocalPayoutProvider;
use App\Modules\Community\Models\CommunityPostVersion;
use App\Modules\Community\Models\ContentAppeal;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Fulfillment\Models\Fulfillment;
use App\Modules\Notification\Observers\NotificationBusinessObserver;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Services\LocalWechatPaymentGateway;
use App\Modules\Payment\Services\WechatPaymentSettingsService;
use App\Modules\Payment\Services\WechatPayV3Gateway;
use App\Modules\Refund\Models\Refund;
use App\Modules\Settings\Events\SystemSettingPublished;
use App\Modules\Settings\Listeners\ForgetPublishedSettingCache;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Upload\Services\ControlledUploadDiskResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WechatPaymentGateway::class, fn () => app(WechatPaymentSettingsService::class)->effective()['driver'] === 'wechat'
            ? app(WechatPayV3Gateway::class)
            : app(LocalWechatPaymentGateway::class));
        $this->app->bind(PayoutProvider::class, fn () => config('payout.driver') === 'http'
            ? app(HttpPayoutProvider::class)
            : app(LocalPayoutProvider::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('campus-list', fn (Request $request) => Limit::perMinute(60)
            ->by($this->campusRateKey($request)));
        RateLimiter::for('campus-nearby', fn (Request $request) => Limit::perMinute(20)
            ->by($this->campusRateKey($request)));

        foreach (['tencent_cos', 'qiniu_kodo'] as $provider) {
            config()->set("filesystems.disks.{$provider}", [
                'driver' => 'controlled-upload-cloud',
                'provider' => $provider,
            ]);
        }
        Storage::extend('controlled-upload-cloud', fn ($app, array $config) => $app
            ->make(ControlledUploadDiskResolver::class)
            ->disk((string) $config['provider']));

        Event::listen(SystemSettingPublished::class, ForgetPublishedSettingCache::class);
        foreach ([TaskClaim::class, Order::class, Refund::class, Fulfillment::class, CommunityPostVersion::class, ModerationAction::class, ContentAppeal::class] as $model) {
            $model::observe(NotificationBusinessObserver::class);
        }
    }

    private function campusRateKey(Request $request): string
    {
        $deviceId = trim((string) $request->header('X-Device-Id', ''));
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D', $deviceId) !== 1) {
            $deviceId = 'anonymous';
        }

        return hash('sha256', ($request->ip() ?: 'unknown').'|'.$deviceId);
    }
}
