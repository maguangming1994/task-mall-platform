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
            // 新环境尚未播种定义，SystemSettingDefinitionSeeder 的默认值已是 0（无门槛）。
            return;
        }

        // 同步定义层的默认值与校验下限，允许配置为 0（0 表示不设单笔最低提现门槛）。
        $definition->update([
            'default_value' => 0,
            'validation_rules' => ['min' => 0, 'max' => 100000000],
        ]);

        $latest = $definition->versions()->latest('version')->first();
        if ($latest === null || (int) $latest->value === 0) {
            return;
        }
        if (SystemSettingDraft::query()->where('setting_definition_id', $definition->id)->exists()) {
            // 配置中心存在在途草稿时不自动插入版本，避免与人工发布流程的 base_version 冲突；
            // 由运营在后台按新下限保存并发布。
            Log::warning('提现门槛迁移跳过：存在在途配置草稿，请在配置中心手动发布 0 元（无门槛）下限。', ['setting_key' => self::SETTING_KEY]);

            return;
        }

        SystemSettingVersion::query()->create([
            'setting_definition_id' => $definition->id,
            'version' => ((int) $latest->version) + 1,
            'value' => 0,
            'source' => 'publish',
            'reason' => '按小程序审核整改要求取消单笔最低提现门槛（0 元起提，货币最小单位 0.01 元仍可提现）。',
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
