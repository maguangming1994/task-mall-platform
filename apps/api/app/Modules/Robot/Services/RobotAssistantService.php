<?php

namespace App\Modules\Robot\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Robot\Models\RobotAssistant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RobotAssistantService
{
    /** @return array{assistant:RobotAssistant,secret:string} */
    public function create(AdminUser $admin, array $data): array
    {
        $secret = bin2hex(random_bytes(32));
        $assistant = RobotAssistant::query()->create([
            'name' => trim((string) $data['name']),
            'remark' => $this->nullableString($data['remark'] ?? null),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'app_id' => 'app_'.strtolower((string) Str::ulid()),
            'app_secret' => $secret,
            'robot_bindings' => [],
            'created_by_admin_id' => $admin->id,
            'updated_by_admin_id' => $admin->id,
        ]);

        return ['assistant' => $assistant, 'secret' => $secret];
    }

    /** @return array<string, mixed> */
    public function present(RobotAssistant $assistant): array
    {
        $bindings = array_values($assistant->robot_bindings ?? []);

        return [
            'id' => $assistant->public_id,
            'name' => $assistant->name,
            'remark' => $assistant->remark,
            'enabled' => $assistant->enabled,
            'app_id' => $assistant->app_id,
            'app_secret_masked' => $this->mask($assistant),
            'robot_bindings' => $bindings,
            'robot_count' => count($bindings),
            'created_at' => $assistant->created_at?->toIso8601String(),
            'updated_at' => $assistant->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function webhook(RobotAssistant $assistant): array
    {
        return [
            'assistant_id' => $assistant->public_id,
            'assistant_name' => $assistant->name,
            'app_id' => $assistant->app_id,
            'url' => $this->webhookUrl($assistant),
            'binding_count' => count($assistant->robot_bindings ?? []),
            'last_tested_at' => $assistant->webhook_last_tested_at?->toIso8601String(),
            'last_test_status' => $assistant->webhook_last_test_status,
            'last_test_message' => $assistant->webhook_last_test_message,
            'notice' => '该地址为预留回调地址；本地自检不代表外部平台已保存配置或公网回调可达。',
        ];
    }

    /** @param array<string, mixed> $data */
    public function addBinding(RobotAssistant $assistant, array $data): RobotAssistant
    {
        $deviceId = trim((string) $data['device_id']);
        $wxid = trim((string) $data['wxid']);
        $bindings = array_values($assistant->robot_bindings ?? []);
        $exists = collect($bindings)->contains(fn (array $binding): bool => $binding['device_id'] === $deviceId && $binding['wxid'] === $wxid);
        if ($exists) {
            throw ValidationException::withMessages(['wxid' => '该机器人已绑定到当前助手，请勿重复绑定。']);
        }

        $bindings[] = [
            'binding_id' => strtolower((string) Str::ulid()),
            'device_id' => $deviceId,
            'device_name' => $this->nullableString($data['device_name'] ?? null),
            'wxid' => $wxid,
            'account_name' => $this->nullableString($data['account_name'] ?? null),
            'bound_at' => now()->toIso8601String(),
        ];
        $assistant->robot_bindings = $bindings;
        $assistant->save();

        return $assistant->fresh();
    }

    public function removeBinding(RobotAssistant $assistant, string $bindingId): RobotAssistant
    {
        $bindings = array_values($assistant->robot_bindings ?? []);
        $remaining = collect($bindings)->reject(fn (array $binding): bool => $binding['binding_id'] === $bindingId)->values()->all();
        if (count($remaining) === count($bindings)) {
            throw ValidationException::withMessages(['binding_id' => '机器人绑定不存在或已解绑。']);
        }
        $assistant->robot_bindings = $remaining;
        $assistant->save();

        return $assistant->fresh();
    }

    /** @return array<string, mixed> */
    public function selfTest(RobotAssistant $assistant): array
    {
        $ready = $assistant->enabled && filled($assistant->app_id) && count($assistant->robot_bindings ?? []) > 0;
        try {
            $ready = $ready && filled($assistant->app_secret);
        } catch (Throwable) {
            $ready = false;
        }
        if (! $assistant->enabled) {
            $message = '本地自检未通过：助手已停用。';
        } elseif (count($assistant->robot_bindings ?? []) === 0) {
            $message = '本地自检未通过：尚未绑定机器人。';
        } else {
            $message = $ready
                ? '本地配置、凭证和机器人绑定检查通过；未请求外部平台。'
                : '本地自检未通过：助手凭证不可用。';
        }
        $assistant->forceFill([
            'webhook_last_tested_at' => now(),
            'webhook_last_test_status' => $ready ? 'LOCAL_READY' : 'LOCAL_FAILED',
            'webhook_last_test_message' => $message,
        ])->save();

        return $this->webhook($assistant->fresh());
    }

    private function webhookUrl(RobotAssistant $assistant): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/robot/webhook/'.$assistant->public_id;
    }

    private function mask(RobotAssistant $assistant): ?string
    {
        try {
            $secret = (string) $assistant->app_secret;
        } catch (Throwable) {
            return null;
        }
        if ($secret === '') {
            return null;
        }

        return strlen($secret) <= 8 ? '********' : substr($secret, 0, 4).'********'.substr($secret, -4);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
