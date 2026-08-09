<?php

return [
    'types' => [
        'user_agreement',
        'privacy_policy',
        'task_rules',
        'points_rules',
        'wallet_rules',
        'mall_after_sale',
        'community_rules',
        'cancellation_rules',
    ],
    'scopes' => [
        'account' => ['user_agreement', 'privacy_policy'],
        'task' => ['user_agreement', 'privacy_policy', 'task_rules'],
        'community' => ['user_agreement', 'privacy_policy', 'community_rules'],
        'checkout' => ['user_agreement', 'privacy_policy', 'mall_after_sale'],
        'wallet' => ['user_agreement', 'privacy_policy', 'wallet_rules'],
        'account_cancellation' => ['user_agreement', 'privacy_policy', 'cancellation_rules'],
    ],
];
