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
        // 2026-09-24 role/UX review #18: a pending self-registration (RegisterController::store —
        // created inactive, awaiting admin activation) is indistinguishable from an admin-deactivated
        // account, both showing a bare "Disabled" badge. Derive "still pending" from the audit trail
        // rather than a new column: RegisterController stamps a 'user.self_register' row at creation,
        // and because every self-registered account starts at active=false, the ONLY way an
        // 'active' key can appear in a later 'user.update' diff (AuditDiff — see ControlController::
        // updateUser) is a false→true flip, i.e. the account was activated at least once (even if an
        // admin later deactivated it again). So "self-registered AND never touched" == "still pending".
        $selfRegisteredIds = DB::table('audit_log')
            ->where('action', 'user.self_register')->where('entity_type', 'user')
            ->pluck('entity_id')->map(fn ($id) => (int) $id)->all();
        $everTouchedActiveIds = DB::table('audit_log')
            ->where('action', 'user.update')->where('entity_type', 'user')
            ->whereRaw("JSON_CONTAINS_PATH(details, 'one', '$.active')")
            ->pluck('entity_id')->map(fn ($id) => (int) $id)->all();
        $pendingIds = array_flip(array_diff($selfRegisteredIds, $everTouchedActiveIds));

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
                // #18: true only for a NEVER-YET-ACTIVATED self-registration, never for an
                // admin-deactivated account — see the derivation above.
                'pending_registration' => ! $u->active && isset($pendingIds[$u->id]),
                'registered_at' => optional($u->created_at)->toIso8601String(),
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
            // #3 (2026-09-24 role/UX review): an inverted pair (e.g. short=20, long=11) used to save
            // silently, corrupting the LOS colour-coding and the Long-Stay % statistic. `lt`/`gt`
            // compare against the OTHER field in this same submission (Laravel cross-field rules).
            'short_los' => ['required', 'integer', 'min:1', 'max:60', 'lt:long_los'],
            'long_los' => ['required', 'integer', 'min:1', 'max:120', 'gt:short_los'],
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
        ], [
            'short_los.lt' => 'Short LOS must be less than Long LOS.',
            'long_los.gt' => 'Long LOS must be greater than Short LOS.',
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
        //
        // 2026-09-23 walkthrough fix — MAJOR: deactivation blocked FUTURE logins (AuthController
        // filters active=1) but left an ALREADY-established session live indefinitely — nothing in
        // the auth chain re-checked `active` per request. End every session row now, mirroring
        // resetMfa() below; SessionTimeout also re-checks `active` per request as defence in depth
        // for any path that flips it outside this controller.
        $sessionsEnded = null;
        if (! $data['active']) {
            TrustedDevice::revokeAllFor($user->id);
            $sessionsEnded = DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        $diff = AuditDiff::diff($before, $user->fresh()->only($fields), ['password']);
        // Phase 4 — Item 4: flag the step-up on a role-escalation update (admin grant)
        Audit::log('user.update', 'user', (string) $user->id,
            $diff + ($escalated ? $this->stepUpDetail() : [])
            + ($sessionsEnded !== null ? ['sessions_ended' => $sessionsEnded] : []));

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

        // Same reasoning as deactivation: the delete is SOFT and restorable from /trashed, so a live
        // waiver would come back with the account. Revoke before deleting. (user.delete audits it.)
        // 2026-09-23 walkthrough fix — same MAJOR defect as deactivation: a soft-deleted user's
        // existing session survived the delete. End every session row before the audit write so its
        // detail records what actually happened.
        TrustedDevice::revokeAllFor($user->id);
        $sessionsEnded = DB::table('sessions')->where('user_id', $user->id)->delete();

        Audit::log('user.delete', 'user', (string) $user->id,
            ['username' => $user->username, 'name' => $user->full_name ?: $user->name, 'role' => (int) $user->role,
                'sessions_ended' => $sessionsEnded]
            + $this->stepUpDetail());
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

    /**
     * Case-insensitive, trimmed uniqueness check for a reference-table name (#2, 2026-09-24 role/UX
     * review) — "Nephrology" and " nephrology " must collide. $ignoreId excludes the row being
     * renamed so a no-op rename doesn't reject itself.
     */
    private function nameTaken(string $table, string $name, ?int $ignoreId = null): bool
    {
        return DB::table($table)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
            // truthy check (not is_null) would skip id 0 — consultation_reasons' real "Other"
            // sentinel row — and falsely reject renaming it to its own unchanged name (review fix).
            ->when(! is_null($ignoreId), fn ($q) => $q->where('id', '<>', $ignoreId))
            ->exists();
    }

    public function addSpecialty(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191'], 'is_subspecialty' => ['boolean'], 'is_external' => ['boolean']]);
        // #2: block a duplicate at creation — previously two identical rows could exist with no
        // edit/delete route to fix either one.
        if ($this->nameTaken('specialties', $data['name'])) {
            return back()->withErrors(['name' => 'A specialty with this name already exists.'])->withInput();
        }
        Specialty::create([
            'name' => trim($data['name']),
            'is_subspecialty' => $request->boolean('is_subspecialty', true),
            'is_external' => $request->boolean('is_external', false),   // external/allied service = transfer-out target only
        ]);
        Audit::log('specialty.add', 'specialty', null,
            ['name' => trim($data['name']), 'is_external' => $request->boolean('is_external', false)]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Specialty added.']);
    }

    /**
     * #2: rename a specialty (case-insensitive/trimmed uniqueness, audited before/after). Every
     * dropdown reads the live row, so this updates every past label — the closed-episode
     * `admissions.discharge_to` snapshot (a plain string, written at transfer time) is untouched by
     * design, same as any other historical free-text field.
     */
    public function updateSpecialty(Request $request, Specialty $specialty): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);
        if ($this->nameTaken('specialties', $data['name'], $specialty->id)) {
            return back()->withErrors(['name' => 'Another specialty already has this name.'])->withInput();
        }
        $before = $specialty->name;
        $specialty->update(['name' => trim($data['name'])]);
        Audit::log('specialty.rename', 'specialty', (string) $specialty->id, ['from' => $before, 'to' => $specialty->name]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Specialty renamed.']);
    }

    /**
     * #2: delete a specialty — refused when it is still referenced anywhere. `users.specialty_id`
     * carries no DB-level foreign key (extend_users_table never added ->constrained()), so this is
     * an APPLICATION-level guard; `consultations.owning_specialty_id` does have a nullOnDelete FK,
     * but the check still runs here so the admin gets a clear reason instead of a silently-orphaned
     * consult. Both checks read straight from the tables (not the Eloquent models), which is
     * deliberately soft-delete-BLIND: a trashed user/consultation can still be restored later, so a
     * row only "not referenced" once nothing — live or trashed — points at it.
     *
     * Specialty id 1 additionally can never be deleted, referenced or not: ShuffleService,
     * DashboardController's "Census by service" split and PatientsController's on-service ranking
     * all hardcode literal id 1 as "the Hospitalist pool" (legacy parity), not by foreign key or the
     * `is_subspecialty` flag. MySQL never reuses a freed auto-increment id, so once id 1 is deleted
     * those three features would silently and permanently lose their Hospitalist pool with no error
     * (review fix, 2026-09-24).
     */
    public function destroySpecialty(Specialty $specialty): RedirectResponse
    {
        if ($specialty->id === 1) {
            return back()->with('flash', ['type' => 'error',
                'message' => 'This specialty is used internally to identify the Hospitalist pool and cannot be deleted.']);
        }
        $usedByUsers = DB::table('users')->where('specialty_id', $specialty->id)->exists();
        $usedByConsultations = DB::table('consultations')->where('owning_specialty_id', $specialty->id)->exists();
        if ($usedByUsers || $usedByConsultations) {
            $where = collect([
                $usedByUsers ? 'at least one staff account' : null,
                $usedByConsultations ? 'at least one consultation' : null,
            ])->filter()->implode(' and ');

            return back()->with('flash', ['type' => 'error',
                'message' => "Cannot delete \"{$specialty->name}\" — it is still assigned to {$where}."]);
        }
        $name = $specialty->name;
        Audit::log('specialty.delete', 'specialty', (string) $specialty->id, ['name' => $name]);
        $specialty->delete();   // no soft-delete column on this table (never had one) — genuinely unused, safe to remove

        return back()->with('flash', ['type' => 'success', 'message' => "Deleted specialty \"{$name}\"."]);
    }

    public function addReason(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);
        if ($this->nameTaken('consultation_reasons', $data['name'])) {
            return back()->withErrors(['name' => 'An indication with this name already exists.'])->withInput();
        }
        ConsultationReason::create(['name' => trim($data['name'])]);
        Audit::log('reason.add', 'consultation_reason', null, ['name' => trim($data['name'])]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation indication added.']);
    }

    /**
     * #2: rename a consultation indication (same uniqueness rule + audit as specialties). Id 0 is
     * the real "Other" sentinel (DATABASE-AND-BEHAVIOR.md §consultation_reasons; hardcoded by id in
     * ConsultationRequest::rules() to require `other_indication`, and by literal placeholder text
     * "required when 'Other' is selected" in Consultations/Index.vue) — it is refused here so its
     * label can never drift out of sync with that hardcoded text (review fix, 2026-09-24).
     */
    public function updateReason(Request $request, ConsultationReason $reason): RedirectResponse
    {
        if ($reason->id === 0) {
            return back()->with('flash', ['type' => 'error', 'message' => '"Other" is a fixed system option and cannot be renamed.']);
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);
        if ($this->nameTaken('consultation_reasons', $data['name'], $reason->id)) {
            return back()->withErrors(['name' => 'Another indication already has this name.'])->withInput();
        }
        $before = $reason->name;
        $reason->update(['name' => trim($data['name'])]);
        Audit::log('reason.rename', 'consultation_reason', (string) $reason->id, ['from' => $before, 'to' => $reason->name]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Indication renamed.']);
    }

    /**
     * #2: delete a consultation indication — refused while any consultation still carries its id in
     * `consultations.indication` (a JSON array of reason ids, no DB foreign key possible on a JSON
     * column). `whereJsonContains` reads every consultation, trashed included (DB::table bypasses
     * the model's soft-delete scope), same soft-delete-blind reasoning as destroySpecialty().
     *
     * Id 0 additionally can never be deleted, referenced or not: it is the real "Other" sentinel
     * hardcoded by id in `ConsultationRequest::rules()` (requires `other_indication` free text when
     * id 0 is picked) and documented in DATABASE-AND-BEHAVIOR.md. Once nothing currently references
     * it, deleting it would silently remove the "Other" option from every future consult-booking
     * form (review fix, 2026-09-24).
     */
    public function destroyReason(ConsultationReason $reason): RedirectResponse
    {
        if ($reason->id === 0) {
            return back()->with('flash', ['type' => 'error', 'message' => '"Other" is a fixed system option and cannot be deleted.']);
        }
        if (DB::table('consultations')->whereJsonContains('indication', $reason->id)->exists()) {
            return back()->with('flash', ['type' => 'error',
                'message' => "Cannot delete \"{$reason->name}\" — it is still used by at least one consultation."]);
        }
        $name = $reason->name;
        Audit::log('reason.delete', 'consultation_reason', (string) $reason->id, ['name' => $name]);
        $reason->delete();

        return back()->with('flash', ['type' => 'success', 'message' => "Deleted indication \"{$name}\"."]);
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
