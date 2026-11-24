<?php

namespace App\Modules\Robot\Services;

use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;

/**
 * 把模板变量映射到社区帖子的真实字段。
 *
 * 变量白名单固定，不执行表达式或脚本；未知变量原样保留。
 * ${lookNum}、${shareNum} 为历史模板兼容保留，社区帖子没有对应统计，固定输出 0。
 */
class RobotForwardContentService
{
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
            '${wappLink}' => $this->links->forCommunityPost($post),
            '${addTime}' => $post->published_at?->format('Y-m-d H:i:s') ?? '',
            '${total}' => (string) $total,
            '${lookNum}' => '0',
            '${shareNum}' => '0',
        ];
    }

    /** @param array<string, string> $variables */
    public function render(string $template, array $variables): string
    {
        return strtr($template, $variables);
    }

    /** 没有可匹配的真实帖子时使用的示例数据。 @return array<string, string> */
    public function sampleVariables(int $contentLimit = 0): array
    {
        $content = '这是一条来自转发配置的测试消息，用于验证模板和机器人目标。';
        if ($contentLimit > 0) {
            $content = mb_substr($content, 0, $contentLimit);
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
