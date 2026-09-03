<?php

declare(strict_types=1);

/**
 * composer-audit-gate.php — the blocking half of `composer audit` in CI.
 *
 * `composer audit` exits non-zero whenever ANY advisory exists, medium and low included, and its
 * own severity handling is a flat --ignore-severity switch. The pipeline used to answer that with
 * `|| true`, which swallowed every advisory — including the high ones. This script replaces that:
 *
 *   composer audit --format=json --locked > composer-audit.json   (exit code deliberately ignored)
 *   php scripts/composer-audit-gate.php composer-audit.json .composer-audit-ignore.json
 *
 * Verdict:
 *   - high / critical advisory NOT in the ignore file ............ BLOCK (exit 1)
 *   - advisory with NO severity from the advisory source ......... BLOCK (exit 1) — unknown is not
 *                                                                    "low"; review it, then ignore it
 *                                                                    explicitly if it is unreachable
 *   - medium / low advisory ....................................... warn only (::warning:: annotation)
 *   - advisory matched by an ignore entry ......................... notice, with the recorded reason
 *   - ignore entry whose `review_by` date has passed .............. the entry is EXPIRED and no longer
 *                                                                    suppresses anything — re-review it
 *   - abandoned package ........................................... warn only
 *   - report missing / not JSON / no `advisories` key ............. exit 2 (composer itself failed —
 *                                                                    never a pass)
 *
 * Ignore file format (see docs/CI.md):
 *   {"ignore": [{"package": "vendor/name", "id": "CVE-… | PKSA-… | GHSA-…",
 *                "reason": "why it is unreachable here", "reviewed_at": "YYYY-MM-DD",
 *                "review_by": "YYYY-MM-DD" (optional)}]}
 *
 * Every entry MUST carry package, id, reason and reviewed_at — a malformed entry is exit 2, so an
 * allow-list cannot be widened by a half-written line.
 */

const EXIT_PASS = 0;
const EXIT_BLOCK = 1;
const EXIT_USAGE = 2;

$reportPath = $argv[1] ?? null;
$ignorePath = $argv[2] ?? dirname(__DIR__).'/.composer-audit-ignore.json';

if ($reportPath === null) {
    fwrite(STDERR, "usage: php scripts/composer-audit-gate.php <composer-audit.json> [ignore-file.json]\n");
    exit(EXIT_USAGE);
}

$annotate = static function (string $level, string $message): void {
    // GitHub Actions workflow commands; harmless plain text anywhere else.
    echo "::{$level}::".str_replace(["\r", "\n"], ' ', $message)."\n";
};

// ---- the audit report --------------------------------------------------------------------------
$raw = is_readable($reportPath) ? (string) file_get_contents($reportPath) : '';
$start = strpos($raw, '{');
$report = $start === false ? null : json_decode(substr($raw, $start), true);
if (! is_array($report) || ! array_key_exists('advisories', $report)) {
    $annotate('error', "composer audit did not produce a JSON report at {$reportPath} — composer itself failed; this is not a pass.");
    exit(EXIT_USAGE);
}

// ---- the ignore list ---------------------------------------------------------------------------
$ignore = [];
if (is_file($ignorePath)) {
    $ignoreDoc = json_decode((string) file_get_contents($ignorePath), true);
    if (! is_array($ignoreDoc) || ! isset($ignoreDoc['ignore']) || ! is_array($ignoreDoc['ignore'])) {
        $annotate('error', "{$ignorePath} is not a JSON object with an `ignore` array.");
        exit(EXIT_USAGE);
    }
    foreach ($ignoreDoc['ignore'] as $i => $entry) {
        foreach (['package', 'id', 'reason', 'reviewed_at'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                $annotate('error', "{$ignorePath}: ignore entry #{$i} is missing `{$field}` — every ignore needs package, id, reason and reviewed_at.");
                exit(EXIT_USAGE);
            }
        }
        foreach (['reviewed_at', 'review_by'] as $dateField) {
            if (isset($entry[$dateField]) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $entry[$dateField])) {
                $annotate('error', "{$ignorePath}: ignore entry #{$i} `{$dateField}` must be YYYY-MM-DD.");
                exit(EXIT_USAGE);
            }
        }
        $ignore[] = $entry;
    }
}

