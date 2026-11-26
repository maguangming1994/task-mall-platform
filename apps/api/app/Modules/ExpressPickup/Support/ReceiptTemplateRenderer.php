<?php

namespace App\Modules\ExpressPickup\Support;

class ReceiptTemplateRenderer
{
    /**
     * 可插入的小票变量（后台变量面板与渲染共用一份口径）。
     *
     * @var array<string, string>
     */
    public const VARIABLES = [
        'order_no' => '订单号',
        'pickup_site' => '取件点',
        'pickup_code' => '取件码',
        'package_info' => '包裹描述',
        'package_count' => '包裹件数',
        'deliver_building' => '送达楼栋',
        'deliver_address' => '送达地址',
        'contact_name' => '联系人',
        'contact_phone' => '联系电话',
        'reward' => '跑腿费（元）',
        'expected_at' => '期望送达时间',
        'remark' => '备注',
        'created_at' => '下单时间',
        'campus_name' => '校区名称',
        'qrcode' => '订单二维码',
        'divider' => '分隔线',
        'blank' => '空行',
    ];

    /**
     * @param  array<string, string>  $variables
     */
    public function render(string $template, array $variables, int $paperWidth = 58): string
    {
        $width = $paperWidth === 80 ? 48 : 32;
        $variables = array_merge([
            'divider' => str_repeat('-', $width),
            'blank' => ' ',
        ], $variables);

        $content = (string) preg_replace_callback(
            '/\$\{([a-z0-9_]+)\}/i',
            static fn (array $matches): string => (string) ($variables[$matches[1]] ?? ''),
            $template,
        );

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_map(static fn (string $line): string => mb_strlen($line) > 200 ? mb_substr($line, 0, 200) : $line, $lines);

        return mb_substr(implode("\n", $lines), 0, 2000);
    }
}
