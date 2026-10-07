<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiniProgramConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_exposes_mini_program_appid_without_credentials(): void
    {
        config(['wechat_auth.mini.app_id' => 'wxtestappid0001']);

        $response = $this->getJson('/api/v1/app-config');

        $response->assertOk()
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.mini_program_appid', 'wxtestappid0001');

        // 凭据字段绝不允许出现在公开响应中
        $this->assertStringNotContainsString('app_secret', (string) $response->getContent());
    }
}
