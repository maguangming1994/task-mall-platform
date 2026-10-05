<?php

return [
    'types' => [
        'user_agreement',
        'privacy_policy',
        'identity_service',
        'payment_service',
        'task_rules',
        'points_rules',
        'wallet_rules',
        'mall_after_sale',
        'community_rules',
        'cancellation_rules',
    ],
    'scopes' => [
        'account' => ['user_agreement', 'privacy_policy', 'identity_service'],
        'task' => ['user_agreement', 'privacy_policy', 'task_rules'],
        'community' => ['user_agreement', 'privacy_policy', 'community_rules'],
        'checkout' => ['user_agreement', 'privacy_policy', 'mall_after_sale', 'payment_service'],
        'wallet' => ['user_agreement', 'privacy_policy', 'wallet_rules', 'payment_service'],
        'account_cancellation' => ['user_agreement', 'privacy_policy', 'cancellation_rules'],
    ],
];
