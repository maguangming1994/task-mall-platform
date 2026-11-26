<?php

use App\Modules\Settings\Models\SystemSettingDefinition;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SystemSettingDefinition::query()->updateOrCreate(
            ['setting_key' => 'decoration.pages'],
            [
                'group_code' => 'decoration',
                'name' => '页面装修',
                'description' => '由装修中心维护首页、社区、商城与我的页面的区块；保存草稿不影响线上，发布后用户端自动刷新。',
                'value_type' => 'object',
                'unit' => null,
                'options' => null,
                'validation_rules' => ['required_keys' => ['schema_version', 'pages'], 'properties' => [
                    'schema_version' => ['type' => 'integer', 'min' => 1],
                    'pages' => ['type' => 'object'],
                ]],
                'default_value' => [
                    'schema_version' => 1,
                    'pages' => [
                        'home' => ['background_url' => '', 'sections' => []],
                        'community' => ['background_url' => '', 'sections' => []],
                        'mall' => ['background_url' => '', 'sections' => []],
                        'account' => ['background_url' => '', 'sections' => []],
                    ],
                ],
                'risk_level' => 'normal',
                'enabled' => true,
            ],
        );
    }

    public function down(): void
    {
        // 保留已发布版本与草稿，避免回滚迁移时丢失装修配置。
    }
};
