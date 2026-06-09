<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
                'can' => ['assign' => (bool) $u->can_assign, 'add' => (bool) $u->can_add, 'manage' => (bool) $u->can_manage, 'modify' => (bool) $u->can_modify],
            ]);

        return Inertia::render('Control/Index', [
            'settings' => Setting::current(),
            'users' => $users,
            'filters' => ['q' => $request->query('q', '')],
            'roles' => User::ROLE_LABELS,
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
}