$today = date('Y-m-d');
$findIgnore = static function (string $package, array $advisory) use ($ignore): ?array {
    $ids = array_filter([
        $advisory['cve'] ?? null,
        $advisory['advisoryId'] ?? null,
    ]);
    foreach ($advisory['sources'] ?? [] as $source) {
        if (! empty($source['remoteId'])) {
            $ids[] = $source['remoteId'];
        }
    }
    $ids = array_map('strtoupper', array_map('strval', $ids));
    foreach ($ignore as $entry) {
        if (strtolower($entry['package']) === strtolower($package) && in_array(strtoupper($entry['id']), $ids, true)) {
            return $entry;
        }
    }

    return null;
};

// ---- evaluate ----------------------------------------------------------------------------------
$blocking = [];
$warnings = [];
$ignored = [];
$expired = [];

foreach ($report['advisories'] as $package => $advisories) {
    foreach ($advisories as $advisory) {
        $severity = strtolower((string) ($advisory['severity'] ?? ''));
        $id = (string) ($advisory['cve'] ?? $advisory['advisoryId'] ?? '?');
        $title = (string) ($advisory['title'] ?? '');
        $label = "{$package} {$id} [".($severity === '' ? 'UNKNOWN severity' : $severity)."] {$title}";

        $entry = $findIgnore((string) $package, $advisory);
        if ($entry !== null && ! empty($entry['review_by']) && $entry['review_by'] < $today) {
            $expired[] = "{$label} — ignore entry expired on {$entry['review_by']} (reviewed {$entry['reviewed_at']}); re-review and update the entry";
            $entry = null;
        }

        if ($entry !== null) {
            $ignored[] = "{$label} — ignored: {$entry['reason']} (reviewed {$entry['reviewed_at']}".(empty($entry['review_by']) ? '' : ", review by {$entry['review_by']}").')';
            continue;
        }

        if ($severity === 'high' || $severity === 'critical' || $severity === '') {
            $blocking[] = $label;
        } else {
            $warnings[] = $label;
        }
    }
}

$abandoned = [];
foreach ($report['abandoned'] ?? [] as $package => $replacement) {
    $abandoned[] = "{$package} is abandoned".(is_string($replacement) && $replacement !== '' ? " — use {$replacement} instead" : '');
}

// ---- report ------------------------------------------------------------------------------------
foreach ($ignored as $line) {
    $annotate('notice', $line);
}
foreach ($warnings as $line) {
    $annotate('warning', $line);
}
foreach ($abandoned as $line) {
    $annotate('warning', $line);
}
foreach ($expired as $line) {
    $annotate('error', $line);
}
foreach ($blocking as $line) {
    $annotate('error', 'BLOCKING: '.$line);
}

$summary = sprintf(
    "composer audit gate: %d blocking, %d expired ignore(s), %d warning(s) (medium/low), %d ignored, %d abandoned",
    count($blocking), count($expired), count($warnings), count($ignored), count($abandoned)
);
echo $summary."\n";

if (($stepSummary = getenv('GITHUB_STEP_SUMMARY')) !== false && $stepSummary !== '') {
    $md = "### Composer audit gate\n\n{$summary}\n\n";
    foreach ([['Blocking (high/critical/unknown, not ignored)', array_merge($blocking, $expired)], ['Warnings (medium/low)', $warnings], ['Ignored (reviewed)', $ignored], ['Abandoned', $abandoned]] as [$heading, $lines]) {
        if ($lines !== []) {
            $md .= "**{$heading}**\n\n".implode('', array_map(static fn ($l) => "- {$l}\n", $lines))."\n";
        }
    }
    file_put_contents($stepSummary, $md, FILE_APPEND);
}

if ($blocking !== [] || $expired !== []) {
    echo "FAILED — fix by upgrading the package, or (only for a verified-unreachable advisory) add an entry to .composer-audit-ignore.json with a reason and review date. See docs/CI.md.\n";
    exit(EXIT_BLOCK);
}

echo "PASSED\n";
exit(EXIT_PASS);
