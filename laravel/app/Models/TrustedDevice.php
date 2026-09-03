<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser the user explicitly opted to skip the TOTP challenge on, for a bounded window.
 *
 * NEVER a substitute for the password: AuthController::login verifies the password first and only
 * then consults a device row, and only for the user whose password just succeeded. A stolen cookie
 * on its own authenticates nobody.
 *
 * Split selector/validator (not one opaque token): the selector is the indexed lookup key so the
 * check is a single indexed query, and the validator is compared with hash_equals against its
 * SHA-256 — constant time, and nothing usable is stored at rest.
 */
class TrustedDevice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rows that may still be honoured: not revoked and not past their fixed window. */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * Mint a device row and return the RAW "selector:validator" cookie value.
     *
     * The raw validator is returned to the caller and never stored or logged — only its SHA-256
     * lands in the database, so a dump of `trusted_devices` cannot be replayed as a cookie.
     */
    public static function issue(User $user, string $userAgent, ?string $ip, int $hours): string
    {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));

        static::create([
            'user_id' => $user->id,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'label' => static::labelFor($userAgent),
            'ip' => $ip,
            'expires_at' => now()->addHours($hours),
        ]);

        return $selector.':'.$validator;
    }

    /**
     * Resolve a cookie value to a usable device row for THIS user, or null.
     *
     * Returns null — never throws — for an absent, malformed, unknown, expired, revoked, tampered
     * or foreign-account cookie. The user_id check is what makes a cookie non-transferable: it is
     * never an identity hint, only a waiver for the account whose password already succeeded.
     */
    public static function resolve(?string $cookie, User $user): ?self
    {
        if (! is_string($cookie) || ! str_contains($cookie, ':')) {
            return null;
        }
        [$selector, $validator] = explode(':', $cookie, 2);
        if ($selector === '' || $validator === '') {
            return null;
        }

        $row = static::query()->where('selector', $selector)->first();
        if (! $row || (int) $row->user_id !== (int) $user->id) {
            return null;
        }
        if ($row->revoked_at !== null || $row->expires_at === null || $row->expires_at->lessThanOrEqualTo(now())) {
            return null;
        }
        // constant-time: a plain === on the hash would leak the prefix length via timing
        if (! hash_equals((string) $row->validator_hash, hash('sha256', $validator))) {
            return null;
        }

        return $row;
    }

    /** Revoke every live device for a user (password change, reset, MFA reset/re-enrol, self-revoke). */
    public static function revokeAllFor(int $userId): void
    {
        static::query()->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /**
     * A short human label for the device list ("Chrome on Windows"). Best-effort only — the
     * User-Agent is attacker-controlled, so this is cosmetic and never used for any decision.
     */
    private static function labelFor(string $userAgent): string
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            return 'Unknown device';
        }

        $browser = null;
        // order matters: Edge/Opera/Chrome all carry "Chrome", and Chrome carries "Safari"
        foreach ([
            'Edg' => 'Edge', 'OPR' => 'Opera', 'SamsungBrowser' => 'Samsung Internet',
            'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari', 'MSIE' => 'Internet Explorer',
            'Trident' => 'Internet Explorer',
        ] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $browser = $name;
                break;
            }
        }

        $os = null;
        foreach ([
            'Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS',
            'Mac OS X' => 'macOS', 'Macintosh' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux',
        ] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $os = $name;
                break;
            }
        }

        return match (true) {
            $browser && $os => "{$browser} on {$os}",
            (bool) $browser => $browser,
            (bool) $os => $os,
            default => 'Unknown device',
        };
    }
}
