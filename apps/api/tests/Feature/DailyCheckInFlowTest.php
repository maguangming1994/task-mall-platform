<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyCheckInFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegalDocumentSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
    }

    public function test_check_in_is_idempotent_awards_configured_points_and_tracks_streak(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 09:30:00');
        try {
            [$user, $token] = $this->loginAndConsent('13800138131');

            $this->withToken($token)->getJson('/api/v1/growth/check-in')
                ->assertOk()
                ->assertJsonPath('data.checked_in_today', false)
                ->assertJsonPath('data.today_reward_points', 5)
                ->assertJsonPath('data.workflow.can_check_in', true);

            $this->withToken($token)->postJson('/api/v1/growth/check-in')
                ->assertCreated()
                ->assertJsonPath('data.checked_in_today', true)
                ->assertJsonPath('data.current_streak', 1)
                ->assertJsonPath('data.workflow.status_label', '今日已签到');
            $this->withToken($token)->postJson('/api/v1/growth/check-in')->assertCreated();

            $this->assertDatabaseCount('daily_check_ins', 1);
            $this->assertDatabaseHas('point_batches', ['user_id' => $user->id, 'source_code' => 'daily_check_in', 'issued_points' => 5, 'remaining_points' => 5]);
            $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'points_available' => 5]);

            CarbonImmutable::setTestNow('2026-08-14 08:00:00');
            [, $nextDayToken] = $this->loginAndConsent('13800138131');
            $this->withToken($nextDayToken)->postJson('/api/v1/growth/check-in')
                ->assertCreated()
                ->assertJsonPath('data.current_streak', 2);
            $this->assertDatabaseHas('user_asset_accounts', ['user_id' => $user->id, 'points_available' => 10]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function loginAndConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'check-in-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'check-in-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', [
                'legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false,
            ])->assertCreated();
        }

        return [User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail(), $token];
    }
}
