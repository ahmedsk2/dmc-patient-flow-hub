<?php
/**
 * mfa.php — self-contained TOTP (RFC-6238 / RFC-4226) multi-factor auth helpers.
 *
 * No Composer / no external library (matches this app's design). Uses only ext-hash
 * (HMAC-SHA1), ext-openssl (AES-256-GCM at-rest encryption of the shared secret) and
 * ext-mbstring. Interoperable with Google Authenticator / Authy / Microsoft Authenticator
 * (6-digit, 30-second, SHA-1 TOTP — the de-facto standard).
 *
 * Correctness is verified against the published RFC-6238 test vectors by
 * tools/mfa_test.php — run it after ANY change here.
 *
 * Storage model (see migration 06-mfa.sql):
 *   members.mfa_secret         VARCHAR  — AES-256-GCM(base32 secret), base64; NULL = not enrolled
 *   members.mfa_recovery_codes TEXT     — JSON array of bcrypt hashes of single-use recovery codes
 *   members.mfa_enrolled_at    DATETIME — when enrollment completed
 *
 * A user "has MFA" iff mfa_secret IS NOT NULL. The pending (not-yet-confirmed) secret lives
 * only in the session during enrollment, so the DB never holds an unverified secret.
 */

require_once __DIR__ . '/config.php';

/* ------------------------------------------------------------------ Base32 (RFC 4648) */

const MFA_B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function mfa_base32_encode(string $bin): string
{
    if ($bin === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($bin) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= MFA_B32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out; // no '=' padding (authenticator apps don't need it)
}

function mfa_base32_decode(string $b32): string
{
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    if ($b32 === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($b32) as $ch) {
        $bits .= str_pad(decbin(strpos(MFA_B32_ALPHABET, $ch)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ HOTP / TOTP core */

/** RFC-4226 HOTP: raw key bytes + 64-bit counter -> zero-padded N-digit code. */
function mfa_hotp(string $keyBin, int $counter, int $digits = 6): string
{
    $hash = hash_hmac('sha1', pack('J', $counter), $keyBin, true); // J = 64-bit big-endian
    $offset = ord($hash[19]) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
    $code = $binary % (10 ** $digits);
    return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
}

/** RFC-6238 TOTP from a Base32 secret. $forTime defaults to now (pass a fixed time for tests). */
function mfa_totp(string $secretB32, ?int $forTime = null, int $digits = 6, int $step = 30): string
{
    $forTime = $forTime ?? time();
    return mfa_hotp(mfa_base32_decode($secretB32), intdiv($forTime, $step), $digits);
}

/**
 * Verify a user-supplied code against the secret, tolerating +/- $window time steps
 * (clock drift). Returns the matched absolute counter on success (so the caller can reject
 * replay of the same step), or false. Constant-time comparison.
 */
function mfa_totp_verify(string $secretB32, string $code, ?int $forTime = null, int $window = 1, int $digits = 6, int $step = 30)
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== $digits) {
        return false;
    }
    $keyBin = mfa_base32_decode($secretB32);
    if ($keyBin === '') {
        return false;
    }
    $base = intdiv($forTime ?? time(), $step);
    for ($i = -$window; $i <= $window; $i++) {
        $counter = $base + $i;
        if (hash_equals(mfa_hotp($keyBin, $counter, $digits), $code)) {
            return $counter;
        }
    }
    return false;
}

/** Cryptographically-random Base32 secret (default 20 bytes = 160 bits, RFC-recommended). */
function mfa_generate_secret(int $bytes = 20): string
{
    return mfa_base32_encode(random_bytes($bytes));
}

/** otpauth:// provisioning URI (what a QR code encodes). */
function mfa_otpauth_uri(string $secretB32, string $accountLabel, ?string $issuer = null): string
{
    $issuer = $issuer ?: (defined('MFA_ISSUER') ? MFA_ISSUER : 'DMC');
    $label  = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
    return 'otpauth://totp/' . $label . '?' . http_build_query([
        'secret' => $secretB32,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ]);
}

/* ------------------------------------------------------------------ at-rest encryption */

/** 32-byte AES key derived from the configured MFA_KEY (empty => MFA disabled). */
function mfa_key_available(): bool
{
    return defined('MFA_KEY') && MFA_KEY !== '';
}

function mfa_encrypt(string $plain): ?string
{
    if (!mfa_key_available()) {
        return null;
    }
    $key = hash('sha256', MFA_KEY, true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        return null;
    }
    return base64_encode($iv . $tag . $ct);
}

function mfa_decrypt(?string $stored): ?string
{
    if ($stored === null || $stored === '' || !mfa_key_available()) {
        return null;
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) { // 12 IV + 16 tag + >=1
        return null;
    }
    $key = hash('sha256', MFA_KEY, true);
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/* ------------------------------------------------------------------ recovery codes */

/** Generate N human-friendly single-use recovery codes (plaintext, shown to the user once). */
function mfa_generate_recovery_codes(int $n = 10): array
{
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $hex = bin2hex(random_bytes(5)); // 10 hex chars
        $codes[] = substr($hex, 0, 5) . '-' . substr($hex, 5, 5);
    }
    return $codes;
}

/** Hash recovery codes for storage (bcrypt, like passwords). Returns a JSON string. */
function mfa_hash_recovery_codes(array $plainCodes): string
{
    return json_encode(array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $plainCodes));
}

/**
 * Verify a recovery code against the stored JSON hashes. On success, removes the used hash
 * (single-use) by reference-updating $storedJson and returns true.
 */
function mfa_consume_recovery_code(string $code, ?string &$storedJson): bool
{
    $code = trim(strtolower($code));
    $hashes = $storedJson ? json_decode($storedJson, true) : [];
    if (!is_array($hashes)) {
        return false;
    }
    foreach ($hashes as $i => $h) {
        if (is_string($h) && password_verify($code, $h)) {
            unset($hashes[$i]);
            $storedJson = json_encode(array_values($hashes));
            return true;
        }
    }
    return false;
}
