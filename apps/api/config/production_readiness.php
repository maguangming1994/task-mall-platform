<?php

return [
    'operator_entity_name' => env('OPERATOR_ENTITY_NAME'),
    'customer_service_contact' => env('CUSTOMER_SERVICE_CONTACT'),
    'privacy_contact' => env('PRIVACY_CONTACT'),
    'legal_documents_approved' => filter_var(env('LEGAL_DOCUMENTS_APPROVED', false), FILTER_VALIDATE_BOOL),
    'authorized_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('WECHAT_AUTHORIZED_DOMAINS', ''))))),
];
