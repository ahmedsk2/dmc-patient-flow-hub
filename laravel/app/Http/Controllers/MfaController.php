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
        $counter = Totp::verifyWithCounter($secret, $request->input('code'));
        if ($counter === null) {
            throw ValidationException::withMessages(['code' => 'That code is incorrect — check your authenticator and try again.']);
        }

        $user->update([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
            'mfa_enrolled_at' => now(),
            'mfa_last_counter' => $counter,   // the enrollment code can't be replayed as the first challenge
        ]);
        $request->session()->forget(['mfa.setup.secret', 'mfa.setup.codes']);
        $this->audit($user, 'mfa.enable');

        return redirect()->route('profile.edit')->with('flash', ['type' => 'success', 'message' => 'Two-factor authentication enabled.']);
    }

    // NOTE: self-disable was REMOVED (owner decision, J2-14) — once enrolled, only an admin
    // can reset a user's MFA via the Control panel (ControlController::resetMfa).

    /** Pending-challenge TTL and guess budget — a parked login screen must not stay live. */
    private const PENDING_TTL_SECONDS = 300;   // 5 minutes after the password step
    private const MAX_ATTEMPTS = 8;            // per pending session

    /**
     * Is the session's pending MFA identity still usable? Missing/stale timestamps fail closed
     * (the only writer — AuthController::login — always stamps mfa.pending.at).
     */
    private function pendingFresh(Request $request): bool
    {
        $at = $request->session()->get('mfa.pending.at');

        return is_numeric($at) && (now()->getTimestamp() - (int) $at) <= self::PENDING_TTL_SECONDS;
    }

    /** Kill the pending identity and bounce to login with a fresh-start message. */
    private function rejectPending(Request $request, string $message): RedirectResponse
    {
        $request->session()->forget(['mfa.pending.id', 'mfa.pending.at', 'mfa.pending.attempts', 'mfa.pending.remember']);

        return redirect()->route('login')->with('flash', ['type' => 'error', 'message' => $message]);
    }

    /** The post-password login challenge (user is NOT yet authenticated; identity held in session). */
    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('mfa.pending.id')) {
            return redirect()->route('login');
        }
        if (! $this->pendingFresh($request)) {
            return $this->rejectPending($request, 'Your sign-in expired — please log in again.');
        }
        return Inertia::render('Auth/MfaChallenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $id = $request->session()->get('mfa.pending.id');
        if (! $id || ! ($user = User::find($id))) {
            return redirect()->route('login');
        }
        if (! $this->pendingFresh($request)) {
            return $this->rejectPending($request, 'Your sign-in expired — please log in again.');
        }
        // guess budget: 8 attempts per pending session, then back to the password step
        $attempts = (int) $request->session()->get('mfa.pending.attempts', 0) + 1;
        $request->session()->put('mfa.pending.attempts', $attempts);
        if ($attempts > self::MAX_ATTEMPTS) {
            return $this->rejectPending($request, 'Too many incorrect codes — please log in again.');
        }
        $request->validate(['code' => ['required', 'string']]);
        $input = trim($request->input('code'));

        $counter = $user->mfa_secret ? Totp::verifyWithCounter($user->mfa_secret, $input) : null;
        if ($counter !== null) {
            // replay guard: a code (time-step) accepted once cannot be reused within its window
            if ($user->mfa_last_counter !== null && $counter <= $user->mfa_last_counter) {
                throw ValidationException::withMessages(['code' => 'That code was already used — wait for the next one.']);
            }
            $user->update(['mfa_last_counter' => $counter]);
        } elseif (! $this->consumeRecoveryCode($user, $input)) {
            throw ValidationException::withMessages(['code' => 'Invalid authentication code.']);
        }

        Auth::login($user, (bool) $request->session()->get('mfa.pending.remember', false));
        $request->session()->forget(['mfa.pending.id', 'mfa.pending.at', 'mfa.pending.attempts', 'mfa.pending.remember']);
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
