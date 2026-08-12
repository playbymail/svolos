<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
     * `?: null` rather than a bare `env()`: `.env.example` ships `MAILGUN_DOMAIN=` and
     * `MAILGUN_SECRET=` with no value, and a fresh clone copies that file to `.env`, so dotenv
     * hands back the empty string rather than null. Blank means unconfigured — coercing here keeps
     * "no credentials" a single value, and stops an empty domain from building a transport that
     * only fails later against Mailgun.
     */
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN') ?: null,
        'secret' => env('MAILGUN_SECRET') ?: null,
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

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

];
