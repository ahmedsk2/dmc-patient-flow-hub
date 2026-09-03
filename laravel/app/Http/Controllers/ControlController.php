<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireStepUp;
use App\Models\Admission;
use App\Models\ConsultationReason;
use App\Models\ReportRecipient;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Audit;
use App\Support\AuditDiff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
                'can' => ['assign' => (bool) $u->can_assign, 'add' => (bool) $u->can_add, 'manage' => (bool) $u->can_manage, 'modify' => (bool) $u->can_modify,
                    'coordinate' => (bool) $u->can_coordinate_consultations],
            ]);

        $s = Setting::current();

        return Inertia::render('Control/Index', [
            'settings' => Setting::current(),
            'system' => [
                'mail_mailer' => $s->mail_mailer, 'mail_host' => $s->mail_host, 'mail_port' => $s->mail_port,
                'mail_encryption' => $s->mail_encryption, 'mail_username' => $s->mail_username,
                'mail_password_set' => filled($s->getRawOriginal('mail_password')),
                'mail_from_address' => $s->mail_from_address, 'mail_from_name' => $s->mail_from_name,
                'app_timezone' => $s->app_timezone, 'app_name' => $s->app_name, 'app_url' => $s->app_url,
            ],
            'timezones' => timezone_identifiers_list(),
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
            // Trusted device (2026-07-19) — TOTP-skip window in hours; 0 disables the feature.
            // `required` like its neighbours now that the Control Settings form actually ships the
            // field: the form submits a complete payload, so an omitted key means a malformed
            // request, not a partial save.
            'mfa_trusted_device_hours' => ['required', 'integer', 'min:0', 'max:720'],
            // Phase 4, Item 2 — session timeout (idle min 5, max 8h; absolute 0=off, max 24h)
            'idle_timeout_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'abs_timeout_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            // Phase 4, Item 3 — consecutive-failure notify threshold (0 = off)
            'failed_login_notify_threshold' => ['required', 'integer', 'min:0', 'max:50'],
            // Phase 4, Item 6 — data-quality stale-episode LOS multiplier (× long_los)
            'dq_los_multiplier' => ['required', 'integer', 'min:1', 'max:10'],
            // Phase 2, Item 3 — break-glass: also log per-record detail opens (default off)
            'log_record_opens' => ['sometimes', 'boolean'],
            // The consultation-ledger cutover gate. When true, `legacy:import` preserves the
            // consultations table instead of truncating it (see LegacyImport::handle()). Flipping this
            // ON is what makes the new system the source of truth for consultations.
            'consultations_source_of_truth' => ['sometimes', 'boolean'],
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
            // Booleans (e.g. log_record_opens, consultations_source_of_truth) must be normalised to
            // '1'/'0' here — plain (string) casting turns `false` into '' (an empty string, not '0'),
            // which renders as an illegible blank in the Change history panel (Control/Index.vue).
            DB::table('setting_changes')->insert([
                'field' => $field,
                'old_value' => $old === null ? null : $this->historyValue($old),
                'new_value' => $this->historyValue($new),
                'changed_by' => Auth::id(),
                'created_at' => now(),
            ]);
        }

        $settings->update($data);
        Audit::log('settings.update', 'settings', '1', $data);

        return back()->with('flash', ['type' => 'success', 'message' => 'Settings saved.']);
    }

    /**
     * String-normalise a setting_changes value. Plain `(string)` casting turns a boolean `false`
     * into '' (PHP: `(string) false === ''`), which the Change history panel then renders as an
     * illegible blank right-hand side — the most security-relevant change this form can record
     * (e.g. disabling the consultation-ledger cutover gate) would look like "1 → " instead of "1 → 0".
     */
    private function historyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * Save runtime config (SMTP / timezone / app basics). The SMTP password is write-only:
     * a blank submit keeps the current stored value, the plaintext is never echoed back to the
     * client (see index()'s mail_password_set flag), and both the setting_changes history row
     * and the audit_log detail redact it rather than recording the value.
     */
    public function updateSystem(Request $request): RedirectResponse
    {
        $rules = [
            'mail_mailer' => ['nullable', 'in:smtp,log'],
            'mail_host' => ['nullable', 'required_if:mail_mailer,smtp', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_encryption' => ['nullable', 'in:tls,ssl,none'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'app_timezone' => ['nullable', Rule::in(timezone_identifiers_list())],
            'app_name' => ['nullable', 'string', 'max:120'],
            'app_url' => ['nullable', 'url', 'max:255'],
        ];

        // Validate manually so a validation FAILURE never flashes the plaintext SMTP password into the
        // (unencrypted, file-driver) session's old-input bag — Laravel's default $dontFlash omits it.
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except('mail_password'));
        }
        $data = $validator->validated();

        // write-only password: a blank/absent submit KEEPS the current value.
        if (! filled($data['mail_password'] ?? null)) {
            unset($data['mail_password']);
        }

        $settings = Setting::current();

        // append-only history, mirroring updateSettings — the password value is REDACTED.
        $passwordChanged = false;
        foreach ($data as $field => $new) {
            if ($field === 'mail_password') {
                // decrypting the current value can throw if the ciphertext is undecryptable (APP_KEY
                // mismatch / cross-env DB copy) — guard it so submitting a NEW password can't 500.
                try {
                    $old = $settings->mail_password;
                } catch (\Throwable) {
                    $old = null;
                }
            } else {
                $old = $settings->{$field};
            }
            if ((string) $old === (string) $new) {
                continue;
            }
            $redact = $field === 'mail_password';
            if ($redact) {
                $passwordChanged = true;
            }
            DB::table('setting_changes')->insert([
                'field' => $field,
                'old_value' => $redact ? '••••' : ($old === null ? null : (string) $old),
                'new_value' => $redact ? '••••' : (string) $new,
                'changed_by' => Auth::id(),
                'created_at' => now(),
            ]);
        }

        $settings->update($data);

        // audit detail: changed non-secret fields (+ a redacted 'changed' marker only when the password
        // actually changed) + whether this was step-up verified (convention: destroyUser).
        $detail = collect($data)->except('mail_password')->all();
        if ($passwordChanged) {
            $detail['mail_password'] = 'changed';
        }
        Audit::log('settings.system.update', 'settings', '1', $detail + $this->stepUpDetail());

        return back()->with('flash', ['type' => 'success', 'message' => 'System configuration saved.']);
    }

    /** Send a one-off test email to the acting admin's own address, using the current mail config. */
    public function testEmail(Request $request): RedirectResponse
    {
        $to = $request->user()->email;
        if (! $to) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Your account has no email address to send a test to.']);
        }

        try {
            Mail::raw('This is a test email from the DMC Internal Medicine Control Panel. If you received it, your mail settings are working.',
                fn ($m) => $m->to($to)->subject('DMC — test email'));
        } catch (\Throwable $e) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Test email failed: '.$e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => "Test email sent to {$to}."]);
    }

    /** Phase 4 — Item 4: ['step_up' => true] when a recent step-up is in session, else []. */
    private function stepUpDetail(): array
    {
        $verifiedAt = session('stepup.verified_at');

        return ($verifiedAt && (now()->getTimestamp() - (int) $verifiedAt) <= RequireStepUp::WINDOW_SECONDS)
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
            // 'sometimes', not 'required': a payload that predates this flag (older callers and the
            // existing Control tests) must leave the grant UNCHANGED rather than silently revoke it.
            // The Control form always sends it, so the admin UI is unaffected.
            'can_coordinate_consultations' => ['sometimes', 'boolean'],
        ]);

        // Phase 4 — Item 4: granting admin (role -> 0 on a non-admin) is a highest-risk action and
        // needs a recent step-up re-auth. The route is shared with non-escalating updates, so the
        // guard is inline rather than route middleware. A fresh step-up (< 5 min) lets it proceed.
        if ((int) $data['role'] === User::ROLE_ADMIN && (int) $user->role !== User::ROLE_ADMIN) {
            $verifiedAt = session('stepup.verified_at');
            if (! $verifiedAt || (now()->getTimestamp() - (int) $verifiedAt) > RequireStepUp::WINDOW_SECONDS) {
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
            'specialty_id', 'can_assign', 'can_add', 'can_manage', 'can_modify',
            'can_coordinate_consultations'];
        $before = $user->only($fields);
        $escalated = (int) $data['role'] === User::ROLE_ADMIN && (int) ($before['role'] ?? 99) !== User::ROLE_ADMIN;
        $user->update($data);
        // Deactivation must REVOKE the waivers, not merely park them. AuthController::login filters
        // on active=1, so a deactivated user's trusted devices are only dormant — reactivating the
        // account inside the window would silently restore a second-factor skip on a browser the
        // admin believes they cut off. (Role changes deliberately do NOT revoke: that is a
        // capability change, not a credential change.) No extra audit row — user.update covers it.
        if (! $data['active']) {
            TrustedDevice::revokeAllFor($user->id);
        }
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
        // Same reasoning as deactivation: the delete is SOFT and restorable from /trashed, so a live
        // waiver would come back with the account. Revoke before deleting. (user.delete audits it.)
        TrustedDevice::revokeAllFor($user->id);
        $user->delete();   // SoftDeletes — attribution survives; recover via /trashed

        return back()->with('flash', ['type' => 'success', 'message' => "Deleted {$user->username}."]);
    }

    /** Clear a user's MFA enrollment (admin) — for a locked-out user who lost their device. */
    public function resetMfa(Request $request, User $user): RedirectResponse
    {
        $user->update(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_enrolled_at' => null]);
        // A reset is what an admin does when a user's second factor is lost OR compromised, so
        // everything standing on that factor must fall with it. Before 2026-07-19 this method
        // touched ONLY the columns above — the user's live sessions and recaller survived the
        // reset, and (once trusted devices shipped) so would every MFA waiver. All three now die.
        TrustedDevice::revokeAllFor($user->id);
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->setRememberToken(Str::random(60));
        $user->save();
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
