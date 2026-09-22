#!/usr/bin/env php
<?php

/**
 * Software Bill of Materials (CICD-05, prod-ready 2026-09-03).
 *
 * Emits ONE CycloneDX 1.5 JSON document covering both dependency trees from the lock files that
 * actually pin production — composer.lock and package-lock.json — so the SBOM describes the exact
 * set CI installed, not whatever a resolver would pick today.
 *
 * Deliberately dependency-free (no cyclonedx plugin, no network, no npm subcommand): a supply-chain
 * artifact that itself pulls a new supply chain is self-defeating, and this runs identically on the
 * Windows dev box and the Linux runner. Output is sorted by purl and carries no timestamp or serial
 * number, so the same lock files always produce a byte-identical document — a diff between two runs
 * means the dependencies moved, never that the generator did.
 *
 * NOT an attestation. Signed build provenance has nothing to attest in this pipeline: CI builds no
 * release artifact and no image (Coolify builds from source on the host), so there is no subject to
 * sign. That stays open in docs/CI.md until CI produces a release artifact.
 *
 * Usage: php scripts/sbom.php <output.json>
 * Exit:  0 written · 3 a lock file is missing or unreadable.
 */
$out = $argv[1] ?? null;
if ($out === null) {
    fwrite(STDERR, 'usage: sbom.php <output.json>'.PHP_EOL);
    exit(3);
}

$root = dirname(__DIR__);

function readLock(string $path): array
{
    if (! is_file($path)) {
        fwrite(STDERR, "sbom: lock file not found at {$path} — run composer install / npm ci first".PHP_EOL);
        exit(3);
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (! is_array($data)) {
        fwrite(STDERR, "sbom: {$path} is not valid JSON".PHP_EOL);
        exit(3);
    }

    return $data;
}

/** CycloneDX wants a licence id OR a free-text name; composer and npm both give a plain string. */
function licences(mixed $raw): array
{
    $list = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? [$raw] : []);
    $out = [];
    foreach ($list as $l) {
        if (is_string($l) && $l !== '') {
            $out[] = ['license' => ['id' => $l]];
        }
    }

    return $out;
}

$components = [];

// ---- PHP (composer.lock) ---------------------------------------------------------------------
$composer = readLock($root.'/composer.lock');
foreach ([['packages', 'required'], ['packages-dev', 'dev']] as [$key, $scope]) {
    foreach ($composer[$key] ?? [] as $pkg) {
        $name = (string) ($pkg['name'] ?? '');
        $version = (string) ($pkg['version'] ?? '');
        if ($name === '') {
            continue;
        }
        $components[] = [
            'type' => 'library',
            'name' => $name,
            'version' => $version,
            'purl' => 'pkg:composer/'.$name.($version !== '' ? '@'.$version : ''),
            'licenses' => licences($pkg['license'] ?? null),
            'properties' => [
                ['name' => 'ecosystem', 'value' => 'composer'],
                ['name' => 'scope', 'value' => $scope],
            ],
        ];
    }
}

// ---- JavaScript (package-lock.json v2/v3) ------------------------------------------------------
$npm = readLock($root.'/package-lock.json');
foreach ($npm['packages'] ?? [] as $path => $pkg) {
    if ($path === '') {
        continue;   // the root project itself, described in metadata.component below
    }
    $marker = 'node_modules/';
    $at = strrpos($path, $marker);
    $name = (string) ($pkg['name'] ?? ($at === false ? $path : substr($path, $at + strlen($marker))));
    $version = (string) ($pkg['version'] ?? '');
    if ($name === '') {
        continue;
    }
    // purl percent-encodes the @ of a scoped name, but never the one before the version
    $purlName = str_starts_with($name, '@') ? '%40'.substr($name, 1) : $name;
    $components[] = [
        'type' => 'library',
        'name' => $name,
        'version' => $version,
        'purl' => 'pkg:npm/'.$purlName.($version !== '' ? '@'.$version : ''),
        'licenses' => licences($pkg['license'] ?? null),
        'properties' => [
            ['name' => 'ecosystem', 'value' => 'npm'],
            ['name' => 'scope', 'value' => ($pkg['dev'] ?? false) ? 'dev' : 'required'],
        ],
    ];
}

// Deterministic order, and one entry per purl (a package can appear at several lock paths).
$byPurl = [];
foreach ($components as $c) {
    $byPurl[$c['purl']] = $c;
}
ksort($byPurl, SORT_STRING);
$components = array_values($byPurl);

$bom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'version' => 1,
    'metadata' => [
        'component' => [
            'type' => 'application',
            'name' => 'dmc-internal-medicine-patient-flow-hub',
            'description' => 'DMC Internal Medicine patient-flow hub (Laravel application)',
        ],
        'tools' => [['vendor' => 'DMC', 'name' => 'scripts/sbom.php', 'version' => '1.0.0']],
    ],
    'components' => $components,
];

$json = json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, 'sbom: could not encode the document'.PHP_EOL);
    exit(3);
}

$dir = dirname($out);
if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
    fwrite(STDERR, "sbom: could not create {$dir}".PHP_EOL);
    exit(3);
}
file_put_contents($out, $json.PHP_EOL);

$counts = ['composer' => 0, 'npm' => 0];
foreach ($components as $c) {
    foreach ($c['properties'] as $p) {
        if ($p['name'] === 'ecosystem') {
            $counts[$p['value']]++;
        }
    }
}
printf('sbom: %s — %d components (%d composer, %d npm), CycloneDX 1.5%s',
    $out, count($components), $counts['composer'], $counts['npm'], PHP_EOL);

exit(0);
