<?php

namespace Database\Seeders;

use App\Modules\Legal\Models\LegalDocumentVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LegalDocumentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $documents = [
            'user_agreement' => ['开发环境用户协议', '说明用户账号、任务、社区、商城和资产功能的开发测试规则。'],
            'privacy_policy' => ['开发环境隐私政策', '说明手机号、微信身份、设备与业务数据在开发环境中的测试处理方式。'],
        ];

        foreach ($documents as $type => [$title, $summary]) {
            $content = "# {$title}\n\n{$summary}\n\n> 本文本仅用于验证协议版本与同意流程，不是正式法律文本，不得发布到生产环境。";
            LegalDocumentVersion::query()->firstOrCreate(
                ['document_type' => $type, 'version' => 'dev-1.0'],
                [
                    'public_id' => (string) Str::ulid(),
                    'title' => $title.'（不可用于生产）',
                    'content_format' => 'markdown',
                    'content' => $content,
                    'content_hash' => hash('sha256', $content),
                    'consent_required' => true,
                    'withdrawal_allowed' => true,
                    'status' => 'published',
                    'effective_at' => now()->subMinute(),
                    'published_at' => now(),
                    'change_summary' => 'I1 本地协议流程初始化。',
                ],
            );
        }
    }
}
