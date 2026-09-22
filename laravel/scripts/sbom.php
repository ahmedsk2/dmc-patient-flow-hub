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
$commit = $argv[2] ?? (getenv('GITHUB_SHA') ?: null);
if ($out === null) {
    fwrite(STDERR, 'usage: sbom.php <output.json> [<commit-sha>]'.PHP_EOL);
    exit(3);
}

$root = dirname(__DIR__);

// Vendored SPDX identifier list (refresh from the URL in its _comment). Absent is not fatal:
// every licence then lands in `name`, which is always schema-valid - the SBOM loses precision,
// never validity.
$spdxPath = __DIR__.'/spdx-license-ids.json';
$spdxIds = [];
if (is_file($spdxPath)) {
    $spdxIds = json_decode((string) file_get_contents($spdxPath), true)['ids'] ?? [];
}
if ($spdxIds === []) {
    fwrite(STDERR, "sbom: no SPDX id list at {$spdxPath} - every licence will be emitted as a free-text name
");
}
$downgraded = [];

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

/**
 * CycloneDX 1.5 accepts three licence shapes and only one of them is enum-validated:
 *   {license:{id}}   - `id` is an enum of the SPDX identifiers, so an id outside it invalidates
 *                      the WHOLE document. Emitted only for ids in the vendored list.
 *   {license:{name}} - free text, always valid; the safe home for anything else.
 *   {expression}     - a one-element tuple, for a CHOICE of licences.
 *
 * Composer records a choice as an ARRAY (BSD-3-Clause, GPL-2.0-only, GPL-3.0-only means pick
 * ONE), which emitted as a list of ids would assert all three at once - the opposite of what the
 * package grants. npm writes the same thing as the string (MIT OR CC0-1.0). Both become an
 * expression. A bare SPDX identifier never contains a space or a parenthesis, which is the whole
 * test needed to tell the two apart.
 */
function licences(mixed $raw, array $spdxIds, array &$downgraded): array
{
    $list = [];
    foreach (is_array($raw) ? $raw : [$raw] as $l) {
        // npm's legacy object form: {type: MIT, url: ...}
        if (is_array($l) && isset($l['type']) && is_string($l['type'])) {
            $l = $l['type'];
        }
        if (is_string($l) && trim($l) !== '') {
            $list[] = trim($l);
        }
    }
    if ($list === []) {
        return [];
    }

    // more than one entry is a CHOICE, not a conjunction
    $value = count($list) > 1 ? '('.implode(' OR ', $list).')' : $list[0];

    if (strpbrk($value, ' ()') !== false) {
        return [['expression' => $value]];
    }
    if (in_array($value, $spdxIds, true)) {
        return [['license' => ['id' => $value]]];
    }

    // Not a known SPDX id (UNLICENSED, BSD*, a typo, or an id newer than the vendored list).
    // `name` is always schema-valid, so the document stays usable; the downgrade is reported so
    // the list can be refreshed rather than the SBOM silently losing precision.
    $downgraded[$value] = true;

    return [['license' => ['name' => $value]]];
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
            'licenses' => licences($pkg['license'] ?? null, $spdxIds, $downgraded),
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
        'licenses' => licences($pkg['license'] ?? null, $spdxIds, $downgraded),
        'properties' => [
            ['name' => 'ecosystem', 'value' => 'npm'],
            ['name' => 'scope', 'value' => ($pkg['dev'] ?? false) ? 'dev' : 'required'],
        ],
    ];
}

// Deterministic order, and one entry per purl (a package can appear at several lock paths).
// The merge is NOT last-wins: a package present as both a production and a dev dependency is a
// PRODUCTION dependency, and labelling it `dev` would hide it from exactly the audit this file
// exists for. Different versions keep different purls, so nothing real is collapsed away.
$scopeOf = function (array $c): string {
    foreach ($c['properties'] as $prop) {
        if ($prop['name'] === 'scope') {
            return $prop['value'];
        }
    }

    return 'dev';
};
$byPurl = [];
foreach ($components as $c) {
    $seen = $byPurl[$c['purl']] ?? null;
    if ($seen !== null && $scopeOf($seen) === 'required') {
        continue;   // already recorded as production; a dev occurrence cannot demote it
    }
    $byPurl[$c['purl']] = $c;
}
ksort($byPurl, SORT_STRING);
$components = array_values($byPurl);

$bom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'version' => 1,
    'metadata' => [
        // Which build this describes: argv[2] or GITHUB_SHA, omitted when neither is given so a
        // local run stays byte-identical between invocations. CI always sets it, which is the
        // property that matters: determinism per commit.
        'component' => array_filter([
            'type' => 'application',
            'name' => 'dmc-internal-medicine-patient-flow-hub',
            'version' => $commit,
            'description' => 'DMC Internal Medicine patient-flow hub (Laravel application)',
        ], fn ($v) => $v !== null),
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
if ($downgraded !== []) {
    fwrite(STDOUT, '::warning::sbom: not SPDX identifiers, emitted as free-text names: '
        .implode(', ', array_keys($downgraded)).'. Refresh scripts/spdx-license-ids.json if they are new ids.'.PHP_EOL);
}

printf('sbom: %s — %d components (%d composer, %d npm), CycloneDX 1.5%s',
    $out, count($components), $counts['composer'], $counts['npm'], PHP_EOL);

exit(0);
