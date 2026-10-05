<?php

use App\Common\Exceptions\IdempotencyConflictException;
use App\Common\Middleware\AddSecurityHeaders;
use App\Common\Middleware\AttachRequestId;
use App\Common\Middleware\MeasureRequestPerformance;
use App\Common\Support\ApiResponse;
use App\Modules\Admin\Http\Middleware\AuthenticateAdminAccessToken;
use App\Modules\Admin\Http\Middleware\RequireAdminPermission;
use App\Modules\Admin\Http\Middleware\RequireRecentAdminVerification;
use App\Modules\Auth\Http\Middleware\AuthenticateAccessToken;
use App\Modules\Auth\Http\Middleware\AuthenticateOptionalAccessToken;
use App\Modules\Campus\Http\Middleware\EnforceAdminCampusScope;
use App\Modules\Campus\Http\Middleware\ResolveCampusContext;
use App\Modules\Legal\Http\Middleware\RequireLegalConsent;
use App\Modules\Operations\Http\Middleware\RequireModuleEnabled;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Refund\Exceptions\RefundException;
use App\Modules\Settings\Exceptions\SettingException;
use App\Modules\Task\Http\Middleware\AuthenticateTaskPublisher;
use App\Modules\Upload\Exceptions\UploadException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1,172.16.0.0/12')),
        )));
        $middleware->trustProxies(
            at: $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->append(AttachRequestId::class);
        $middleware->append(AddSecurityHeaders::class);
        $middleware->append(MeasureRequestPerformance::class);
        $middleware->append(ResolveCampusContext::class);
        $middleware->alias([
            'auth.access' => AuthenticateAccessToken::class,
            'auth.optional' => AuthenticateOptionalAccessToken::class,
            'auth.admin' => AuthenticateAdminAccessToken::class,
            'admin.permission' => RequireAdminPermission::class,
            'admin.recent_verification' => RequireRecentAdminVerification::class,
            'admin.campus' => EnforceAdminCampusScope::class,
            'legal.consent' => RequireLegalConsent::class,
            'auth.publisher' => AuthenticateTaskPublisher::class,
            'module.gate' => RequireModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (IdempotencyConflictException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                IdempotencyConflictException::ERROR_CODE,
                $exception->getMessage(),
                IdempotencyConflictException::STATUS,
            );
        });
        $exceptions->render(function (PrivacyException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        });
        $exceptions->render(function (UploadException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        });
        $exceptions->render(function (SettingException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        });
        $exceptions->render(function (RefundException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'VALIDATION_FAILED',
                '请求参数校验失败。',
                422,
                ['errors' => $exception->errors()],
            );
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $code = $status === 429 ? 'RATE_LIMITED' : 'HTTP_ERROR';
            $message = $status === 429 ? '请求过于频繁，请稍后再试。' : '请求无法处理。';

            return ApiResponse::error($code, $message, $status);
        });
    })->create();
