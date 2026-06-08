<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(): Response
    {
        $u = Auth::user();

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $u->full_name ?: $u->name,
                'username' => $u->username,
                'email' => $u->email,
                'role' => $u->roleLabel(),
                'pass_exp_date' => optional($u->pass_exp_date)->toDateString(),
                'mfa_enabled' => (bool) $u->mfa_enrolled_at,
            ],
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $u = Auth::user();
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
        ]);
        $u->update(['full_name' => $data['full_name'], 'name' => $data['full_name'], 'email' => $data['email']]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Profile updated.']);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $u = Auth::user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! Hash::check($data['current_password'], $u->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $u->update([
            'password' => $data['password'],                 // hashed by the model cast
            'pass_exp_date' => now()->toDateString(),        // resets the 3-month expiry clock
        ]);
        AuditLog::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'action' => 'password.change',
            'entity_type' => 'user', 'entity_id' => (string) $u->id, 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Password changed.']);
    }
}
