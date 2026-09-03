#!/usr/bin/env php
<?php

/**
 * PHP coverage floor (TST-02, prod-ready 2026-09-03).
 *
 * Reads the Clover XML that PHPUnit writes with `--coverage-clover` and fails when statement
 * coverage over the configured <source> (app/) is below the floor. Deliberately independent of
 * Collision's `php artisan test --coverage` renderer, which printed nothing on CI with pcov
 * under PHPUnit 12 — the clover file is produced by PHPUnit itself, so this cannot silently skip.
 *
 * Usage: php scripts/coverage-gate.php <clover.xml> <min-percent>
 * Exit:  0 at or above the floor · 1 below it · 3 when no usable report exists (treated as failure).
 */
[$file, $min] = [$argv[1] ?? null, $argv[2] ?? null];

if ($file === null || $min === null || ! is_numeric($min)) {
    fwrite(STDERR, "usage: coverage-gate.php <clover.xml> <min-percent>\n");
    exit(3);
}
if (! is_file($file)) {
    fwrite(STDERR, "coverage-gate: clover report not found at {$file} — did PHPUnit run with --coverage-clover and a coverage driver (pcov)?\n");
    exit(3);
}

$xml = @simplexml_load_file($file);
$metrics = $xml?->project?->metrics ?? null;
if ($metrics === null) {
    fwrite(STDERR, "coverage-gate: no <project><metrics> element in {$file}\n");
    exit(3);
}

$total = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
if ($total === 0) {
    fwrite(STDERR, "coverage-gate: zero statements counted — is pcov loaded and <source> configured in phpunit.xml?\n");
    exit(3);
}

$pct = $covered / $total * 100;
printf("PHP statement coverage: %.2f%% (%d/%d statements over app/) — floor %s%%\n", $pct, $covered, $total, $min);

if ($pct + 1e-9 < (float) $min) {
    fwrite(STDERR, "coverage-gate: below the floor. Raise coverage or, with a reason in the PR, lower the floor in .github/workflows/laravel-ci.yml.\n");
    exit(1);
}
exit(0);
