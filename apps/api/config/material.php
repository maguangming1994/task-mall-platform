<?php

return [
    // 每位用户每日免费获取资料的次数（次日 0 点重置）
    'daily_limit' => (int) env('MATERIAL_DAILY_LIMIT', 1),

    // 用户投喂资料审核通过后赠送的长期获取次数（不每日重置）
    'bonus_per_approved' => (int) env('MATERIAL_BONUS_PER_APPROVED', 10),

    // 单次投喂文案长度上限
    'max_share_text_length' => 8000,
];
