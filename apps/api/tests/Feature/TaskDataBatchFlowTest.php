<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskDataBatch;
use App\Modules\Task\Models\TaskDataItem;
use App\Modules\Task\Services\TaskClaimService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TaskDataBatchFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_import_preflight_publish_snapshot_atomic_assignment_recovery_and_export(): void
    {
        $token = $this->adminToken('batch-flow-admin');
        $csv = "手机号,兑换码\n13800000001,CODE-001\n13800000002,CODE-002\n";
        $batchResponse = $this->withToken($token)->post('/api/v1/admin/task-data-batches', [
            'name' => '首发兑换码批次',
            'field_mapping' => json_encode(['phone' => '手机号', 'code' => '兑换码'], JSON_UNESCAPED_UNICODE),
            'file' => UploadedFile::fake()->createWithContent('codes.csv', $csv),
        ])->assertCreated()
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonPath('data.valid_count', 2)
            ->assertJsonPath('data.invalid_count', 0)
            ->assertJsonPath('data.preview.0.mapped_payload.code', 'CODE-001');
        $batchId = $batchResponse->json('data.id');

        $points = $this->pool($token, '批次积分池', 1000, 'batch-points-pool');
        $payload = $this->task($points['id'], 2);
        $payload['data_batch_id'] = $batchId;
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'batch-task-create-001'])
            ->postJson('/api/v1/admin/tasks', $payload)->assertCreated();
        $taskId = $created->json('data.id');
        $snapshotHash = $created->json('data.data_batch.snapshot_hash');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'batch-task-publish-01'])
            ->postJson("/api/v1/admin/tasks/{$taskId}/status", ['action' => 'publish', 'reason' => '批次预检完成，正式发布。'])
            ->assertOk()->assertJsonPath('data.status', 'published');

        $batch = TaskDataBatch::query()->where('public_id', $batchId)->firstOrFail();
        $this->assertSame('locked', $batch->status);
        $this->assertSame($snapshotHash, $batch->snapshot_hash);
        $this->assertNotNull($batch->locked_at);
        $this->assertSame(Task::query()->where('public_id', $taskId)->value('published_version_no'), $batch->version->version_no);

        $task = Task::query()->where('public_id', $taskId)->firstOrFail();
        $service = app(TaskClaimService::class);
        $firstUser = $this->eligibleUser();
        $firstClaim = $service->claim($firstUser, $task, 'batch-first-claim');
        $firstPresented = $service->presentClaim($firstClaim);
        $this->assertSame('CODE-001', $firstPresented['data_item']['payload']['code']);
        $this->assertDatabaseHas('task_data_items', ['allocated_task_claim_id' => $firstClaim->id, 'status' => 'allocated']);

        $service->cancel($firstUser, $firstClaim, '未开始任务，释放批次数据。', 'batch-first-cancel');
        $released = TaskDataItem::query()->where('row_no', 2)->firstOrFail();
        $this->assertSame('available', $released->status);
        $this->assertNull($released->allocated_task_claim_id);
        $this->assertSame(1, $released->release_count);
        $this->assertDatabaseHas('task_data_allocations', ['task_claim_id' => $firstClaim->id, 'status' => 'released']);

        $secondClaim = $service->claim($this->eligibleUser(), $task, 'batch-second-claim');
        $this->assertSame('CODE-001', $service->presentClaim($secondClaim)['data_item']['payload']['code']);
        $secondClaim->update(['completion_deadline_at' => now()->subMinute()]);
        $this->artisan('tasks:expire-claims')->assertSuccessful();
        $this->assertSame('expired', $secondClaim->fresh()->status);
        $this->assertDatabaseHas('task_data_items', ['row_no' => 2, 'status' => 'available', 'release_count' => 2]);
        $this->assertDatabaseHas('task_data_allocations', ['task_claim_id' => $secondClaim->id, 'status' => 'released']);

        $thirdClaim = $service->claim($this->eligibleUser(), $task, 'batch-third-claim');
        $this->assertSame('CODE-001', $service->presentClaim($thirdClaim)['data_item']['payload']['code']);
        $fourthClaim = $service->claim($this->eligibleUser(), $task, 'batch-fourth-claim');
        $this->assertSame('CODE-002', $service->presentClaim($fourthClaim)['data_item']['payload']['code']);
        $this->assertSame(0, $this->getJson("/api/v1/tasks/{$taskId}")->assertOk()->json('data.remaining_reward_slots'));

        $export = $this->withToken($token)->get("/api/v1/admin/task-data-batches/{$batchId}/export")->assertOk();
        $body = $export->streamedContent();
        $this->assertStringContainsString('CODE-001', $body);
        $this->assertStringContainsString($thirdClaim->public_id, $body);
        $this->assertStringContainsString('release_count', $body);
        $this->assertStringContainsString('allocation_history_count', $body);
    }

    public function test_invalid_rows_fail_preflight_and_cannot_be_bound_to_a_task(): void
    {
        $token = $this->adminToken('batch-invalid-admin');
        $batch = $this->withToken($token)->post('/api/v1/admin/task-data-batches', [
            'name' => '存在空值的批次',
            'field_mapping' => json_encode(['code' => '兑换码'], JSON_UNESCAPED_UNICODE),
            'file' => UploadedFile::fake()->createWithContent('invalid.csv', "兑换码\nCODE-001\n\nCODE-003\n"),
        ])->assertCreated();
        // Blank physical lines are skipped, so create an explicit empty mapped cell in a second column.
        $this->assertSame('ready', $batch->json('data.status'));

        $failed = $this->withToken($token)->post('/api/v1/admin/task-data-batches', [
            'name' => '预检失败批次',
            'field_mapping' => json_encode(['phone' => '手机号', 'code' => '兑换码'], JSON_UNESCAPED_UNICODE),
            'file' => UploadedFile::fake()->createWithContent('failed.csv', "手机号,兑换码\n13800000001,CODE-001\n13800000002,\n"),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'preflight_failed')
            ->assertJsonPath('data.invalid_count', 1)
            ->assertJsonPath('data.preview.1.validation_errors.0', 'code 不能为空');

        $points = $this->pool($token, '失败批次积分池', 1000, 'failed-batch-pool');
        $payload = $this->task($points['id'], 1);
        $payload['data_batch_id'] = $failed->json('data.id');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'failed-batch-task'])
            ->postJson('/api/v1/admin/tasks', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('tasks', 0);
    }

    /** @return array<string,mixed> */
    private function pool(string $token, string $name, int $amount, string $key): array
    {
        return $this->withToken($token)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/admin/reward-budget-pools', [
            'name' => $name, 'asset_type' => 'points', 'total_amount' => $amount, 'reason' => '初始化批次测试预算池。',
        ])->assertCreated()->json('data');
    }

    /** @return array<string,mixed> */
    private function task(string $poolId, int $quota): array
    {
        return [
            'visibility' => 'public', 'category' => 'campaign', 'task_type' => 'one_time',
            'participation_mode' => 'claim_and_submit', 'verification_mode' => 'manual',
            'title' => '批次数据任务', 'description' => '领取任务时原子分配一条兑换码数据。', 'tags' => ['批次'],
            'rewards' => [['type' => 'points', 'points' => 10]],
            'reward_budgets' => [['reward_sequence' => 1, 'budget_pool_id' => $poolId]],
            'reward_quota_total' => $quota, 'completion_window_hours' => 24, 'submission_limit' => 3,
            'review_deadline_hours' => 48, 'appeal_deadline_days' => 7, 'appeal_limit' => 1,
            'claim_starts_at' => now()->subMinute()->toIso8601String(), 'claim_ends_at' => now()->addDays(5)->toIso8601String(),
            'submit_ends_at' => now()->addDays(7)->toIso8601String(), 'rules' => [],
        ];
    }

    private function eligibleUser(): User
    {
        return User::factory()->create(['status' => 'active', 'phone_verified_at' => now()]);
    }

    private function adminToken(string $account): string
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');
    }
}
