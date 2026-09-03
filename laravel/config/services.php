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

    // DATA-02: the encrypted nightly DB backups (scripts/backup/db-backup.py, run by host cron)
    // land in a SEPARATE bucket on the same endpoint/region/credentials as the audit archive; its
    // lifecycle/retention is configured on the bucket itself. `backup:verify` (scheduled daily in
    // routes/console.php) only READS the heartbeat at {prefix}/LATEST.json from here — the app
    // never writes backups. See docs/BACKUP-AND-RESTORE.md.
    'db_backup' => [
        'bucket' => env('DB_BACKUP_S3_BUCKET', 'dmc-db-backups'),
        'prefix' => env('DB_BACKUP_S3_PREFIX', 'db-backups/dmc_demo'),
        // Point-in-time recovery (docs/BACKUP-AND-RESTORE.md §10). scripts/backup/binlog-ship.py
        // defaults its own prefix to <S3_PREFIX>/binlogs, so this default tracks it. The window is
        // hours, not the nightly 26: the shipper runs hourly, and a shipper that stopped is the
        // failure that matters. A missing heartbeat means "not installed" and never alerts.
        // Slashes are trimmed so a prefix written with a trailing "/" cannot produce a
        // "//binlogs/LATEST.json" key that reads as "not installed" forever; an empty override falls
        // back to the derived default for the same reason.
        'binlog_prefix' => trim((string) env('DB_BACKUP_BINLOG_S3_PREFIX', ''), '/')
            ?: trim((string) env('DB_BACKUP_S3_PREFIX', 'db-backups/dmc_demo'), '/').'/binlogs',
        'binlog_max_age_hours' => (int) env('DB_BACKUP_BINLOG_MAX_AGE_HOURS', 2),
    ],

];
