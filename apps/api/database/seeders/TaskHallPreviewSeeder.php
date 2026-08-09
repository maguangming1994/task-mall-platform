<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskRewardBudget;
use App\Modules\Task\Models\TaskVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaskHallPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Task hall preview data is restricted to local and testing environments.');

            return;
        }

        $user = User::query()->get()->first(fn (User $candidate): bool => is_string($candidate->phone) && $candidate->phone !== '');
        if ($user === null) {
            $this->command?->warn('No local user with a phone number is available for the withdrawal ticker preview.');

            return;
        }

        WalletWithdrawal::query()->updateOrCreate(
            ['withdrawal_no' => 'WDLOCALPREVIEW20260805'],
            [
                'user_id' => $user->id,
                'requested_amount_cents' => 5600,
                'fee_rate_ppm' => 6000,
                'fee_amount_cents' => 34,
                'received_amount_cents' => 5566,
                'channel' => 'wechat',
                'status' => 'completed',
                'idempotency_key_hash' => hash('sha256', 'local-withdrawal-ticker-preview'),
                'completed_at' => now(),
            ],
        );

        Task::query()->where('status', 'published')->get()->each(function (Task $task): void {
            DB::transaction(function () use ($task): void {
                $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
                $version = TaskVersion::query()->where('task_id', $lockedTask->id)->where('version_no', $lockedTask->published_version_no)->first();
                if ($version === null) {
                    return;
                }
                $content = $version->content;
                if (! isset($content['steps'])) {
                    $content['steps'] = $this->taskSteps((string) ($content['category'] ?? 'campaign'));
                    $content['attachment_rules'] = ['max_count' => 9, 'max_images' => 9, 'max_image_bytes' => 10485760];
                    $nextVersionNo = $lockedTask->current_version_no + 1;
                    $newVersion = TaskVersion::query()->create([
                        'task_id' => $lockedTask->id,
                        'version_no' => $nextVersionNo,
                        'status' => 'published',
                        'change_type' => 'minor',
                        'source_version_no' => $version->version_no,
                        'changed_fields' => ['steps', 'attachment_rules'],
                        'content' => $content,
                        'created_by_admin_id' => $version->created_by_admin_id,
                        'published_at' => now(),
                    ]);
                    TaskRewardBudget::query()->where('task_version_id', $version->id)->update(['task_version_id' => $newVersion->id]);
                    $version->update(['status' => 'superseded']);
                    $lockedTask->update([
                        'current_version_no' => $nextVersionNo,
                        'published_version_no' => $nextVersionNo,
                        'lock_version' => $lockedTask->lock_version + 1,
                    ]);
                }
                TaskClaim::query()->where('task_id', $lockedTask->id)->get()->each(function (TaskClaim $claim) use ($content): void {
                    $snapshot = $claim->task_snapshot;
                    $snapshot['steps'] = $content['steps'];
                    $snapshot['attachment_rules'] = $content['attachment_rules'];
                    $claim->update(['task_snapshot' => $snapshot]);
                });
            });
        });

        $this->command?->info('Local task hall withdrawal ticker and step-by-step task previews are ready.');
    }

    /** @return array<int,array<string,mixed>> */
    private function taskSteps(string $category): array
    {
        return match ($category) {
            'onboarding' => [
                ['id' => 'goals', 'title' => '填写本周目标', 'description' => '写下本周最想完成的三件事，并分别说明可验证的完成标准。', 'input_type' => 'text', 'required' => true],
                ['id' => 'plan', 'title' => '补充行动计划', 'description' => '说明你准备从哪一步开始，以及预计完成时间。', 'input_type' => 'text', 'required' => true],
            ],
            'daily' => [
                ['id' => 'focus-proof', 'title' => '上传专注记录', 'description' => '完成至少 25 分钟专注学习，上传包含时长的记录截图。', 'input_type' => 'image', 'required' => true],
                ['id' => 'learning-note', 'title' => '填写学习收获', 'description' => '用文字总结本次学习的重点和下一步行动。', 'input_type' => 'text', 'required' => true],
            ],
            'community' => [
                ['id' => 'publish-post', 'title' => '发布原创经验', 'description' => '在成长社区发布一条对其他用户有帮助的原创经验。', 'input_type' => 'none', 'required' => true],
                ['id' => 'post-proof', 'title' => '提交帖子凭证', 'description' => '填写帖子标题，并上传发布成功后的页面截图。', 'input_type' => 'text_image', 'required' => true],
            ],
            'commerce' => [
                ['id' => 'compare-prices', 'title' => '比较三种购买方式', 'description' => '选择同一 SKU，查看现金、积分和现金＋积分三种价格。', 'input_type' => 'none', 'required' => true],
                ['id' => 'price-proof', 'title' => '上传价格对比截图', 'description' => '上传能够同时说明三种购买方式的页面截图。', 'input_type' => 'image', 'required' => true],
                ['id' => 'experience-note', 'title' => '填写体验结论', 'description' => '说明你会选择哪种购买方式以及原因。', 'input_type' => 'text', 'required' => true],
            ],
            default => [['id' => 'result', 'title' => '提交任务结果', 'description' => '按照任务说明填写完成结果并上传必要凭证。', 'input_type' => 'text_image', 'required' => true]],
        };
    }
}
