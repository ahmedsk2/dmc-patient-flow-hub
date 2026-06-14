<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\ConsultationReason;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Audit;
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
        $users = User::query()
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) =>
                $w->where('full_name', 'like', "%{$s}%")->orWhere('username', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->orderBy('role')->orderBy('full_name')
            ->paginate(20)->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id, 'name' => $u->full_name ?: $u->name, 'full_name' => $u->full_name, 'username' => $u->username, 'email' => $u->email,
                'role' => (int) $u->role, 'role_label' => $u->roleLabel(), 'active' => (bool) $u->active,
                'on_service' => (bool) $u->on_service, 'specialty_id' => $u->specialty_id, 'mfa' => (bool) $u->mfa_enrolled_at,
                'can' => ['assign' => (bool) $u->can_assign, 'add' => (bool) $u->can_add, 'manage' => (bool) $u->can_manage, 'modify' => (bool) $u->can_modify],
            ]);

        return Inertia::render('Control/Index', [
            'settings' => Setting::current(),
            'users' => $users,
            'filters' => ['q' => $request->query('q', '')],
            'roles' => User::ROLE_LABELS,
            'specialties' => Specialty::orderBy('name')->get(['id', 'name', 'is_external']),
            'reasons' => ConsultationReason::orderBy('name')->get(['id', 'name']),
            'settingHistory' => DB::table('setting_changes as sc')->leftJoin('users as u', 'u.id', '=', 'sc.changed_by')
                ->orderByDesc('sc.id')->limit(25)
                ->selectRaw('sc.field, sc.old_value, sc.new_value, COALESCE(u.full_name, u.name) changed_by, sc.created_at')
                ->get(),
            'counts' => [
                'users' => User::count(),
                'active_users' => User::where('active', 1)->count(),
                'patients' => DB::table('patients')->count(),
                'admissions' => DB::table('admissions')->count(),
                'consultations' => DB::table('consultations')->count(),
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

        // guard against an admin locking themselves out
        if ($user->id === Auth::id() && ((int) $data['role'] !== User::ROLE_ADMIN || ! $data['active'])) {
            return back()->with('flash', ['type' => 'error', 'message' => 'You cannot remove your own admin access.']);
        }

        // legacy parity: a user still carrying active patients cannot be deactivated
        if (! $data['active'] && Admission::whereNull('discharge_date')->where('consultant_id', $user->id)->exists()) {
            return back()->with('flash', ['type' => 'error',
                'message' => "{$user->username} still has active patients — reassign or discharge them first."]);
        }

        $user->update($data);
        Audit::log('user.update', 'user', (string) $user->id, $data);

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
            ['username' => $user->username, 'name' => $user->full_name ?: $user->name, 'role' => (int) $user->role]);
        $user->delete();

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
}
