<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 0;
    public const ROLE_REGISTRAR = 2;
    public const ROLE_CONSULTANT = 3;
    public const ROLE_RESIDENT = 4;
    public const ROLE_OBSERVER = 5;

    public const ROLE_LABELS = [
        0 => 'Administrator', 2 => 'Registrar', 3 => 'Consultant', 4 => 'Resident', 5 => 'Observer',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    /**
     * Stamp the password-set date on creation: NULL pass_exp_date means "password age unknown"
     * and the pwd middleware treats it as EXPIRED (legacy parity), so every app-created account
     * starts its 3-month clock today. The legacy importer writes users via DB::table (no model
     * events) and keeps NULL for unknown-age rows — those are forced to change on first login.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user) {
            if (! array_key_exists('pass_exp_date', $user->getAttributes())) {
                $user->pass_exp_date = now()->toDateString();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'array',
            'mfa_enrolled_at' => 'datetime',
            'pass_exp_date' => 'date',
            'active' => 'boolean',
            'on_service' => 'boolean',
            'can_assign' => 'boolean',
            'can_add' => 'boolean',
            'can_manage' => 'boolean',
            'can_modify' => 'boolean',
            'role' => 'integer',
        ];
    }

    public function isAdmin(): bool { return (int) $this->role === self::ROLE_ADMIN; }

    /** Observers (role 5) are READ-ONLY everywhere — capability flags never override this. */
    public function isObserver(): bool { return (int) $this->role === self::ROLE_OBSERVER; }
    public function roleLabel(): string { return self::ROLE_LABELS[$this->role] ?? 'User'; }
    public function mfaEnabled(): bool { return $this->mfa_secret !== null && $this->mfa_enrolled_at !== null; }

    /**
     * Consultant-picker options [{id, name}] — the one definition for every dropdown.
     * Registry passes $activeOnly=false: historical admissions reference inactive consultants.
     */
    public static function consultantOptions(bool $activeOnly = true)
    {
        return static::where('role', self::ROLE_CONSULTANT)
            ->when($activeOnly, fn ($q) => $q->where('active', 1))
            ->orderBy('full_name')->get(['id', 'full_name', 'name', 'specialty_id', 'on_service'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name ?: $u->name,
                'specialty_id' => $u->specialty_id, 'on_service' => (bool) $u->on_service]);
    }

    public function specialty() { return $this->belongsTo(Specialty::class); }
    public function admissions(): HasMany { return $this->hasMany(Admission::class, 'consultant_id'); }
    public function consultations(): HasMany { return $this->hasMany(Consultation::class, 'consultant_id'); }
}
