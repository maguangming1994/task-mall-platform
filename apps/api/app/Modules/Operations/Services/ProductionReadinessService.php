<?php

namespace App\Modules\Operations\Services;

class ProductionReadinessService
{
    /** @return array{ready:bool,checked_at:string,checks:array<int,array<string,mixed>>,summary:array<string,int>} */
    public function evaluate(): array
    {
        $checks = [
            $this->check('runtime.environment', '运行环境为 production', app()->environment('production'), '部署时设置 APP_ENV=production。'),
            $this->check('runtime.debug', '生产调试已关闭', ! (bool) config('app.debug'), '设置 APP_DEBUG=false。'),
            $this->check('runtime.https', '服务地址使用 HTTPS', str_starts_with((string) config('app.url'), 'https://'), '配置正式 HTTPS APP_URL。'),
            $this->check('security.user_secrets', '用户令牌和身份摘要使用独立密钥', $this->secretPairReady(config('auth_tokens.jwt_secret'), config('auth_tokens.identity_hash_secret')), '注入彼此不同的高强度 AUTH_JWT_SECRET 与 AUTH_IDENTITY_HASH_KEY。'),
            $this->check('security.admin_secrets', '后台令牌和摘要使用独立密钥', $this->secretPairReady(config('admin_auth.jwt_secret'), config('admin_auth.hash_secret')) && config('admin_auth.jwt_secret') !== config('auth_tokens.jwt_secret'), '注入与用户端不同的 ADMIN_AUTH_JWT_SECRET 与 ADMIN_AUTH_HASH_KEY。'),
            $this->check('security.cors', '跨域来源使用 HTTPS 明确白名单', $this->corsReady(), '设置不含通配符的 HTTPS CORS_ALLOWED_ORIGINS。'),
            $this->check('security.monitoring', '监控指标使用独立高强度令牌', $this->secretReady(config('monitoring.metrics_token')), '注入至少 32 位独立 MONITORING_METRICS_TOKEN。'),
            $this->check('infra.database', '数据库使用 PostgreSQL', config('database.default') === 'pgsql', '生产 DB_CONNECTION 必须为 pgsql。'),
            $this->check('infra.cache', '缓存使用 Redis', config('cache.default') === 'redis', '生产 CACHE_STORE 必须为 redis。'),
            $this->check('infra.queue', '队列使用 Redis', config('queue.default') === 'redis', '生产 QUEUE_CONNECTION 必须为 redis。'),
            $this->check('wechat.driver', '微信使用真实适配器', config('wechat_auth.driver') === 'wechat', '设置 WECHAT_AUTH_DRIVER=wechat。'),
            $this->check('wechat.mini', '小程序 AppID/AppSecret 已配置', $this->filled('wechat_auth.mini.app_id') && $this->filled('wechat_auth.mini.app_secret'), '提供 WECHAT_MINI_APP_ID 与 WECHAT_MINI_APP_SECRET。'),
            $this->check('wechat.oa', '公众号 AppID/AppSecret 已配置', $this->filled('wechat_auth.official_account.app_id') && $this->filled('wechat_auth.official_account.app_secret'), '提供 WECHAT_OA_APP_ID 与 WECHAT_OA_APP_SECRET。'),
            $this->check('wechat.domains', '微信授权域名已登记', config('production_readiness.authorized_domains', []) !== [], '设置 WECHAT_AUTHORIZED_DOMAINS 并在微信后台完成登记。'),
            $this->check('payment.driver', '微信支付使用真实适配器', config('wechat_payment.driver') === 'wechat', '设置 WECHAT_PAYMENT_DRIVER=wechat。'),
            $this->check('payment.merchant', '微信支付商户号、证书序列号和私钥已配置', $this->filled('wechat_payment.merchant_id') && $this->filled('wechat_payment.merchant_certificate_serial') && $this->filled('wechat_payment.merchant_private_key_path'), '提供微信支付商户号、商户证书序列号和只读私钥路径。'),
            $this->check('payment.callback', '微信支付 APIv3 密钥、平台证书与 HTTPS 回调已配置', $this->secretReady(config('wechat_payment.api_v3_key')) && is_file((string) config('wechat_payment.platform_certificate_path')) && str_starts_with((string) config('wechat_payment.notify_url'), 'https://'), '提供 32 字节 APIv3 密钥、微信支付平台证书路径和正式 HTTPS 支付回调地址。'),
            $this->check('sms.provider', '短信网关已配置', config('auth_tokens.phone_verification.driver') === 'webhook' && $this->filled('auth_tokens.phone_verification.webhook.endpoint') && $this->filled('auth_tokens.phone_verification.webhook.token'), '配置 PHONE_VERIFICATION_DRIVER=webhook、SMS_WEBHOOK_ENDPOINT 与令牌。'),
            $this->check('sms.template', '短信签名和模板已配置', $this->filled('auth_tokens.phone_verification.webhook.template_id') && $this->filled('auth_tokens.phone_verification.webhook.sign_name'), '提供已审核的 SMS_TEMPLATE_ID 与 SMS_SIGN_NAME。'),
            $this->check('notification.provider', '外部消息投递网关已配置', config('notifications.driver') === 'webhook' && $this->filled('notifications.webhook.endpoint') && $this->secretReady(config('notifications.webhook.token')), '配置 NOTIFICATION_EXTERNAL_DRIVER=webhook、消息端点和至少 32 位独立令牌。'),
            $this->check('upload.storage', '上传使用 S3 预签名私有存储', config('uploads.mode') === 's3_presigned' && config('uploads.disk') === 's3', '配置 UPLOAD_MODE=s3_presigned、UPLOAD_DISK=s3 和生产桶凭据。'),
            $this->check('upload.scanner', '外部文件扫描器已配置', config('uploads.scanner') === 'webhook' && $this->filled('uploads.scanner_webhook.endpoint') && $this->filled('uploads.scanner_webhook.token'), '配置 UPLOAD_SCANNER=webhook、扫描端点与令牌。'),
            $this->check('compliance.entity', '运营主体和联系人已填写', $this->filled('production_readiness.operator_entity_name') && $this->filled('production_readiness.customer_service_contact') && $this->filled('production_readiness.privacy_contact'), '填写运营主体、客服和隐私联系人。'),
            $this->check('compliance.legal', '正式协议已完成法务批准', (bool) config('production_readiness.legal_documents_approved'), '正式文本发布并复核后设置 LEGAL_DOCUMENTS_APPROVED=true。'),
        ];
        $passed = count(array_filter($checks, fn (array $check) => $check['ready']));

        return [
            'ready' => $passed === count($checks),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
            'summary' => ['total' => count($checks), 'passed' => $passed, 'failed' => count($checks) - $passed],
        ];
    }

    /** @return array{code:string,label:string,ready:bool,action:?string} */
    private function check(string $code, string $label, bool $ready, string $action): array
    {
        return ['code' => $code, 'label' => $label, 'ready' => $ready, 'action' => $ready ? null : $action];
    }

    private function filled(string $key): bool
    {
        return is_string(config($key)) && trim((string) config($key)) !== '';
    }

    private function secretReady(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= 32 && ! str_starts_with($value, 'base64:AAAAAAAA');
    }

    private function secretPairReady(mixed $first, mixed $second): bool
    {
        return $this->secretReady($first) && $this->secretReady($second) && ! hash_equals((string) $first, (string) $second);
    }

    private function corsReady(): bool
    {
        $origins = config('cors.allowed_origins', []);

        return is_array($origins) && $origins !== [] && ! in_array('*', $origins, true) && collect($origins)->every(fn ($origin) => is_string($origin) && str_starts_with($origin, 'https://'));
    }
}
