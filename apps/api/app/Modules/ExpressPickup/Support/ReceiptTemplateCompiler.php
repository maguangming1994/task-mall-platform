<?php

namespace App\Modules\ExpressPickup\Support;

/**
 * 把小票装修组件编译成打印机可识别的文本标记。
 *
 * 仅使用现有驱动已验证的 <C>（居中）、<B>（加粗放大）、<QR>（二维码）标记，
 * 不引入新的打印能力，保证飞鹅驱动的输出与原来一致。
 */
class ReceiptTemplateCompiler
{
    public const BLOCK_TYPES = [
        'text' => '文本信息',
        'product_header' => '商品表头',
        'product_item' => '商品信息',
        'qr_code' => '二维码',
        'distribution_code' => '分销码',
        'divider' => '分隔线',
        'blank' => '空行',
    ];

    private const EXTRA_VARIABLES = [
        'product_name' => '商品名称',
        'quantity' => '商品数量',
        'amount' => '商品金额',
        'distribution_code' => '分销码',
    ];

    private const DEFAULT_CONTENT = [
        'product_header' => '商品名称        数量    金额',
        'product_item' => '${product_name}  ${quantity}  ${amount}',
        'distribution_code' => '推广码：${distribution_code}',
    ];

    /** @return array<string, string> */
    public function variables(): array
    {
        return [...ReceiptTemplateRenderer::VARIABLES, ...self::EXTRA_VARIABLES];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, string>  $variables
     */
    public function compile(array $template, array $variables = [], bool $plain = false): string
    {
        $width = ((int) ($template['paper_width'] ?? 58)) === 80 ? 48 : 32;
        $blocks = is_array($template['blocks'] ?? null) ? $template['blocks'] : [];
        $lines = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            if ($type === 'divider') {
                $lines[] = str_repeat('-', $width);
                continue;
            }
            if ($type === 'blank') {
                $lines[] = '';
                continue;
            }
            if ($type === 'qr_code') {
                $value = $this->substitute($this->content($props, '${order_no}'), $variables);
                $lines[] = $plain ? $value : '<C><QR>'.$this->stripTags($value).'</QR></C>';
                continue;
            }
            $content = $this->substitute($this->content($props, self::DEFAULT_CONTENT[$type] ?? ''), $variables);
            foreach (preg_split('/\r\n|\r|\n/', $content) ?: [''] as $line) {
                $lines[] = $this->formatLine($line, $props, $width, $plain);
            }
        }

        $lines = array_map(
            static fn (string $line): string => mb_strlen($line) > 200 ? mb_substr($line, 0, 200) : $line,
            $lines,
        );

        return mb_substr(implode("\n", $lines), 0, 2000);
    }

    /** @param  array<string, mixed>  $props */
    private function content(array $props, string $default): string
    {
        $content = $props['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            return $default;
        }

        return $content;
    }

    /** @param  array<string, mixed>  $props */
    private function formatLine(string $line, array $props, int $width, bool $plain): string
    {
        $align = in_array($props['align'] ?? 'left', ['left', 'center', 'right'], true) ? $props['align'] : 'left';
        $bold = (int) ($props['bold'] ?? 0);
        $size = (int) ($props['font_size'] ?? 0);
        $emphasize = $bold > 0 || $size > 0;

        if ($plain) {
            $padding = $align === 'center'
                ? max(0, intdiv(max(0, $width - mb_strlen($line)), 2))
                : max(0, $width - mb_strlen($line));

            return in_array($align, ['center', 'right'], true) ? str_repeat(' ', $padding).$line : $line;
        }

        $inner = $emphasize ? '<B>'.$line.'</B>' : $line;
        if ($align === 'center') {
            return '<C>'.$inner.'</C>';
        }
        if ($align === 'right') {
            return str_repeat(' ', max(0, $width - mb_strlen($line))).$inner;
        }

        return $inner;
    }

    /** @param  array<string, string>  $variables */
    private function substitute(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\$\{([a-z0-9_]+)\}/i',
            static fn (array $matches): string => (string) ($variables[$matches[1]] ?? ''),
            $text,
        );
    }

    private function stripTags(string $value): string
    {
        return str_replace(['<', '>'], '', $value);
    }
}
