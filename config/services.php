<?php

declare(strict_types=1);

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

    // YouTube Data API v3 (videos.list, part=contentDetails): the length of a home video reel that
    // links to YouTube (FetchVideoLength). Empty → YouTube reels show only a typed length. A browser
    // or server API key from Google Cloud, restricted to the YouTube Data API.
    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

    // Next.js storefront cache revalidation (docs/api/revalidation.md). Either empty → the
    // RevalidateStorefrontTags job logs at debug level and sends nothing.
    'storefront' => [
        'url' => env('STOREFRONT_URL'),
        'revalidate_secret' => env('STOREFRONT_REVALIDATE_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
