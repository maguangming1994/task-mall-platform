<?php

namespace App\Providers;

use App\Modules\Assets\Contracts\PayoutProvider;
use App\Modules\Assets\Services\HttpPayoutProvider;
use App\Modules\Assets\Services\LocalPayoutProvider;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Services\LocalWechatPaymentGateway;
use App\Modules\Payment\Services\WechatPayV3Gateway;
use App\Modules\Settings\Events\SystemSettingPublished;
use App\Modules\Settings\Listeners\ForgetPublishedSettingCache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WechatPaymentGateway::class, fn () => config('wechat_payment.driver') === 'wechat'
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
        Event::listen(SystemSettingPublished::class, ForgetPublishedSettingCache::class);
    }
}
