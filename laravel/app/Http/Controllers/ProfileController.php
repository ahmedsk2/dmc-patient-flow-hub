<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TrustedDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'trustedDevices' => $this->trustedDevicesFor($u->id),
        ]);
    }

    /**
     * The signed-in user's OWN live trusted devices, newest first.
     *
     * Scoped to $userId at the query — the browsing user's own id, never a request parameter — so
     * this can only ever expose the caller's rows. Expired/revoked rows are filtered out by
     * scopeUsable: the list shows what is currently waiving the second factor, nothing else.
     */
    private function trustedDevicesFor(int $userId): array
    {
        return TrustedDevice::query()->where('user_id', $userId)->usable()
            ->orderByDesc('created_at')->get()
            ->map(fn (TrustedDevice $d) => [
                'id' => $d->id,
                'label' => $d->label,
                'granted_at' => optional($d->created_at)->toIso8601String(),
                'expires_at' => optional($d->expires_at)->toIso8601String(),
                'last_used_at' => optional($d->last_used_at)->toIso8601String(),
            ])->all();
    }

    /**
     * Revoke ONE of the caller's trusted devices.
     *
     * Object-level authorization: the row is found by id AND user_id = Auth::id(), so a posted id
     * belonging to someone else simply matches nothing. The posted id is never trusted as proof of
     * ownership. Revoked, never deleted — the audit trail survives.
     */
    public function revokeTrustedDevice(Request $request, int $device): RedirectResponse
    {
        $u = Auth::user();
        $affected = TrustedDevice::query()->where('id', $device)->where('user_id', $u->id)
            ->whereNull('revoked_at')->update(['revoked_at' => now()]);

        // Withdrawing a second-factor waiver is security-relevant — audit it so "who revoked this,
        // and when" is answerable. Only on a real revocation: an id that matched nothing (someone
        // else's device, or one already dead) is not an event. Never log selector/validator.
        if ($affected > 0) {
            AuditLog::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'action' => 'mfa.device_revoked',
                'entity_type' => 'user', 'entity_id' => (string) $u->id,
                'details' => ['device_id' => $device], 'ip' => $request->ip()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => 'Device revoked — it will ask for a code next time.']);
    }

    /** Revoke every trusted device the caller holds (scoped to Auth::id(), same as above). */
    public function revokeAllTrustedDevices(Request $request): RedirectResponse
    {
        $u = Auth::user();
        // count the live rows first — revokeAllFor() is void, and the count is what the trail records
        $count = TrustedDevice::query()->where('user_id', $u->id)->whereNull('revoked_at')->count();
        TrustedDevice::revokeAllFor((int) $u->id);

        if ($count > 0) {
            AuditLog::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'action' => 'mfa.device_revoked',
                'entity_type' => 'user', 'entity_id' => (string) $u->id,
                'details' => ['count' => $count, 'scope' => 'all'], 'ip' => $request->ip()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => 'All trusted devices revoked.']);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $u = Auth::user();
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:191'],
            // app-level uniqueness only — the DB index was dropped (legacy members shared/lacked emails)
            'email' => ['nullable', 'email', 'max:191', Rule::unique('users', 'email')->ignore($u->id)],
            'username' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')->ignore($u->id)],
        ]);

        // a login-name change is security-relevant — audit it with the OLD value
        if ($data['username'] !== $u->username) {
            AuditLog::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'action' => 'user.username_change',
                'entity_type' => 'user', 'entity_id' => (string) $u->id,
                'details' => ['was' => $u->username, 'username' => $data['username']], 'ip' => $request->ip()]);
        }
        $u->update(['full_name' => $data['full_name'], 'name' => $data['full_name'],
            'email' => $data['email'], 'username' => $data['username']]);

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
        // a "change" to the SAME password would only restamp the 3-month expiry clock
        // (legacy change-password.php:41-43 parity)
        if (Hash::check($data['password'], $u->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from your previous one.']);
        }

        $u->update([
            'password' => $data['password'],                 // hashed by the model cast
            'pass_exp_date' => now()->toDateString(),        // resets the 3-month expiry clock
            'remember_token' => Str::random(60),             // rotate any recaller (defense in depth)
        ]);
        // Evict the user's OTHER sessions (keep the one making this change) so a changed password
        // logs a stolen live session out. (SESSION_DRIVER=database in prod; a no-op for other drivers.)
        DB::table('sessions')->where('user_id', $u->id)
            ->where('id', '!=', $request->session()->getId())->delete();
        // …and every trusted device: a standing MFA waiver must not outlive the credentials it was
        // granted against, or a changed password would leave an attacker's browser still skipping
        // the second factor (2026-07-19 trusted-device spec, "Revocation").
        TrustedDevice::revokeAllFor($u->id);
        AuditLog::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'action' => 'password.change',
            'entity_type' => 'user', 'entity_id' => (string) $u->id, 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Password changed.']);
    }
}
