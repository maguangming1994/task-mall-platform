<?php

namespace App\Modules\Robot\Services;

use App\Modules\Robot\Exceptions\XpRobotException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class XpRobotClient
{
    public function __construct(private readonly XpRobotSettingsService $settings) {}

    /** @return array{items:array<int,mixed>,total:int,online_count:int} */
    public function devices(?string $status = null): array
    {
        $data = $this->request('GET', '/api/tenant/devices', array_filter([
            'status' => $status,
        ], fn (?string $value): bool => filled($value)));
        $items = $this->listData($data, ['devices']);

        return [
            'items' => $items,
            'total' => (int) ($data['total'] ?? count($items)),
            'online_count' => (int) ($data['online_count'] ?? 0),
        ];
    }

    /** @return array{items:array<int,mixed>} */
    public function accounts(string $deviceId): array
    {
        $data = $this->request('GET', '/api/wx/accounts', [
            'device_id' => $deviceId,
            'timeout' => 30000,
        ]);

        return ['items' => $this->listData($data, ['accounts', 'wx_accounts'])];
    }

    /** @return array{items:array<int,mixed>} */
    public function groups(string $deviceId, string $wxid, bool $forceRealtime = false): array
    {
        $data = $this->request('GET', '/api/wx/groups/realtime', [
            'device_id' => $deviceId,
            'wxid' => $wxid,
            'timeout' => 30000,
            'force_realtime' => $forceRealtime ? 'true' : 'false',
        ]);

        return ['items' => $this->listData($data, ['groups'])];
    }

    /** @return array<string, mixed> */
    public function sendGroupText(string $deviceId, string $wxid, string $groupId, string $message): array
    {
        return $this->request('POST', '/api/msg/send-group', body: [
            'device_id' => $deviceId,
            'wxid' => $wxid,
            'group_id' => $groupId,
            'msg_type' => 'text',
            'message' => $message,
            'timeout' => 30000,
        ]);
    }

    /**
     * 发送群图片：XP 无独立上传接口，message 传公网可访问的图片 URL（或设备本地路径）。
     *
     * @return array<string, mixed>
     */
    public function sendGroupImage(string $deviceId, string $wxid, string $groupId, string $imageUrl): array
    {
        return $this->request('POST', '/api/msg/send-group', body: [
            'device_id' => $deviceId,
            'wxid' => $wxid,
            'group_id' => $groupId,
            'msg_type' => 'image',
            'message' => $imageUrl,
            'timeout' => 60000,
        ]);
    }

    /**
     * 发送链接卡片到群（个人微信 / 企业微信 / QQ 通用）。
     *
     * @param  array{link_url:string,title:string,desc?:string|null,image_url?:string|null,conversation_id?:string|null}  $fields
     * @return array<string, mixed>
     */
    public function sendGroupLinkCard(string $deviceId, string $wxid, string $groupId, array $fields): array
    {
        $body = [
            'device_id' => $deviceId,
            'wxid' => $wxid,
            'group_id' => $groupId,
            'link_url' => $fields['link_url'],
            'title' => $fields['title'],
            'timeout' => 30000,
        ];
        foreach (['desc', 'image_url', 'conversation_id'] as $optional) {
            if (filled($fields[$optional] ?? null)) {
                $body[$optional] = $fields[$optional];
            }
        }

        return $this->request('POST', '/api/msg/send-link-card', body: $body);
    }

    /** @return array<string, mixed> */
    public function messageStatus(string $cmdId): array
    {
        return $this->request('GET', '/api/msg/request/status', ['cmd_id' => $cmdId]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $pending = Http::baseUrl(rtrim((string) config('services.xp.base_url'), '/'))
            ->withHeader('X-API-Key', $this->settings->apiKey())
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(max(5, (int) config('services.xp.timeout_seconds', 35)));

        try {
            $response = $method === 'GET'
                ? $pending->get($path, $query)
                : $pending->post($path, $body);
        } catch (ConnectionException $exception) {
            throw new XpRobotException(
                'XP 服务暂时无法连接；如为发送请求，结果可能已受理，请通过本地记录刷新状态。',
                true,
                $exception,
            );
        }

        $payload = $response->json();
        $rejected = $response->failed()
            || ! is_array($payload)
            || (array_key_exists('code', $payload) && (int) $payload['code'] !== 200);
        if ($rejected) {
            $indeterminate = $response->serverError();
            throw new XpRobotException(
                $indeterminate
                    ? 'XP 服务暂时不可用；如为发送请求，结果可能已受理，请通过本地记录刷新状态。'
                    : 'XP 服务请求失败，请检查 API Key、设备、账号与群参数。',
                $indeterminate,
            );
        }

        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<int, mixed>
     */
    private function listData(array $data, array $keys): array
    {
        if (array_is_list($data)) {
            return array_values($data);
        }
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values($data[$key]);
            }
        }

        return [];
    }
}
