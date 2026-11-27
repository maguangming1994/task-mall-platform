<?php

use App\Modules\Settings\Models\SystemSettingDefinition;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // 页面方案与小票模板使用独立设置键存储，不改动已上线的 decoration.pages。
        SystemSettingDefinition::query()->updateOrCreate(
            ['setting_key' => 'decoration.schemes'],
            [
                'group_code' => 'decoration',
                'name' => '页面装修方案',
                'description' => '由装修中心维护同一页面的多套方案；启用公众号或小程序渠道后立即生效，未启用方案时沿用原有页面装修。',
                'value_type' => 'object',
                'unit' => null,
                'options' => null,
                'validation_rules' => [
                    'required_keys' => ['schema_version', 'pages', 'schemes'],
                    'properties' => [
                        'schema_version' => ['type' => 'integer', 'min' => 1],
                        'pages' => ['type' => 'object'],
                        'schemes' => ['type' => 'array'],
                    ],
                ],
                'default_value' => [
                    'schema_version' => 1,
                    'pages' => [
                        'home' => ['h5' => null, 'miniapp' => null],
                        'community' => ['h5' => null, 'miniapp' => null],
                        'mall' => ['h5' => null, 'miniapp' => null],
                        'account' => ['h5' => null, 'miniapp' => null],
                    ],
                    'schemes' => [],
                ],
                'risk_level' => 'normal',
                'enabled' => true,
            ],
        );

        SystemSettingDefinition::query()->updateOrCreate(
            ['setting_key' => 'decoration.receipt_templates'],
            [
                'group_code' => 'decoration',
                'name' => '小票打印模板',
                'description' => '由装修中心维护小票打印模板；绑定到打印场景并启用后覆盖小票机的自定义模板。',
                'value_type' => 'object',
                'unit' => null,
                'options' => null,
                'validation_rules' => [
                    'required_keys' => ['schema_version', 'templates', 'bindings'],
                    'properties' => [
                        'schema_version' => ['type' => 'integer', 'min' => 1],
                        'templates' => ['type' => 'array'],
                        'bindings' => ['type' => 'object'],
                    ],
                ],
                'default_value' => [
                    'schema_version' => 1,
                    'templates' => [],
                    'bindings' => [
                        'express_pickup' => ['receipt' => null, 'split' => null, 'label' => null],
                        'delivery' => ['receipt' => null, 'split' => null, 'label' => null],
                        'pickup' => ['receipt' => null, 'split' => null, 'label' => null],
                        'dine_in' => ['receipt' => null, 'split' => null, 'label' => null],
                        'wechat_reserve' => ['receipt' => null, 'split' => null, 'label' => null],
                    ],
                ],
                'risk_level' => 'normal',
                'enabled' => true,
            ],
        );
    }

    public function down(): void
    {
        // 保留已保存的方案与模板，避免回滚迁移时丢失运营配置。
    }
};
