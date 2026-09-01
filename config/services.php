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

    'bulksms' => [
        'base_url' => env('BULK_SMS_BASE_URL'),
        'api_token' => env('BULK_SMS_API_TOKEN'),
        'sender_id' => env('BULK_SMS_SENDER_ID'),
    ],

    'zoom' => [
        'sdk_key' => env('ZOOM_SDK_KEY'),
        'sdk_secret' => env('ZOOM_SDK_SECRET'),
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'payment_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
    ],

    'affiliate' => [
        'url' => env('AFFILIATE_API_URL', 'http://tutorialcenter-affiliate.test'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Assessment Email Toggle
    |--------------------------------------------------------------------------
    | Set ASSESSMENT_EMAIL_ENABLED=false in your .env to disable assessment
    | emails (publish/grade). Database notifications always remain on.
    */
    'assessments' => [
        'send_email' => env('ASSESSMENT_EMAIL_ENABLED', true),
    ],
];
