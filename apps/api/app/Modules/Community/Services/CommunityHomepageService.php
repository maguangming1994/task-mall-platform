<?php

namespace App\Modules\Community\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Settings\Exceptions\SettingException;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Validation\ValidationException;

class CommunityHomepageService
{
    public const SETTING_KEY = 'community.homepage';

    public const DEFAULTS = [
        'brand' => [
            'logo_url' => '',
            'logo_text' => '校',
            'name' => '任务商城校园',
            'caption' => '同学都在这里',
        ],
        'banner' => [
            'title' => '校园互助社区',
            'subtitle' => '求助、经验、组团，让校园生活更简单',
            'image_url' => '',
            'link_type' => 'posts',
            'accent_color' => '#20D68B',
        ],
        'quick_entries' => [
            ['id' => 'publish', 'label' => '发布帖子', 'description' => '分享、吐槽、求助', 'icon_url' => '', 'target' => 'publish_post', 'enabled' => true, 'color' => '#20D68B', 'sort_order' => 10],
            ['id' => 'activity', 'label' => '校园组团', 'description' => '自习、拼车、运动', 'icon_url' => '', 'target' => 'activities', 'enabled' => true, 'color' => '#FF5964', 'sort_order' => 20],
            ['id' => 'help', 'label' => '有偿求助', 'description' => '去任务大厅发布需求', 'icon_url' => '', 'target' => 'tasks', 'enabled' => true, 'color' => '#FFD43B', 'sort_order' => 30],
        ],
        'hot_topics' => [
            ['id' => 'campus-help', 'label' => '#校园互助', 'target' => 'question', 'enabled' => true, 'color' => '#20D68B'],
            ['id' => 'study-together', 'label' => '#自习搭子', 'target' => 'study', 'enabled' => true, 'color' => '#4D9CFF'],
            ['id' => 'carpool', 'label' => '#校园拼车', 'target' => 'carpool', 'enabled' => true, 'color' => '#FF8A4C'],
        ],
    ];

    public function read(): array
    {
        try {
            $setting = $this->settings->read(self::SETTING_KEY);
            $value = is_array($setting['value']) ? $setting['value'] : [];

            return [...self::DEFAULTS, ...$value, 'version' => $setting['version']];
        } catch (SettingException) {
            return [...self::DEFAULTS, 'version' => null];
        }
    }

    public function update(AdminUser $admin, array $value, string $reason): array
    {
        $this->validate($value);
        $current = $this->read();
        if (($value['expected_version'] ?? null) !== null && $value['expected_version'] !== $current['version']) {
            throw ValidationException::withMessages(['expected_version' => '社区首页配置已被其他管理员更新，请刷新后重试。']);
        }
        unset($value['expected_version']);
        $this->settings->saveDraft(self::SETTING_KEY, $value, $reason, $admin);
        $this->settings->publish(self::SETTING_KEY, $admin);

        return $this->read();
    }

    private function validate(array $value): void
    {
        if (! isset($value['brand'], $value['banner'], $value['quick_entries'], $value['hot_topics']) || ! is_array($value['brand']) || ! is_array($value['banner']) || ! is_array($value['quick_entries']) || ! is_array($value['hot_topics'])) {
            throw ValidationException::withMessages(['config' => '必须提供 brand、banner、quick_entries 和 hot_topics。']);
        }
        if (count($value['quick_entries']) > 8 || count($value['hot_topics']) > 12) {
            throw ValidationException::withMessages(['config' => '快捷入口最多 8 个，热门话题最多 12 个。']);
        }
    }

    public function __construct(private readonly SystemSettingService $settings) {}
}
