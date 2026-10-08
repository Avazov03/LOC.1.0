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

    'telegram' => [
        // Public bot username, only used to build t.me invite links. Not a secret.
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        // Secrets: environment only, never in source, docs or Git.
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
        'timeout' => (int) env('TELEGRAM_HTTP_TIMEOUT', 10),
        // Minutes an unfinished dialog (join, check-in, change request) is kept.
        'conversation_ttl' => (int) env('TELEGRAM_CONVERSATION_TTL', 30),
        // true: record outgoing messages instead of calling the Bot API (local demos without a token).
        'fake' => (bool) env('TELEGRAM_FAKE', false),
    ],

];
