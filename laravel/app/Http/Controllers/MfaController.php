<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MfaController extends Controller
{
    /** Begin enrollment: generate a secret + recovery codes, hold them in the session, show the QR. */
    public function setup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->mfaEnabled()) {
            return redirect()->route('profile.edit')->with('flash', ['type' => 'error', 'message' => 'Two-factor is already enabled.']);
        }

        $secret = $request->session()->get('mfa.setup.secret') ?: Totp::secret();
        $codes = $request->session()->get('mfa.setup.codes') ?: Totp::recoveryCodes();
        $request->session()->put('mfa.setup.secret', $secret);
        $request->session()->put('mfa.setup.codes', $codes);

        return Inertia::render('Mfa/Setup', [
            'secret' => $secret,
            'otpauthUri' => Totp::uri($secret, $user->email ?: $user->username, config('app.name')),
            'recoveryCodes' => $codes,
        ]);
    }

    /** Confirm enrollment by verifying the first code, then persist (secret encrypted, codes hashed). */
    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        $secret = $request->session()->get('mfa.setup.secret');
        $codes = $request->session()->get('mfa.setup.codes', []);
        if (! $secret) {
            return redirect()->route('mfa.setup')->with('flash', ['type' => 'error', 'message' => 'Setup expired — start again.']);
        }
        $request->validate(['code' => ['required', 'string']]);
        if (! Totp::verify($secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'That code is incorrect — check your authenticator and try again.']);
        }

        $user->update([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
            'mfa_enrolled_at' => now(),
        ]);
        $request->session()->forget(['mfa.setup.secret', 'mfa.setup.codes']);
        $this->audit($user, 'mfa.enable');

        return redirect()->route('profile.edit')->with('flash', ['type' => 'success', 'message' => 'Two-factor authentication enabled.']);
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'Incorrect password.']);
        }
        $user->update(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_enrolled_at' => null]);
        $this->audit($user, 'mfa.disable');

        return redirect()->route('profile.edit')->with('flash', ['type' => 'success', 'message' => 'Two-factor authentication disabled.']);
    }

    /** The post-password login challenge (user is NOT yet authenticated; identity held in session). */
    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('mfa.pending.id')) {
            return redirect()->route('login');
        }
        return Inertia::render('Auth/MfaChallenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $id = $request->session()->get('mfa.pending.id');
        if (! $id || ! ($user = User::find($id))) {
            return redirect()->route('login');
        }
        $request->validate(['code' => ['required', 'string']]);
        $input = trim($request->input('code'));

        $ok = $user->mfa_secret && Totp::verify($user->mfa_secret, $input);
        if (! $ok) {
            $ok = $this->consumeRecoveryCode($user, $input);
        }
        if (! $ok) {
            throw ValidationException::withMessages(['code' => 'Invalid authentication code.']);
        }

        Auth::login($user, (bool) $request->session()->get('mfa.pending.remember', false));
        $request->session()->forget(['mfa.pending.id', 'mfa.pending.remember']);
        $request->session()->regenerate();
        $this->audit($user, 'login.mfa');

        return redirect()->intended(route('dashboard'));
    }

    private function consumeRecoveryCode(User $user, string $input): bool
    {
        $codes = $user->mfa_recovery_codes ?? [];
        $normalized = strtoupper($input);
        foreach ($codes as $i => $hash) {
            if (Hash::check($normalized, $hash)) {
                unset($codes[$i]);
                $user->update(['mfa_recovery_codes' => array_values($codes)]);
                return true;
            }
        }
        return false;
    }

    private function audit(User $user, string $action): void
    {
        AuditLog::create([
            'actor_id' => $user->id, 'actor_name' => $user->name, 'action' => $action,
            'entity_type' => 'user', 'entity_id' => (string) $user->id, 'ip' => request()->ip(),
        ]);
    }
}
