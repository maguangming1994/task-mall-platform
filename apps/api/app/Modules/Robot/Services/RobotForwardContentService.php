<?php

namespace App\Modules\Robot\Services;

use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskVersion;

/**
 * 把模板变量映射到社区帖子 / 任务的真实字段。
 *
 * 变量白名单固定，不执行表达式或脚本；未知变量原样保留。
 * ${lookNum}、${shareNum} 为历史模板兼容保留。
 */
class RobotForwardContentService
{
    public const TASK_CATEGORIES = [
        'onboarding' => '新手任务',
        'daily' => '日常任务',
        'community' => '社区任务',
        'commerce' => '商城任务',
        'research' => '调研任务',
        'campaign' => '活动任务',
    ];

    public function __construct(private readonly WechatMiniUrlLinkService $links) {}

    /** @return array<string, string> */
    public function variables(CommunityPost $post, int $index, int $total, int $contentLimit = 0): array
    {
        $author = User::query()->find($post->user_id);
        $campusName = (string) (Campus::query()->find($post->campus_id)?->name ?: '校园');
        $content = (string) $post->content;
        if ($contentLimit > 0) {
            $content = mb_substr($content, 0, $contentLimit);
        }

        return [
            '${index}' => (string) $index,
            '${title}' => (string) $post->title,
            '${content}' => $content,
            '${categoryName}' => CommunityPost::CATEGORIES[$post->category_code] ?? (string) $post->category_code,
            '${campus}' => $campusName,
            '${campusName}' => $campusName,
            '${nickname}' => $author?->nickname ?: '社区用户',
            '${mobile}' => $this->maskPhone($author?->phone),
            '${commentNum}' => (string) (int) $post->comment_count,
            '${favoriteNum}' => (string) (int) $post->favorite_count,
            '${likeNum}' => (string) (int) $post->like_count,
            '${reward}' => '',
            '${wappLink}' => $this->links->forCommunityPost($post),
            '${addTime}' => $post->published_at?->format('Y-m-d H:i:s') ?? '',
            '${total}' => (string) $total,
            '${lookNum}' => '0',
            '${shareNum}' => '0',
        ];
    }

    /** @return array<string, string> */
    public function taskVariables(Task $task, int $index, int $total, int $contentLimit = 0): array
    {
        $version = TaskVersion::query()
            ->where('task_id', $task->id)
            ->where('version_no', $task->published_version_no)
            ->first();
        $snapshot = is_array($version?->content) ? $version->content : [];
        $campusName = (string) (Campus::query()->find($task->campus_id)?->name ?: '校园');
        $content = (string) ($snapshot['description'] ?? '');
        if ($contentLimit > 0) {
            $content = mb_substr($content, 0, $contentLimit);
        }
        $categoryCode = (string) ($snapshot['category'] ?? '');

        return [
            '${index}' => (string) $index,
            '${title}' => (string) ($snapshot['title'] ?? '校园任务'),
            '${content}' => $content,
            '${categoryName}' => self::TASK_CATEGORIES[$categoryCode] ?? ($categoryCode !== '' ? $categoryCode : '任务'),
            '${campus}' => $campusName,
            '${campusName}' => $campusName,
            '${nickname}' => (string) ($snapshot['publisher_display_name'] ?? '校园任务官') ?: '校园任务官',
            '${mobile}' => '',
            '${commentNum}' => '0',
            '${favoriteNum}' => '0',
            '${likeNum}' => '0',
            '${reward}' => $this->taskRewardText($snapshot['rewards'] ?? null),
            '${wappLink}' => $this->links->forTask($task),
            '${addTime}' => $task->published_at?->format('Y-m-d H:i:s') ?? '',
            '${total}' => (string) $total,
            '${lookNum}' => (string) (int) $task->view_count,
            '${shareNum}' => '0',
        ];
    }

    /** 渲染主奖励文案，与小程序端 primaryRewardText 口径对齐。 */
    private function taskRewardText(mixed $rewards): string
    {
        $first = is_array($rewards) && isset($rewards[0]) && is_array($rewards[0]) ? $rewards[0] : null;
        if ($first === null) {
            return '任务奖励';
        }
        $type = (string) ($first['type'] ?? '');
        if ($type === 'points') {
            return '+'.(int) ($first['points'] ?? 0).' 积分';
        }
        if ($type === 'wallet_balance') {
            return '+¥'.number_format(((int) ($first['balance_amount'] ?? 0)) / 100, 2).' 余额';
        }

        return '优惠券奖励';
    }

    /** @param array<string, string> $variables */
    public function render(string $template, array $variables): string
    {
        return strtr($template, $variables);
    }

    /** 没有可匹配的真实内容时使用的示例数据。 @return array<string, string> */
    public function sampleVariables(int $contentLimit = 0, string $contentType = 'community_post'): array
    {
        $content = $contentType === 'task'
            ? '这是一条任务转发的测试消息，用于验证模板和机器人目标。'
            : '这是一条来自转发配置的测试消息，用于验证模板和机器人目标。';
        if ($contentLimit > 0) {
            $content = mb_substr($content, 0, $contentLimit);
        }

        if ($contentType === 'task') {
            return [
                '${index}' => '1',
                '${title}' => '任务转发配置测试',
                '${content}' => $content,
                '${categoryName}' => '日常任务',
                '${campus}' => '校园',
                '${campusName}' => '校园',
                '${nickname}' => '校园任务官',
                '${mobile}' => '',
                '${commentNum}' => '0',
                '${favoriteNum}' => '0',
                '${likeNum}' => '0',
                '${reward}' => '+¥5.00 余额',
                '${wappLink}' => rtrim((string) config('app.user_h5_url'), '/').'/#/pages/task-detail/index',
                '${addTime}' => now()->format('Y-m-d H:i:s'),
                '${total}' => '1',
                '${lookNum}' => '0',
                '${shareNum}' => '0',
            ];
        }

        return [
            '${index}' => '1',
            '${title}' => 'XP 转发配置测试',
            '${content}' => $content,
            '${categoryName}' => '经验分享',
            '${campus}' => '校园',
            '${campusName}' => '校园',
            '${nickname}' => '测试助手',
            '${mobile}' => '138****0000',
            '${commentNum}' => '0',
            '${favoriteNum}' => '0',
            '${likeNum}' => '0',
            '${reward}' => '',
            '${wappLink}' => rtrim((string) config('app.user_h5_url'), '/'),
            '${addTime}' => now()->format('Y-m-d H:i:s'),
            '${total}' => '1',
            '${lookNum}' => '0',
            '${shareNum}' => '0',
        ];
    }

    private function maskPhone(?string $phone): string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '';
        }

        return strlen($phone) >= 7
            ? substr($phone, 0, 3).'****'.substr($phone, -4)
            : str_repeat('*', strlen($phone));
    }
}
