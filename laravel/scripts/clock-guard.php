#!/usr/bin/env php
<?php

/**
 * Raw-clock guard (I18N-02, prod-ready 2026-09-03).
 *
 * THE HAZARD. `config/database.php` deliberately pins no MySQL session timezone, so the database
 * session runs in the DB host's zone (UTC in production) while the app runs Asia/Riyadh (+03:00).
 * That is safe on its own — but it makes the two clocks different clocks, and mixing them silently
 * skews every comparison by three hours:
 *
 *   SAFE    a DB-written column compared against the DB clock. `audit_log.created_at` is filled by
 *           the column default (`->useCurrent()`), so `created_at >= NOW() - INTERVAL 10 MINUTE`
 *           has the same clock on both sides. The three allow-listed sites are exactly this.
 *   SAFE    an app-written column compared against an app-bound value (`Carbon::today()`), which is
 *           how Dashboard / DataQuality / Registry were fixed. `AppClockDayBoundaryTest` pins it.
 *   BROKEN  an app-written column (admit_date, handovers.updated_at, assigned_at — Laravel writes
 *           these as Riyadh-local strings) compared against MySQL's NOW() / CURDATE(). The window
 *           is three hours wrong, all year, and nothing fails visibly.
 *
 * Pinning the session timezone is NOT the fix: the schema is full of TIMESTAMP columns, which MySQL
 * re-interprets through the session zone, so pinning would shift every stored value by three hours.
 * docs/BACKUP-AND-RESTORE.md and docs/DEPLOY-LARAVEL.md carry the migration path if that is ever
 * wanted. Until then the accepted risk is that someone reaches for NOW() in NEW code — which is
 * what this guard catches.
 *
 * WHAT IT DOES. Tokenises each PHP file and inspects STRING LITERALS only, so prose in comments
 * ("never use CURDATE() here") can never trip it and the PHP helper now() can never be mistaken for
 * SQL NOW(). Every literal containing a MySQL clock function must be listed in the allow-list with
 * a reason, or the run fails.
 *
 * Usage: php scripts/clock-guard.php <allowlist.json> <dir> [<dir>...]
 * Exit:  0 clean (or every hit allow-listed) · 1 an un-allow-listed hit · 3 bad usage / unreadable.
 */
$allowPath = $argv[1] ?? null;
$roots = array_slice($argv, 2);

if ($allowPath === null || $roots === []) {
    fwrite(STDERR, "usage: clock-guard.php <allowlist.json> <dir> [<dir>...]\n");
    exit(3);
}
if (! is_file($allowPath)) {
    fwrite(STDERR, "clock-guard: allow-list not found at {$allowPath}\n");
    exit(3);
}

$allowRaw = json_decode((string) file_get_contents($allowPath), true);
if (! is_array($allowRaw) || ! isset($allowRaw['allow']) || ! is_array($allowRaw['allow'])) {
    fwrite(STDERR, "clock-guard: {$allowPath} must be an object with an \"allow\" array\n");
    exit(3);
}

$allow = [];
foreach ($allowRaw['allow'] as $i => $entry) {
    foreach (['file', 'needle', 'reason'] as $required) {
        if (! isset($entry[$required]) || ! is_string($entry[$required]) || trim($entry[$required]) === '') {
            fwrite(STDERR, "clock-guard: allow[{$i}] is missing a non-empty \"{$required}\"\n");
            exit(3);
        }
    }
    $allow[] = ['file' => $entry['file'], 'needle' => $entry['needle'], 'reason' => $entry['reason'], 'used' => false];
}

// MySQL's clock functions. CURRENT_TIMESTAMP / CURRENT_DATE / CURRENT_TIME are matched without the
// parentheses they may legally omit.
$clock = '/\b(NOW|CURDATE|CURTIME|SYSDATE|UTC_TIMESTAMP|UTC_DATE|UTC_TIME|LOCALTIME|LOCALTIMESTAMP)\s*\(|\bCURRENT_(TIMESTAMP|DATE|TIME)\b/i';

$hits = [];
$scanned = 0;

foreach ($roots as $root) {
    if (! is_dir($root)) {
        fwrite(STDERR, "clock-guard: {$root} is not a directory\n");
        exit(3);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = strtr($file->getPathname(), DIRECTORY_SEPARATOR, '/');
        $src = (string) file_get_contents($path);
        $scanned++;

        foreach (token_get_all($src) as $token) {
            // Only string literals. Comments (T_COMMENT / T_DOC_COMMENT) and bare code never reach here.
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if (! preg_match($clock, $token[1], $m)) {
                continue;
            }
            $hits[] = ['file' => $path, 'line' => $token[2], 'fn' => strtoupper(rtrim($m[0], ' (')), 'text' => trim($token[1], "'\" \t\n\r")];
        }
    }
}

$blocked = [];
foreach ($hits as $hit) {
    $ok = false;
    foreach ($allow as &$entry) {
        if (str_ends_with($hit['file'], $entry['file']) && stripos($hit['text'], $entry['needle']) !== false) {
            $entry['used'] = true;
            $ok = true;
            break;
        }
    }
    unset($entry);
    if (! $ok) {
        $blocked[] = $hit;
    }
}

foreach ($blocked as $hit) {
    fwrite(STDERR, "::error file={$hit['file']},line={$hit['line']}::clock-guard: raw MySQL {$hit['fn']} in \"{$hit['text']}\". "
        ."The DB session runs in the DB host's timezone and the app runs Asia/Riyadh, so this is only correct when the column on "
        .'the other side is DB-written (a ->useCurrent() default). If it is app-written, bind the value from PHP '
        .'(Carbon::today()/now()) as a query parameter instead. If it IS correct, add it to '.basename($allowPath)." with a reason.\n");
}

foreach ($allow as $entry) {
    if (! $entry['used']) {
        fwrite(STDOUT, "::warning::clock-guard: allow-list entry for {$entry['file']} (\"{$entry['needle']}\") matched nothing — "
            ."the code moved or was fixed; remove the entry.\n");
    }
}

printf("clock-guard: %d PHP file(s) scanned, %d raw-clock literal(s), %d allow-listed, %d blocked\n",
    $scanned, count($hits), count($hits) - count($blocked), count($blocked));

exit($blocked === [] ? 0 : 1);
