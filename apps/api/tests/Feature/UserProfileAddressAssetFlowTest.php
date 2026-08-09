<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Upload\Models\UploadedFile;
use Database\Seeders\LegalDocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserProfileAddressAssetFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegalDocumentSeeder::class);
    }

    public function test_profile_completion_requires_an_owned_clean_avatar_and_creates_one_pending_milestone(): void
    {
        [$user, $token] = $this->loginAndConsent('13800138071');
        $avatar = $this->avatar($user->id, 'owned-avatar');
        $this->withToken($token)->putJson('/api/v1/me', ['nickname' => '新昵称', 'avatar_file_id' => $avatar->public_id])
            ->assertOk()->assertJsonPath('data.profile_completed', true)->assertJsonPath('data.avatar_file_id', $avatar->public_id);
        $this->assertDatabaseHas('user_milestones', ['user_id' => $user->id, 'milestone_code' => 'profile_completed', 'reward_status' => 'pending']);
        $this->withToken($token)->putJson('/api/v1/me', ['nickname' => '再次修改'])
            ->assertOk()->assertJsonPath('data.profile_completed', true);
        $this->assertDatabaseCount('user_milestones', 1);

        [$other] = $this->loginAndConsent('13800138072');
        $otherAvatar = $this->avatar($other->id, 'other-avatar');
        $this->withToken($token)->putJson('/api/v1/me', ['avatar_file_id' => $otherAvatar->public_id])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_address_book_is_idempotent_encrypted_owner_scoped_and_keeps_one_default(): void
    {
        [, $token] = $this->loginAndConsent('13800138073');
        [, $otherToken] = $this->loginAndConsent('13800138074');
        $first = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'address-create-key-0001'])->postJson('/api/v1/addresses', $this->address('张三', true))
            ->assertCreated()->assertJsonPath('data.is_default', true);
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'address-create-key-0001'])->postJson('/api/v1/addresses', $this->address('不会覆盖', false))
            ->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $second = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'address-create-key-0002'])->postJson('/api/v1/addresses', $this->address('李四', true))
            ->assertCreated()->assertJsonPath('data.is_default', true);
        $this->withToken($token)->getJson('/api/v1/addresses')->assertOk()->assertJsonPath('data.0.id', $second->json('data.id'));
        $this->assertNotSame('13800138000', DB::table('user_addresses')->where('public_id', $first->json('data.id'))->value('phone'));
        $this->withToken($otherToken)->deleteJson('/api/v1/addresses/'.$first->json('data.id'))->assertNotFound();
        $this->withToken($token)->deleteJson('/api/v1/addresses/'.$second->json('data.id'))->assertNoContent();
        $this->withToken($token)->getJson('/api/v1/addresses')->assertOk()->assertJsonPath('data.0.is_default', true);
    }

    public function test_asset_overview_is_initialized_and_wallet_balance_blocks_cancellation(): void
    {
        [$user, $token] = $this->loginAndConsent('13800138075');
        $this->withToken($token)->getJson('/api/v1/me/asset-overview')
            ->assertOk()->assertJsonPath('data.points.available', 0)->assertJsonPath('data.wallet.available_amount', 0);
        UserAssetAccount::query()->where('user_id', $user->id)->update(['wallet_available_cents' => 1234, 'points_available' => 50, 'coupon_available' => 2]);
        $this->withToken($token)->getJson('/api/v1/me/asset-overview')
            ->assertOk()->assertJsonPath('data.wallet.available_amount', 1234)->assertJsonPath('data.points.available', 50)->assertJsonPath('data.coupons.available', 2);
        $this->withToken($token)->getJson('/api/v1/me/account-cancellation/eligibility')
            ->assertOk()->assertJsonPath('data.eligible', false)->assertJsonFragment(['wallet_balance_not_zero']);
    }

    private function avatar(int $userId, string $key): UploadedFile
    {
        return UploadedFile::query()->create([
            'owner_user_id' => $userId, 'purpose' => 'avatar', 'original_name' => $key.'.png', 'extension' => 'png',
            'declared_mime' => 'image/png', 'detected_mime' => 'image/png', 'declared_size' => 8, 'actual_size' => 8,
            'sha256' => hash('sha256', $key), 'disk' => 's3', 'quarantine_path' => 'avatar/'.$key.'.png', 'status' => 'clean',
            'idempotency_key_hash' => hash('sha256', $key), 'ticket_expires_at' => now()->addHour(), 'uploaded_at' => now(), 'scanned_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function address(string $name, bool $default): array
    {
        return ['recipient_name' => $name, 'phone' => '13800138000', 'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail' => '科技园 1 号', 'postal_code' => '518000', 'is_default' => $default];
    }

    private function loginAndConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'account-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        $pending = $this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data');
        foreach ($pending as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'account-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', [
                'legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false,
            ])->assertCreated();
        }

        return [User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail(), $token];
    }
}
