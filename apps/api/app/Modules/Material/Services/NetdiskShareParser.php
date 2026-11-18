<?php

namespace App\Modules\Material\Services;

/**
 * 网盘分享文案智能解析：从用户粘贴的整段文字中提取链接、提取码、名称与网盘类型。
 */
class NetdiskShareParser
{
    /** 支持的网盘域名 → 类型标识 */
    private const HOSTS = [
        'pan.baidu.com' => 'baidu',
        'eyun.baidu.com' => 'baidu',
        'pan.quark.cn' => 'quark',
        'aliyundrive.com' => 'aliyun',
        'alipan.com' => 'aliyun',
        'www.aliyundrive.com' => 'aliyun',
        'www.alipan.com' => 'aliyun',
        '115.com' => 'other',
        'cloud.189.cn' => 'other',
        'caiyun.139.com' => 'other',
        'pan.xunlei.com' => 'other',
        'www.123pan.com' => 'other',
    ];

    private const FILE_EXTENSIONS = [
        'pdf' => 'pdf',
        'doc' => 'word',
        'docx' => 'word',
        'xls' => 'excel',
        'xlsx' => 'excel',
        'csv' => 'excel',
        'ppt' => 'ppt',
        'pptx' => 'ppt',
        'mp4' => 'video',
        'mov' => 'video',
        'avi' => 'video',
        'zip' => 'archive',
        'rar' => 'archive',
        '7z' => 'archive',
    ];

    /**
     * @return array{share_url: ?string, extract_code: ?string, netdisk_type: string, title: string, file_type: string}
     */
    public function parse(string $text): array
    {
        $text = trim($text);
        $url = $this->extractUrl($text);
        $code = $this->extractCode($text, $url);
        $title = $this->extractTitle($text, $url, $code);

        return [
            'share_url' => $url,
            'extract_code' => $code,
            'netdisk_type' => $this->detectNetdiskType($url),
            'title' => $title,
            'file_type' => $this->detectFileType($text.' '.$title),
        ];
    }

    private function extractUrl(string $text): ?string
    {
        if (preg_match('#https?://(?:[a-z0-9-]+\.)*(?:pan\.baidu\.com|eyun\.baidu\.com|pan\.quark\.cn|aliyundrive\.com|alipan\.com|115\.com|cloud\.189\.cn|caiyun\.139\.com|pan\.xunlei\.com|123pan\.com)/[^\s，。、；;）)】\]"\'<>]*#iu', $text, $m)) {
            return rtrim($m[0], '，。、；;）)】]');
        }

        return null;
    }

    private function extractCode(string $text, ?string $url): ?string
    {
        if ($url && preg_match('/[?&](?:pwd|password|code)=([a-zA-Z0-9]{2,12})/i', $url, $m)) {
            return $m[1];
        }

        if (preg_match('/(?:提取码|提取碼|访问码|訪問碼|密码|密碼)\s*[:：]?\s*([a-zA-Z0-9]{2,12})/u', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractTitle(string $text, ?string $url, ?string $code): string
    {
        $cleaned = $text;
        if ($url) {
            $cleaned = str_replace($url, ' ', $cleaned);
        }
        $cleaned = preg_replace('/(?:提取码|提取碼|访问码|訪問碼|密码|密碼)\s*[:：]?\s*[a-zA-Z0-9]{2,12}/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/https?:\/\/\S+/iu', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/(?:复制这段内容|复制链接|点击链接|打开网盘|下载后查看|来自[:：]?)/u', ' ', $cleaned) ?? $cleaned;

        $lines = preg_split('/[\r\n]+/u', $cleaned) ?: [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            $line = trim($line, " \t\n\r\0\x0B-—_·•.,，。:：;；【】[]()（）");
            if (mb_strlen($line) >= 2) {
                return mb_substr($line, 0, 60);
            }
        }

        return $code ? '学习资料分享' : '学习资料分享';
    }

    private function detectNetdiskType(?string $url): string
    {
        if (! $url) {
            return 'other';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::HOSTS as $domain => $type) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $type;
            }
        }

        return 'other';
    }

    private function detectFileType(string $haystack): string
    {
        if (preg_match('/\.([a-z0-9]{2,5})(?:\b|$)/i', $haystack, $m)) {
            return self::FILE_EXTENSIONS[strtolower($m[1])] ?? 'other';
        }

        return 'other';
    }
}
