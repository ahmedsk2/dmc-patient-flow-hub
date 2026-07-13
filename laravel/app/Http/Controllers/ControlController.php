<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\ConsultationReason;
use App\Models\ReportRecipient;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Audit;
use App\Support\AuditDiff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ControlController extends Controller
{
    public function index(Request $request): Response
    {
        // Ship ALL users (~323 rows — a small payload) so the Users tab filters + searches INSTANTLY
        // client-side: no server round-trip per keystroke, no pages to click past. See Control/Index.vue.
        $users = User::query()
            ->orderBy('role')->orderBy('full_name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->full_name ?: $u->name, 'full_name' => $u->full_name, 'username' => $u->username, 'email' => $u->email,
                'role' => (int) $u->role, 'role_label' => $u->roleLabel(), 'active' => (bool) $u->active,
                'on_service' => (bool) $u->on_service, 'specialty_id' => $u->specialty_id, 'mfa' => (bool) $u->mfa_enrolled_at,
                'can' => ['assign' => (bool) $u->can_assign, 'add' => (bool) $u->can_add, 'manage' => (bool) $u->can_manage, 'modify' => (bool) $u->can_modify],
            ]);

        return Inertia::render('Control/Index', [
            'settings' => Setting::current(),
            // 2026-07-11 auth-hardening: MFA enrollment is now mandatory for every user, always —
            // the mfa_enforcement setting below is inert (kept in-schema); the UI should annotate
            // the control as a no-op rather than implying it still switches enrollment off.
            'mfaMandatory' => true,
            'users' => $users,
            'roles' => User::ROLE_LABELS,
            'specialties' => Specialty::orderBy('name')->get(['id', 'name', 'is_external']),
            'reasons' => ConsultationReason::orderBy('name')->get(['id', 'name']),
            'settingHistory' => DB::table('setting_changes as sc')->leftJoin('users as u', 'u.id', '=', 'sc.changed_by')
                ->orderByDesc('sc.id')->limit(25)
                ->selectRaw('sc.field, sc.old_value, sc.new_value, COALESCE(u.full_name, u.name) changed_by, sc.created_at')
                ->get(),
            // Phase 3 — §3.3: scheduled monthly-report recipients (merged into the existing page)
            'reportRecipients' => ReportRecipient::orderByDesc('created_at')->get(['id', 'email', 'active']),
            'counts' => [
                'users' => User::count(),
                'active_users' => User::where('active', 1)->count(),
                // Phase 4 — Item 1/9: count live (non-soft-deleted) rows for the admin overview cards.
                // patients gained soft-delete in Item 9 (merge retires the duplicate source) — exclude
                // the retired rows so the headline count tracks distinct live patients.
                'patients' => DB::table('patients')->whereNull('deleted_at')->count(),
                'admissions' => DB::table('admissions')->whereNull('deleted_at')->count(),
                'consultations' => DB::table('consultations')->whereNull('deleted_at')->count(),
                'icd10' => DB::table('icd10')->count(),
                'specialties' => DB::table('specialties')->count(),
            ],
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'min_hospitalist' => ['required', 'integer', 'min:0', 'max:100'],
            'max_hospitalist' => ['required', 'integer', 'min:1', 'max:200'],
            'min_subs' => ['required', 'integer', 'min:0', 'max:100'],
            'max_subs' => ['required', 'integer', 'min:1', 'max:200'],
            'short_los' => ['required', 'integer', 'min:1', 'max:60'],
            'long_los' => ['required', 'integer', 'min:1', 'max:120'],
            'ward_beds' => ['required', 'integer', 'min:1', 'max:2000'],
            'icu_beds' => ['required', 'integer', 'min:0', 'max:1000'],
            'readmission_window_days' => ['required', 'integer', 'min:0', 'max:30'],
            'mfa_enforcement' => ['required', 'integer', 'in:0,1,2'],
            // Phase 4, Item 2 — session timeout (idle min 5, max 8h; absolute 0=off, max 24h)
            'idle_timeout_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'abs_timeout_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            // Phase 4, Item 3 — consecutive-failure notify threshold (0 = off)
            'failed_login_notify_threshold' => ['required', 'integer', 'min:0', 'max:50'],
            // Phase 4, Item 6 — data-quality stale-episode LOS multiplier (× long_los)
            'dq_los_multiplier' => ['required', 'integer', 'min:1', 'max:10'],
            // Phase 2, Item 3 — break-glass: also log per-record detail opens (default off)
            'log_record_opens' => ['sometimes', 'boolean'],
            // Phase 1, Item 4 — dashboard alert thresholds (clinician-tunable)
            'alert_overcensus_pct' => ['required', 'integer', 'min:50', 'max:200'],
            'alert_boarding_max' => ['required', 'integer', 'min:0', 'max:100'],
            'alert_readmit_rate_pct' => ['required', 'integer', 'min:1', 'max:100'],
            'alert_deaths_delta_pct' => ['required', 'integer', 'min:10', 'max:500'],
        ]);
        $settings = Setting::current();

        // append-only history: one row per field that actually changed (tracks e.g. ward
        // capacity over time — queryable later, unlike the JSON blob in audit_logs)
        foreach ($data as $field => $new) {
            $old = $settings->{$field};
            if ($old !== null && (string) $old === (string) $new) {
                continue;
            }
            DB::table('setting_changes')->insert([
                'field' => $field,
                'old_value' => $old === null ? null : (string) $old,
                'new_value' => (string) $new,
                'changed_by' => Auth::id(),
                'created_at' => now(),
            ]);
        }

        $settings->update($data);
        Audit::log('settings.update', 'settings', '1', $data);

        return back()->with('flash', ['type' => 'success', 'message' => 'Settings saved.']);
    }

    /** Phase 4 — Item 4: ['step_up' => true] when a recent step-up is in session, else []. */
    private function stepUpDetail(): array
    {
        $verifiedAt = session('stepup.verified_at');

        return ($verifiedAt && (now()->getTimestamp() - (int) $verifiedAt) <= \App\Http\Middleware\RequireStepUp::WINDOW_SECONDS)
            ? ['step_up' => true] : [];
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'full_name' => ['nullable', 'string', 'max:191'],
            // app-level uniqueness only — the DB index was dropped (legacy members shared/lacked emails)
            'email' => ['nullable', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', 'integer', 'in:0,2,3,4,5'],
            'active' => ['required', 'boolean'],
            'on_service' => ['required', 'boolean'],
            'specialty_id' => ['nullable', 'exists:specialties,id'],
            'can_assign' => ['required', 'boolean'],
            'can_add' => ['required', 'boolean'],
            'can_manage' => ['required', 'boolean'],
            'can_modify' => ['required', 'boolean'],
        ]);

        // Phase 4 — Item 4: granting admin (role -> 0 on a non-admin) is a highest-risk action and
        // needs a recent step-up re-auth. The route is shared with non-escalating updates, so the
        // guard is inline rather than route middleware. A fresh step-up (< 5 min) lets it proceed.
        if ((int) $data['role'] === User::ROLE_ADMIN && (int) $user->role !== User::ROLE_ADMIN) {
            $verifiedAt = session('stepup.verified_at');
            if (! $verifiedAt || (now()->getTimestamp() - (int) $verifiedAt) > \App\Http\Middleware\RequireStepUp::WINDOW_SECONDS) {
                session(['stepup.intended' => route('control.index'), 'stepup.intended_method' => 'GET']);

                return redirect()->route('stepup.show')->with('flash', [
                    'type' => 'error', 'message' => 'Re-authentication required to grant admin access.',
                ]);
            }
        }

        // guard against an admin locking themselves out
        if ($user->id === Auth::id() && ((int) $data['role'] !== User::ROLE_ADMIN || ! $data['active'])) {
            return back()->with('flash', ['type' => 'error', 'message' => 'You cannot remove your own admin access.']);
        }

        // legacy parity: a user still carrying active patients cannot be deactivated
        if (! $data['active'] && Admission::whereNull('discharge_date')->where('consultant_id', $user->id)->exists()) {
            return back()->with('flash', ['type' => 'error',
                'message' => "{$user->username} still has active patients — reassign or discharge them first."]);
        }

        // field-level diff (Item 4): snapshot the editable fields before update, diff after — so the
        // audit detail shows only what CHANGED (role 4 → 0, can_manage false → true), not the whole
        // payload. Passwords are not edited here, but omit defensively.
        $fields = ['username', 'full_name', 'email', 'role', 'active', 'on_service',
            'specialty_id', 'can_assign', 'can_add', 'can_manage', 'can_modify'];
        $before = $user->only($fields);
        $escalated = (int) $data['role'] === User::ROLE_ADMIN && (int) ($before['role'] ?? 99) !== User::ROLE_ADMIN;
        $user->update($data);
        $diff = AuditDiff::diff($before, $user->fresh()->only($fields), ['password']);
        // Phase 4 — Item 4: flag the step-up on a role-escalation update (admin grant)
        Audit::log('user.update', 'user', (string) $user->id, $diff + ($escalated ? $this->stepUpDetail() : []));

        return back()->with('flash', ['type' => 'success', 'message' => "Updated {$user->username}."]);
    }

    /**
     * Delete a user account (admin). Historical references survive: every FK to users
     * (admissions.consultant_id/admitted_by/discharged_by, consultations.consultant_id/entered_by,
     * audit_log.actor_id, setting_changes.changed_by) is nullOnDelete, and display falls back to
     * the denormalised names. The audit row records the username BEFORE the account disappears.
     */
    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('flash', ['type' => 'error', 'message' => 'You cannot delete your own account.']);
        }
        if (Admission::whereNull('discharge_date')->where('consultant_id', $user->id)->exists()) {
            return back()->with('flash', ['type' => 'error',
                'message' => "{$user->username} still has active patients — reassign or discharge them first."]);
        }

        Audit::log('user.delete', 'user', (string) $user->id,
            ['username' => $user->username, 'name' => $user->full_name ?: $user->name, 'role' => (int) $user->role]
            + $this->stepUpDetail());
        $user->delete();   // SoftDeletes — attribution survives; recover via /trashed

        return back()->with('flash', ['type' => 'success', 'message' => "Deleted {$user->username}."]);
    }

    /** Clear a user's MFA enrollment (admin) — for a locked-out user who lost their device. */
    public function resetMfa(Request $request, User $user): RedirectResponse
    {
        $user->update(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_enrolled_at' => null]);
        Audit::log('user.reset_mfa', 'user', (string) $user->id);

        return back()->with('flash', ['type' => 'success', 'message' => "Two-factor reset for {$user->username}."]);
    }

    /** Email a password-reset link to a user (admin). */
    public function sendReset(Request $request, User $user): RedirectResponse
    {
        if (! $user->email) {
            return back()->with('flash', ['type' => 'error', 'message' => 'That user has no email on file.']);
        }
        Password::sendResetLink(['email' => $user->email]);
        Audit::log('user.send_reset', 'user', (string) $user->id);

        return back()->with('flash', ['type' => 'success', 'message' => "Password-reset link sent to {$user->email}."]);
    }

    public function addSpecialty(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191'], 'is_subspecialty' => ['boolean'], 'is_external' => ['boolean']]);
        Specialty::create([
            'name' => $data['name'],
            'is_subspecialty' => $request->boolean('is_subspecialty', true),
            'is_external' => $request->boolean('is_external', false),   // external/allied service = transfer-out target only
        ]);
        Audit::log('specialty.add', 'specialty', null,
            ['name' => $data['name'], 'is_external' => $request->boolean('is_external', false)]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Specialty added.']);
    }

    public function addReason(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);
        ConsultationReason::create(['name' => $data['name']]);
        Audit::log('reason.add', 'consultation_reason', null, ['name' => $data['name']]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation indication added.']);
    }

    /** Phase 3 — §3.3: add a monthly-report email recipient. */
    public function addReportRecipient(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:report_recipients,email'],
        ]);
        $recipient = ReportRecipient::create(['email' => $data['email'], 'active' => true, 'added_by_id' => Auth::id()]);
        Audit::log('report_recipient.add', 'report_recipient', (string) $recipient->id, ['email' => $data['email']]);

        return back()->with('flash', ['type' => 'success', 'message' => "Added {$data['email']} to monthly report recipients."]);
    }

    /** Phase 3 — §3.3: remove a monthly-report email recipient. */
    public function removeReportRecipient(ReportRecipient $recipient): RedirectResponse
    {
        $email = $recipient->email;
        Audit::log('report_recipient.remove', 'report_recipient', (string) $recipient->id, ['email' => $email]);
        $recipient->delete();

        return back()->with('flash', ['type' => 'success', 'message' => "Removed {$email} from monthly report recipients."]);
    }
}
