<?php

namespace Tests\Feature;

use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeContentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_content_is_public_and_returns_managed_sections(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);

        $this->getJson('/api/v1/home-content')
            ->assertOk()
            ->assertJsonPath('data.brand.name', '任务商城')
            ->assertJsonPath('data.carousel.autoplay', true)
            ->assertJsonCount(2, 'data.carousel.items')
            ->assertJsonPath('data.announcement.enabled', true)
            ->assertJsonPath('data.popup.mode', 'announcement')
            ->assertJsonCount(4, 'data.modules.quick_entries')
            ->assertJsonCount(2, 'data.modules.product_section.cards')
            ->assertJsonPath('data.modules.trust.link_type', 'assets');
    }

    public function test_bottom_navigation_is_public_and_returns_managed_items(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);

        $this->getJson('/api/v1/app-navigation')
            ->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.items.0.label', '首页')
            ->assertJsonPath('data.items.4.target', 'account')
            ->assertJsonPath('data.style.active_color', '#2F67DF');
    }
}
