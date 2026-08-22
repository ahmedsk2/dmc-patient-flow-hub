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
            'can_coordinate_consultations' => 'boolean',
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

    /**
     * Consultation COORDINATOR (W1): may book a consult into any specialty, see every consult and
     * modify every consult. Deliberately NOT a sign-off grant — see canManageConsultation(), which
     * remains the sign-off gate (owning consultant / can_manage / admin). Observers are rejected
     * FIRST, so the read-only guarantee can never be bought with a capability flag.
     */
    public function canCoordinateConsultations(): bool
    {
        if ($this->isObserver()) {
            return false;
        }

        return $this->isAdmin() || (bool) $this->can_coordinate_consultations;
    }

    /**
     * May this user SEE a consultation? Ownership is `owning_specialty_id` + `consultant_id` and is
     * independent of `entered_by` — but you always keep sight of a consult you entered or that is
     * assigned to you, otherwise a registrar who books one loses it the moment they save.
     * Unassigned rows (NULL owning specialty) are visible to admins and coordinators only.
     * Observers are excluded: the consultations workspace is a clinical-role page (J2-12).
     */
    public function canSeeConsultation(Consultation $c): bool
    {
        if ($this->isObserver()) {
            return false;
        }
        if ($this->isAdmin() || $this->canCoordinateConsultations()) {
            return true;
        }
        if ($this->specialty_id !== null && (int) $c->owning_specialty_id === (int) $this->specialty_id) {
            return true;
        }

        return (int) $c->consultant_id === (int) $this->id || (int) $c->entered_by === (int) $this->id;
    }

    /**
     * May this user EDIT a consultation? Coordinators modify all; everyone else may edit what they
     * can see. NOTE: W1 defines this predicate but deliberately does NOT wire it into
     * ConsultationsController::update(), which keeps its legacy-open gate (J1-10: legacy modify had
     * no ownership check, pinned by Round5J1Test). W2 flips update() over to this predicate.
     */
    public function canModifyConsultation(Consultation $c): bool
    {
        if ($this->isObserver()) {
            return false;
        }

        return $this->isAdmin() || $this->canCoordinateConsultations() || $this->canSeeConsultation($c);
    }

    /**
     * May this user record TODAY's follow-up tick against a consultation? (W2b)
     *
     * Deliberately NARROWER than canSeeConsultation(), which is a VISIBILITY predicate and on
     * purpose also returns true for `entered_by` ("you never lose sight of a consult you personally
     * booked"). A follow-up is not a read: it is the receiving team's assertion that they rounded on
     * this patient today. Letting the REFERRER tick it — a Nephrology registrar closing out the day
     * on a Cardiology consult she merely booked — would misattribute the clinical fact (`author_id`
     * would name her) and inflate Cardiology's "seen 8 of 12" with a round nobody on that team made.
     *
     * So: the owning team, the assigned consultant, a coordinator, or an admin. Observers first, as
     * everywhere. `Consultation::scopeRecordableBy` is the query mirror of this predicate and must
     * be kept in lockstep with it — the daily worklist is built from that scope, and a worklist row
     * this predicate would refuse is a must-be-seen item nobody can ever clear.
     */
    public function canRecordFollowup(Consultation $c): bool
    {
        if ($this->isObserver()) {
            return false;
        }
        if ($this->isAdmin() || $this->canCoordinateConsultations()) {
            return true;
        }
        if ($this->specialty_id !== null && (int) $c->owning_specialty_id === (int) $this->specialty_id) {
            return true;
        }

        return $c->consultant_id !== null && (int) $c->consultant_id === (int) $this->id;
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
    public function trustedDevices(): HasMany { return $this->hasMany(TrustedDevice::class); }
}
