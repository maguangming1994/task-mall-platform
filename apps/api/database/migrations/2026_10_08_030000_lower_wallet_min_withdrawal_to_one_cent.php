<?php

use App\Modules\Settings\Models\SystemSettingDefinition;
use App\Modules\Settings\Models\SystemSettingDraft;
use App\Modules\Settings\Models\SystemSettingVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const SETTING_KEY = 'wallet.min_withdrawal_cents';

    public function up(): void
    {
        $definition = SystemSettingDefinition::query()->where('setting_key', self::SETTING_KEY)->first();
        if ($definition === null) {
            // 新环境尚未播种定义，SystemSettingDefinitionSeeder 的默认值已是 1 分。
            return;
        }

        // 同步收窄定义层的默认值与校验下限，使其与新版本一致（不依赖 seeder 重跑）。
        $definition->update([
            'default_value' => 1,
            'validation_rules' => ['min' => 1, 'max' => 100000000],
        ]);

        $latest = $definition->versions()->latest('version')->first();
        if ($latest === null || (int) $latest->value <= 1) {
            return;
        }
        if (SystemSettingDraft::query()->where('setting_definition_id', $definition->id)->exists()) {
            // 配置中心存在在途草稿时不自动插入版本，避免与人工发布流程的 base_version 冲突；
            // 由运营在后台按新下限保存并发布。
            Log::warning('提现门槛迁移跳过：存在在途配置草稿，请在配置中心手动发布 0.01 元下限。', ['setting_key' => self::SETTING_KEY]);

            return;
        }

        SystemSettingVersion::query()->create([
            'setting_definition_id' => $definition->id,
            'version' => ((int) $latest->version) + 1,
            'value' => 1,
            'source' => 'publish',
            'reason' => '按小程序审核要求降低提现门槛至 0.01 元，避免小额余额用户无法发起提现。',
            'published_at' => now(),
        ]);

        Cache::forget('system-setting:active:'.self::SETTING_KEY);
        Cache::forget('system-setting:last-good:'.self::SETTING_KEY);
    }

    public function down(): void
    {
        // 风控门槛收窄不回滚；如需恢复请在配置中心调整并发布。
    }
};
