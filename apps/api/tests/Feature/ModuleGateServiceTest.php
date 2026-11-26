<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Operations\Models\CampusFeatureFlag;
use App\Modules\Operations\Models\FeatureFlag;
use App\Modules\Operations\Services\ModuleGateService;
use App\Modules\Operations\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ModuleGateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function globalFlag(string $code, string $mode = 'enabled', int $percentage = 100, ?array $audience = null): void
    {
        // 基类已播种 13 个全局开关（镜像生产），这里按需覆盖具体模式
        FeatureFlag::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $code,
                'mode' => $mode,
                'rollout_percentage' => $percentage,
                'audience' => $audience,
                'version' => 1,
            ],
        );
    }

    private function override(Campus $campus, string $code, string $status): void
    {
        CampusFeatureFlag::query()->create([
            'campus_id' => $campus->id,
            'code' => $code,
            'status' => $status,
        ]);
    }

    public function test_three_state_overrides_when_global_enabled(): void
    {
        $this->globalFlag('module_tasks');
        $disabled = Campus::query()->create(['code' => 'gate-a', 'name' => '覆盖关闭校', 'status' => 'active']);
        $enabled = Campus::query()->create(['code' => 'gate-b', 'name' => '覆盖开启校', 'status' => 'active']);
        $inherit = Campus::query()->create(['code' => 'gate-c', 'name' => '无覆盖校', 'status' => 'active']);
        $this->override($disabled, 'module_tasks', 'disabled');
        $this->override($enabled, 'module_tasks', 'enabled');

        $gate = app(ModuleGateService::class);

        $this->assertFalse($gate->effective('module_tasks', $disabled->id, 'device-1'));
        $this->assertTrue($gate->effective('module_tasks', $enabled->id, 'device-1'));
        $this->assertTrue($gate->effective('module_tasks', $inherit->id, 'device-1'));
        // 无校区上下文匿名请求跟随全局
        $this->assertTrue($gate->effective('module_tasks', null, 'device-1'));
    }

    public function test_global_disabled_overrides_every_campus_even_when_enabled(): void
    {
        $this->globalFlag('module_tasks', 'disabled', 0);
        $campus = Campus::query()->create(['code' => 'gate-g', 'name' => '全局关闭校', 'status' => 'active']);
        $this->override($campus, 'module_tasks', 'enabled');

        $this->assertFalse(app(ModuleGateService::class)->effective('module_tasks', $campus->id, 'device-1'));
    }

    public function test_missing_global_flag_cannot_be_opened_by_campus(): void
    {
        // 模拟全局开关记录缺失（未播种/被误删）：fail-close，校区不能自行开启
        FeatureFlag::query()->where('code', 'module_checkin')->delete();
        $campus = Campus::query()->create(['code' => 'gate-missing', 'name' => '无全局开关校', 'status' => 'active']);
        $this->override($campus, 'module_checkin', 'enabled');

        $this->assertFalse(app(ModuleGateService::class)->effective('module_checkin', $campus->id, 'device-1'));
    }

    public function test_parent_chain_closes_children(): void
    {
        $this->globalFlag('module_mall');
        $this->globalFlag('module_mall_trade');
        $this->globalFlag('module_after_sale');
        $campus = Campus::query()->create(['code' => 'gate-parent', 'name' => '父链校', 'status' => 'active']);
        $this->override($campus, 'module_mall', 'disabled');
        $this->override($campus, 'module_mall_trade', 'enabled');
        $this->override($campus, 'module_after_sale', 'enabled');

        $gate = app(ModuleGateService::class);

        $this->assertFalse($gate->effective('module_mall', $campus->id, 'device-1'));
        $this->assertFalse($gate->effective('module_mall_trade', $campus->id, 'device-1'));
        $this->assertFalse($gate->effective('module_after_sale', $campus->id, 'device-1'));
    }

    public function test_percentage_global_merges_with_campus_overrides(): void
    {
        // 0% 灰度 + 单设备白名单：未命中设备 inherit 跟随为关，enabled 校区恒开
        $this->globalFlag('module_community', 'percentage', 0, ['subject_allowlist' => ['device-allow']]);
        $inherit = Campus::query()->create(['code' => 'pct-inherit', 'name' => '灰度跟随校', 'status' => 'active']);
        $enabled = Campus::query()->create(['code' => 'pct-enabled', 'name' => '灰度全开标校', 'status' => 'active']);
        $disabled = Campus::query()->create(['code' => 'pct-disabled', 'name' => '灰度关闭校', 'status' => 'active']);
        $this->override($enabled, 'module_community', 'enabled');
        $this->override($disabled, 'module_community', 'disabled');

        $gate = app(ModuleGateService::class);

        $this->assertFalse($gate->effective('module_community', $inherit->id, 'device-a'));
        $this->assertTrue($gate->effective('module_community', $inherit->id, 'device-allow'));
        $this->assertTrue($gate->effective('module_community', $enabled->id, 'device-a'));
        $this->assertFalse($gate->effective('module_community', $disabled->id, 'device-allow'));
    }

    public function test_all_effective_returns_all_21_module_keys(): void
    {
        foreach (app(ModuleRegistry::class)->codes() as $code) {
            $this->globalFlag($code);
        }

        $result = app(ModuleGateService::class)->allEffective(null, 'device-1');

        $this->assertCount(21, $result);
        $this->assertCount(21, array_filter($result));
    }

    public function test_override_cache_is_cleared_after_forget_campus(): void
    {
        $this->globalFlag('module_second_hand');
        $campus = Campus::query()->create(['code' => 'cache-campus', 'name' => '缓存校', 'status' => 'active']);
        $gate = app(ModuleGateService::class);

        $this->assertTrue($gate->effective('module_second_hand', $campus->id, 'device-1'));

        // 绕过服务直接写入覆盖；60s 缓存内仍为旧值，forget 后立即生效
        $this->override($campus, 'module_second_hand', 'disabled');
        $this->assertTrue($gate->effective('module_second_hand', $campus->id, 'device-1'));

        $gate->forgetCampus($campus->id);
        $this->assertFalse($gate->effective('module_second_hand', $campus->id, 'device-1'));
    }

    public function test_request_context_prefers_logged_in_user_campus_over_header(): void
    {
        $this->globalFlag('module_tasks');
        $headerCampus = Campus::query()->create(['code' => 'ctx-header', 'name' => '请求头校', 'status' => 'active']);
        $userCampus = Campus::query()->create(['code' => 'ctx-user', 'name' => '用户校', 'status' => 'active']);
        $this->override($userCampus, 'module_tasks', 'disabled');

        $user = User::factory()->create(['campus_id' => $userCampus->id]);

        $request = Request::create('/api/v1/tasks', 'GET');
        $request->headers->set('X-Device-Id', 'device-9');
        $request->headers->set('X-Client-Platform', 'wechat-mini');
        $request->attributes->set('campus_database_id', $headerCampus->id);
        $request->setUserResolver(fn () => $user);

        $gate = app(ModuleGateService::class);

        $this->assertSame($userCampus->id, $gate->campusIdFromRequest($request));
        $this->assertFalse($gate->effectiveForRequest($request, 'module_tasks'));
    }

    public function test_anonymous_request_uses_header_resolved_campus(): void
    {
        $this->globalFlag('module_mall');
        $campus = Campus::query()->create(['code' => 'ctx-anon', 'name' => '匿名头校', 'status' => 'active']);
        $this->override($campus, 'module_mall', 'disabled');

        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('X-Device-Id', 'device-anon');
        $request->headers->set('X-Client-Platform', 'wechat-mini');
        $request->attributes->set('campus_database_id', $campus->id);

        $this->assertFalse(app(ModuleGateService::class)->effectiveForRequest($request, 'module_mall'));
    }
}
