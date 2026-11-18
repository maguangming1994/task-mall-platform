<?php

namespace App\Modules\Auth\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 公众号自定义菜单。
 *
 * 菜单指向公众号内 H5（hash 路由），发布后微信客户端顶部菜单即生效；
 * 微信侧最多 3 个一级菜单、每个一级菜单最多 5 个二级菜单。
 */
class WechatOfficialAccountMenuService
{
    private const HTTP_TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly WechatProviderSettingsService $settings,
        private readonly WechatAccessTokenService $tokens,
    ) {}

    /**
     * 默认菜单：与公众号端主要入口保持一致。
     *
     * @return array<int,array<string,string>>
     */
    public function defaultButtons(): array
    {
        return [
            ['type' => 'view', 'name' => '逛逛', 'url' => $this->siteUrl('pages/index/index')],
            ['type' => 'view', 'name' => '做任务', 'url' => $this->siteUrl('pages/tasks/index')],
            ['type' => 'view', 'name' => '我的', 'url' => $this->siteUrl('pages/account/index')],
        ];
    }

    /** @return array{configured:bool,button:array<int,mixed>,default_button:array<int,mixed>} */
    public function current(): array
    {
        $this->assertConfigured();
        $payload = $this->request('get', '/cgi-bin/menu/get');
        $button = data_get($payload, 'menu.button');
        if (! is_array($button)) {
            // 46003：菜单不存在，属于正常状态，直接展示默认菜单供发布。
            return ['configured' => false, 'button' => $this->defaultButtons(), 'default_button' => $this->defaultButtons()];
        }

        return ['configured' => true, 'button' => $button, 'default_button' => $this->defaultButtons()];
    }

    /**
     * @param array<int,array<string,mixed>>|null $buttons
     * @return array{configured:bool,button:array<int,mixed>,default_button:array<int,mixed>}
     */
    public function publish(?array $buttons = null): array
    {
        $this->assertConfigured();
        $buttons = $buttons ?: $this->defaultButtons();
        $this->assertButtons($buttons);
        $this->request('post', '/cgi-bin/menu/create', ['button' => $buttons]);

        return $this->current();
    }

    /** @param array<int,array<string,mixed>> $buttons */
    private function assertButtons(array $buttons): void
    {
        if ($buttons === [] || count($buttons) > 3) {
            throw new RuntimeException('公众号一级菜单需要 1-3 个。');
        }
        foreach ($buttons as $button) {
            $name = trim((string) ($button['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 8) {
                throw new RuntimeException('公众号菜单名称不能为空且不超过 8 个字。');
            }
            $type = (string) ($button['type'] ?? '');
            if (! in_array($type, ['view', 'miniprogram', 'click'], true)) {
                throw new RuntimeException('公众号菜单仅支持跳转网页或小程序。');
            }
            if ($type === 'view' && ! is_string($button['url'] ?? null)) {
                throw new RuntimeException('跳转网页菜单必须填写 URL。');
            }
        }
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, array $body = [], bool $refreshed = false): array
    {
        try {
            $token = $this->tokens->token('official_account', $refreshed);
            $response = Http::acceptJson()
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withQueryParameters(['access_token' => $token])
                ->{$method}('https://api.weixin.qq.com'.$path, $body);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('公众号菜单接口连接失败，请稍后重试。', 0, $exception);
        }
        $payload = $response->json();
        $code = is_array($payload) ? (int) ($payload['errcode'] ?? -1) : -1;
        if ($code === 0 || $code === 46003) {
            return is_array($payload) ? $payload : [];
        }
        if (! $refreshed && in_array($code, [40001, 40014, 42001], true)) {
            return $this->request($method, $path, $body, true);
        }

        throw new RuntimeException('公众号菜单接口调用失败（'.$code.'），请检查公众号凭据与接口权限。');
    }

    private function assertConfigured(): void
    {
        $configuration = $this->settings->effective('official_account');
        if ($configuration['driver'] !== 'wechat' || $configuration['app_id'] === '' || blank($configuration['app_secret'])) {
            throw new RuntimeException('请先保存完整的微信公众号配置。');
        }
    }

    private function siteUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/#/'.ltrim($path, '/');
    }
}
