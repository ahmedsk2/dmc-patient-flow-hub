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
