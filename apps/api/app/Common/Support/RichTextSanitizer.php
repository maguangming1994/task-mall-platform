<?php

namespace App\Common\Support;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\File;

class RichTextSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $cachePath = storage_path('app/htmlpurifier');
        File::ensureDirectoryExists($cachePath);

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('HTML.Allowed', implode(',', [
            'p[style]', 'br', 'h2[style]', 'h3[style]', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
            'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr',
            'a[href|target|rel]', 'img[src|alt|title|width|height]',
            'table', 'thead', 'tbody', 'tr', 'th[colspan|rowspan]', 'td[colspan|rowspan]',
        ]));
        $config->set('CSS.AllowedProperties', ['text-align']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.Nofollow', true);
        $config->set('HTML.TargetBlank', true);

        $this->purifier = new HTMLPurifier($config);
    }

    public function sanitize(string $html): string
    {
        return trim($this->purifier->purify($html));
    }

    public function toPlainText(string $html): string
    {
        $withBreaks = preg_replace('/<(br|\/p|\/li|\/h2|\/h3|\/blockquote|\/tr)>/iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    public function normalizeContent(array $content): array
    {
        $content['description'] = $this->sanitize((string) ($content['description'] ?? ''));
        $content['description_text'] = $this->toPlainText($content['description']);
        $content['description_format'] = 'rich_text_html';
        $content['description_schema_version'] = 1;

        return $content;
    }
}
