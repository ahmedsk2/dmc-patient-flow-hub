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
 * docs/DEPLOY-LARAVEL.md §10 carries the migration path if that is ever wanted. Until then the
 * accepted risk is that someone reaches for NOW() in NEW code — which is what this guard catches.
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

// MySQL's clock functions. CURRENT_TIMESTAMP / CURRENT_DATE / CURRENT_TIME and LOCALTIME(STAMP)
// are matched WITHOUT the parentheses MySQL lets them omit, with LOCALTIMESTAMP ahead of
// LOCALTIME so the longer name wins the alternation. UNIX_TIMESTAMP matches only with EMPTY
// parentheses: given an argument it converts a column rather than reading the clock.
$clock = '/\b(?:NOW|CURDATE|CURTIME|SYSDATE|UTC_TIMESTAMP|UTC_DATE|UTC_TIME|LOCALTIMESTAMP|LOCALTIME)\s*\(|\bUNIX_TIMESTAMP\s*\(\s*\)|\b(?:CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|LOCALTIMESTAMP|LOCALTIME)\b/i';

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
            // preg_match_ALL, not preg_match: one literal can hold several clock calls, and an
            // allow-listed one must never launder the others. Each match is reported, and matched
            // against the allow-list, on its own.
            if (! preg_match_all($clock, $token[1], $ms, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($ms[0] as [$call, $offset]) {
                // The window an allow-list needle must appear in: a little context before the
                // matched call plus enough of the SQL after it to carry the interval. Anchored to
                // THIS match, so a needle belonging to another clock call in the same literal
                // cannot authorise this one.
                $from = max(0, $offset - 40);
                $hits[] = [
                    'file' => $path,
                    'line' => $token[2],
                    'fn' => strtoupper(rtrim(rtrim($call), '()')),   // NOW( -> NOW, UNIX_TIMESTAMP() -> UNIX_TIMESTAMP
                    'call' => trim($call),
                    'window' => trim(substr($token[1], $from, ($offset - $from) + strlen($call) + 120)),
                ];
            }
        }
    }
}

$blocked = [];
foreach ($hits as $hit) {
    $ok = false;
    foreach ($allow as &$entry) {
        if (str_ends_with($hit['file'], $entry['file']) && stripos($hit['window'], $entry['needle']) !== false) {
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
    fwrite(STDERR, "::error file={$hit['file']},line={$hit['line']}::clock-guard: raw MySQL {$hit['fn']} in \"{$hit['call']}\" (context: {$hit['window']}). "
        ."The DB session runs in the DB host's timezone and the app runs Asia/Riyadh, so this is only correct when the column on "
        .'the other side is DB-written (a ->useCurrent() default). If it is app-written, bind the value from PHP '
        .'(Carbon::today()/now()) as a query parameter instead. A DDL column default in a migration IS a DB-written value and is correct — allow-list it. If it IS correct, add it to '.basename($allowPath).' with a reason.
');
}

foreach ($allow as $entry) {
    if (! $entry['used']) {
        fwrite(STDOUT, "::warning::clock-guard: allow-list entry for {$entry['file']} (\"{$entry['needle']}\") matched nothing — "
            ."the code moved or was fixed; remove the entry.\n");
    }
}

// A guard that looked at nothing is worse than no guard: it would report success for ever after
// a renamed directory or a typo'd path. An empty scan is a configuration error, not a pass.
if ($scanned === 0) {
    fwrite(STDERR, 'clock-guard: scanned 0 PHP files under '.implode(', ', $roots).' — wrong path?
');
    exit(3);
}

printf("clock-guard: %d PHP file(s) scanned, %d raw-clock call(s), %d allow-listed, %d blocked\n",
    $scanned, count($hits), count($hits) - count($blocked), count($blocked));

exit($blocked === [] ? 0 : 1);
