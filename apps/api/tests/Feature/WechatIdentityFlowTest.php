<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Services\IdentityHasher;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WechatIdentityFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mini_and_official_account_union_id_resolve_to_one_user(): void
    {
        $mini = $this->wechatLogin('mini', 'local:mini-open-1:union-shared-1')
            ->assertOk()
            ->assertJsonPath('data.user.phone_bound', false);
        $official = $this->wechatLogin('h5', 'local:oa-open-1:union-shared-1')
            ->assertOk();

        $this->assertSame($mini->json('data.user.id'), $official->json('data.user.id'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_identities', 2);

        $stored = DB::table('user_identities')->where('provider', 'wechat_mini')->first();
        $this->assertNotSame('mini-open-1', $stored->provider_user_id);
        $this->assertNotSame('mini-open-1', $stored->provider_user_value);
        $this->assertNotSame('union-shared-1', $stored->union_id);
        $this->assertNotSame('union-shared-1', $stored->union_value);
    }

    public function test_wechat_user_can_bind_a_verified_phone(): void
    {
        $login = $this->wechatLogin('mini', 'local:mini-open-bind:union-bind');
        $token = $login->json('data.access_token');
        $headers = $this->headers('wechat-mini', 'mini-bind-device');

        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138880',
            'purpose' => 'bind_phone',
        ], $headers)->assertAccepted();

        $this->withToken($token)
            ->putJson('/api/v1/me/phone', [
                'new_phone' => '13800138880',
                'new_phone_verification_code' => '246810',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone_bound', true)
            ->assertJsonPath('data.phone_masked', '138****8880');

        $this->assertDatabaseHas('user_identities', ['provider' => 'phone']);
    }

    public function test_union_collision_creates_a_pending_conflict_without_silent_merge(): void
    {
        $hasher = app(IdentityHasher::class);
        $first = User::factory()->create();
        $second = User::factory()->create();
        foreach ([[$first, 'wechat_mini', 'open-first'], [$second, 'wechat_oa', 'open-second']] as [$user, $provider, $openid]) {
            UserIdentity::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $hasher->hash($openid),
                'provider_user_value' => $openid,
                'union_id' => $hasher->hash('union-collision'),
                'union_value' => 'union-collision',
                'metadata' => [],
            ]);
        }

        $this->wechatLogin('mini', 'local:open-third:union-collision')
            ->assertConflict()
            ->assertJsonPath('code', 'IDENTITY_MERGE_REQUIRED')
            ->assertJsonStructure(['details' => ['conflict_id']]);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('identity_conflicts', ['kind' => 'union_collision', 'status' => 'pending']);
    }

    public function test_phone_conflict_can_be_resolved_by_super_admin_and_revokes_source_session(): void
    {
        $phoneHeaders = $this->headers('h5-browser', 'existing-phone-device');
        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138881',
            'purpose' => 'login',
        ], $phoneHeaders)->assertAccepted();
        $phoneUser = $this->postJson('/api/v1/auth/phone/login', [
            'phone' => '13800138881',
            'verification_code' => '246810',
        ], $phoneHeaders)->assertOk();

        $wechat = $this->wechatLogin('mini', 'local:merge-open:merge-union')->assertOk();
        $wechatToken = $wechat->json('data.access_token');
        $wechatHeaders = $this->headers('wechat-mini', 'mini-merge-device');
        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138881',
            'purpose' => 'bind_phone',
        ], $wechatHeaders)->assertAccepted();
        $binding = $this->withToken($wechatToken)->putJson('/api/v1/me/phone', [
            'new_phone' => '13800138881',
            'new_phone_verification_code' => '246810',
        ])->assertConflict();

        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => 'identity-admin',
            'display_name' => '身份管理员',
            'password' => 'IdentityAdmin123!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $adminToken = $this->postJson('/api/v1/admin/auth/login', [
            'account' => 'identity-admin',
            'password' => 'IdentityAdmin123!',
        ])->assertOk()->json('data.access_token');

        $conflictId = $binding->json('details.conflict_id');
        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/identity-conflicts/{$conflictId}/resolve", [
                'resolution' => 'attach_to_target',
                'reason' => '用户完成身份核验，确认归并至已有手机号账号。',
                'target_user_id' => $phoneUser->json('data.user.id'),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->withToken($wechatToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
        $this->assertSame(
            $phoneUser->json('data.user.id'),
            UserIdentity::query()->where('provider', 'wechat_mini')->firstOrFail()->user->public_id,
        );
        $this->assertDatabaseHas('identity_conflicts', ['status' => 'resolved']);
        $this->assertDatabaseHas('operation_logs', ['action' => 'identity_conflict.resolve']);
    }

    public function test_capabilities_only_advertise_wechat_for_the_matching_runtime(): void
    {
        $this->getJson('/api/v1/client-capabilities', $this->headers('wechat-mini', 'cap-mini'))
            ->assertOk()
            ->assertJsonPath('data.login_methods.0', 'wechat_mini');
        $this->getJson('/api/v1/client-capabilities', $this->headers('h5-wechat', 'cap-h5'))
            ->assertOk()
            ->assertJsonPath('data.login_methods.0', 'wechat_oa');
        $this->getJson('/api/v1/client-capabilities', $this->headers('h5-browser', 'cap-browser'))
            ->assertOk()
            ->assertJsonMissing(['wechat_mini'])
            ->assertJsonMissing(['wechat_oa']);
    }

    private function wechatLogin(string $channel, string $code)
    {
        $mini = $channel === 'mini';

        return $this->postJson(
            $mini ? '/api/v1/auth/wechat-mini/login' : '/api/v1/auth/wechat-h5/login',
            ['code' => $code],
            $this->headers($mini ? 'wechat-mini' : 'h5-wechat', $mini ? 'mini-test-device' : 'h5-test-device'),
        );
    }

    /** @return array<string, string> */
    private function headers(string $platform, string $device): array
    {
        return ['X-Client-Platform' => $platform, 'X-Device-Id' => $device];
    }
}
