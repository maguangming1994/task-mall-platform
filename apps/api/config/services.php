<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'xp' => [
        'base_url' => env('XP_BASE_URL', 'https://xp.vvbot.cc'),
        'api_key' => env('XP_API_KEY'),
        'timeout_seconds' => (int) env('XP_TIMEOUT_SECONDS', 35),
    ],

    'green' => [
        'access_key_id' => env('GREEN_ACCESS_KEY_ID'),
        'access_key_secret' => env('GREEN_ACCESS_KEY_SECRET'),
    ],

    // 发帖机器人 AI 内容生成：任意 OpenAI 兼容接口
    'robot_ai' => [
        'base_url' => env('ROBOT_AI_BASE_URL', ''),
        'api_key' => env('ROBOT_AI_API_KEY', ''),
        'model' => env('ROBOT_AI_MODEL', ''),
    ],

];
