<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Auth\Services\WechatJssdkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WechatJssdkController extends Controller
{
    public function show(Request $request, WechatJssdkService $jssdk): JsonResponse
    {
        $data = validator($request->query(), [
            'url' => ['required', 'string', 'max:1024', 'url'],
        ])->validate();

        $url = explode('#', $data['url'])[0];
        $host = parse_url($url, PHP_URL_HOST);
        $allowedHosts = array_values(array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('wechat_auth.official_account.oauth_redirect_domain', ''), PHP_URL_HOST),
        ]));

        if (! is_string($host) || $allowedHosts === []
            || ! in_array(strtolower($host), array_map('strtolower', $allowedHosts), true)) {
            return ApiResponse::error('WECHAT_JSSDK_URL_NOT_ALLOWED', '分享地址不属于本站域名。', 422);
        }

        try {
            return ApiResponse::success($jssdk->signature($url));
        } catch (Throwable $exception) {
            return ApiResponse::error('WECHAT_JSSDK_UNAVAILABLE', $exception->getMessage(), 503);
        }
    }
}
