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

    // Task #230: off-box audit-log archive (S3-compatible, path-style; target is OCI Object
    // Storage but this is endpoint-agnostic — see App\Support\S3SigV4). Unset endpoint/keys means
    // audit:ship no-ops (warns and exits 0) rather than failing the schedule.
    'audit_archive' => [
        'endpoint' => env('AUDIT_S3_ENDPOINT'),
        'bucket' => env('AUDIT_S3_BUCKET'),
        'region' => env('AUDIT_S3_REGION', 'me-riyadh-1'),
        'access_key' => env('AUDIT_S3_ACCESS_KEY'),
        'secret' => env('AUDIT_S3_SECRET'),
    ],

];
