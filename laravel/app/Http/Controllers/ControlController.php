<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ConsultationReason;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
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
                'id' => $u->id, 'name' => $u->full_name ?: $u->name, 'username' => $u->username, 'email' => $u->email,
                'role' => (int) $u->role, 'role_label' => $u->roleLabel(), 'active' => (bool) $u->active,
                'on_service' => (bool) $u->on_service, 'specialty_id' => $u->specialty_id, 'mfa' => (bool) $u->mfa_enrolled_at,
                'can' => ['assign' => (bool) $u->can_assign, 'add' => (bool) $u->can_add, 'manage' => (bool) $u->can_manage, 'modify' => (bool) $u->can_modify],
            ]);

        return Inertia::render('Control/Index', [
            'settings' => Setting::current(),
            'users' => $users,
            'filters' => ['q' => $request->query('q', '')],
            'roles' => User::ROLE_LABELS,
            'specialties' => Specialty::orderBy('name')->get(['id', 'name']),
            'reasons' => ConsultationReason::orderBy('name')->get(['id', 'name']),
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
            'mfa_enforcement' => ['required', 'integer', 'in:0,1,2'],
        ]);
        Setting::current()->update($data);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'settings.update',
            'entity_type' => 'settings', 'entity_id' => '1', 'details' => $data, 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Settings saved.']);
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
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

        $user->update($data);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'user.update',
            'entity_type' => 'user', 'entity_id' => (string) $user->id, 'details' => $data, 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => "Updated {$user->username}."]);
    }

    /** Clear a user's MFA enrollment (admin) — for a locked-out user who lost their device. */
    public function resetMfa(Request $request, User $user): RedirectResponse
    {
        $user->update(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_enrolled_at' => null]);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'user.reset_mfa',
            'entity_type' => 'user', 'entity_id' => (string) $user->id, 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => "Two-factor reset for {$user->username}."]);
    }

    /** Email a password-reset link to a user (admin). */
    public function sendReset(Request $request, User $user): RedirectResponse
    {
        if (! $user->email) {
            return back()->with('flash', ['type' => 'error', 'message' => 'That user has no email on file.']);
        }
        Password::sendResetLink(['email' => $user->email]);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'user.send_reset',
            'entity_type' => 'user', 'entity_id' => (string) $user->id, 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => "Password-reset link sent to {$user->email}."]);
    }

    public function addSpecialty(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191'], 'is_subspecialty' => ['boolean']]);
        Specialty::create(['name' => $data['name'], 'is_subspecialty' => $request->boolean('is_subspecialty', true)]);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'specialty.add',
            'entity_type' => 'specialty', 'entity_id' => null, 'details' => ['name' => $data['name']], 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Specialty added.']);
    }

    public function addReason(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);
        ConsultationReason::create(['name' => $data['name']]);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'reason.add',
            'entity_type' => 'consultation_reason', 'entity_id' => null, 'details' => ['name' => $data['name']], 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation indication added.']);
    }
}
