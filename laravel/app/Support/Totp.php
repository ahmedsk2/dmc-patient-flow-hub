<?php

namespace App\Support;

/**
 * Self-contained RFC 6238 TOTP (no external package). 6-digit codes, 30s period, SHA-1 — compatible
 * with Google Authenticator, Microsoft Authenticator, Authy, etc.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // RFC 4648 base32
    private const PERIOD = 30;
    private const DIGITS = 6;

    /** Generate a new base32 secret (default 160 bits = 32 chars). */
    public static function secret(int $bytes = 20): string
    {
        $random = random_bytes($bytes);
        $out = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($random) as $char) {
            $buffer = ($buffer << 8) | ord($char);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }
        return $out;
    }

    /** otpauth:// provisioning URI for QR codes. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /** Verify a user-entered code, tolerating +/- $window time-steps for clock drift. */
    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        return self::verifyWithCounter($secret, $code, $window, $timestamp) !== null;
    }

    /**
     * Verify and return the matched time-step counter (for replay protection), or null on failure.
     * Callers should reject a counter <= the last one they accepted for the user, then persist it.
     */
    public static function verifyWithCounter(string $secret, string $code, int $window = 1, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $timestamp ??= time();
        $counter = intdiv($timestamp, self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $counter + $i), $code)) {
                return $counter + $i;
            }
        }
        return null;
    }

    private static function code(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $buffer = 0;
        $bits = 0;
        $out = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $out;
    }

    /** Generate human-friendly one-time recovery codes (returned in plaintext, store hashed). */
    public static function recoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
        }
        return $codes;
    }
}
