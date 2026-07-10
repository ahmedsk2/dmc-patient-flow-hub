<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per in-progress self-registration, keyed by session('reg.token'). Server-authoritative
 * and expirable — see the 2026-07-11 auth-hardening design. Never exposed to the client directly;
 * the controller reads it and returns only the specific fields each step needs.
 */
class PendingRegistration extends Model
{
    protected $fillable = [
        'token', 'email',
        'email_code_hash', 'email_code_expires_at', 'email_sent_at', 'email_send_count', 'email_attempts',
        'email_verified_at',
        'totp_secret', 'totp_recovery_codes', 'totp_confirmed_at',
        'expires_at',
    ];

    protected $hidden = ['email_code_hash', 'totp_secret', 'totp_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_code_expires_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'totp_secret' => 'encrypted',
            'totp_recovery_codes' => 'array',
            'totp_confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function emailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function totpConfirmed(): bool
    {
        return $this->totp_confirmed_at !== null;
    }
}
