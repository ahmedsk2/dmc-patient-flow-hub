<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Phase 4 — Item 1: soft-delete. ControlController::destroyUser now sets deleted_at instead of a
    // physical DELETE — so the nullOnDelete FKs (admissions.consultant_id/admitted_by, etc.) never
    // fire and historical per-consultant joins still resolve the name. The global scope excludes a
    // trashed user from auth (login lookups go through Eloquent) AND from User::consultantOptions().
    use SoftDeletes;

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
            'tour_completed_at' => 'datetime',
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

    /**
     * D1 (legacy endorsement scope [0,2,4]): true ONLY for a plain consultant — everyone else
     * (admin, registrar, resident, observer) sees the whole unit. The ONE own-only predicate,
     * shared by PatientsController::boardScope() and HandoverController's needs-handover
     * dataset/count so the two conventions can never drift apart.
     */
    public function seesOwnPatientsOnly(): bool
    {
        return (int) $this->role === self::ROLE_CONSULTANT && ! $this->isAdmin();
    }

    /**
     * A user may manage (discharge/transfer/edit/undo) an admission when they are:
     *   - the admin, OR
     *   - have the can_manage capability flag, OR
     *   - are the primary consultant on the admission.
     * Observers are globally read-only and are rejected BEFORE the other conditions are checked
     * (the capability flags must never override the read-only guarantee — J1-9). The ONE manage
     * rule, delegated to by PatientActionController + HandoverController.
     */
    public function canManageAdmission(Admission $a): bool
    {
        if ($this->isObserver()) {
            return false;
        }

        return $this->isAdmin() || $this->can_manage || (int) $a->consultant_id === (int) $this->id;
    }

    /**
     * Consultation sign-off / edit is CONSULTANT-centric (the receiving consultant), but follows the
     * SAME observer-first / admin / can_manage / owner pattern as canManageAdmission. Delegated to by
     * ConsultationsController::signoff.
     */
    public function canManageConsultation(Consultation $c): bool
    {
        if ($this->isObserver()) {
            return false;
        }

        return $this->isAdmin() || $this->can_manage || (int) $c->consultant_id === (int) $this->id;
    }
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
