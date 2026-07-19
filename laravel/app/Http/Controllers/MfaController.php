<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\TrustedDevice;
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
        // 2026-07-11 auth-hardening: rotate the remember_token so ANY recaller cookie this user set
        // before enrolling (in the pre-MFA grace window) is invalidated — otherwise it would
        // auto-authenticate them on a future request WITHOUT the MFA challenge, defeating the second
        // factor. New logins never issue a recaller (AuthController), so this only clears stale ones.
        $user->setRememberToken(\Illuminate\Support\Str::random(60));
        $user->save();
        // Same reasoning for trusted devices: a waiver granted against the PREVIOUS second factor
        // must not carry over to the new one (2026-07-19 trusted-device spec, "Revocation").
        TrustedDevice::revokeAllFor($user->id);
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
        // The configured trusted-device window drives the opt-in checkbox: the page renders it only
        // when this is > 0, and interpolates the number into the label. 0 = feature off.
        return Inertia::render('Auth/MfaChallenge', [
            'trustedDeviceHours' => (int) (Setting::current()->mfa_trusted_device_hours ?? 0),
        ]);
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
        // trust_device is the opt-in trusted-device checkbox (2026-07-19) — absent/unticked is the default
        $request->validate(['code' => ['required', 'string'], 'trust_device' => ['nullable', 'boolean']]);
        $input = trim($request->input('code'));

        // This field accepts EITHER a TOTP code or a recovery code, and the two are NOT equivalent
        // credentials — a recovery code is single-use break-glass. Which one succeeded is carried
        // forward: it decides whether a device may be trusted, and it is recorded in the audit row.
        $viaRecovery = false;

        $counter = $user->mfa_secret ? Totp::verifyWithCounter($user->mfa_secret, $input) : null;
        if ($counter !== null) {
            // replay guard: a code (time-step) accepted once cannot be reused within its window
            if ($user->mfa_last_counter !== null && $counter <= $user->mfa_last_counter) {
                $this->auditFailed($user, 'replayed_mfa_code', $attempts);
                throw ValidationException::withMessages(['code' => 'That code was already used — wait for the next one.']);
            }
            $user->update(['mfa_last_counter' => $counter]);
        } elseif ($this->consumeRecoveryCode($user, $input)) {
            $viaRecovery = true;
            // Reaching for a backup code is the USER-side signal that the authenticator is lost or
            // compromised. The admin-side signal (ControlController::resetMfa) already revokes every
            // standing waiver; the user-side one must not be weaker, or a waiver granted against the
            // very factor that just failed the user would keep skipping MFA on someone else's browser.
            TrustedDevice::revokeAllFor($user->id);
        } else {
            // Phase 4 — Item 3: a bad MFA code is a login.failed (identity is already known here)
            $this->auditFailed($user, 'bad_mfa_code', $attempts);
            throw ValidationException::withMessages(['code' => 'Invalid authentication code.']);
        }

        // MFA logins are NEVER remembered (K1-5, legacy parity: MFA users re-authenticate each
        // session) — a remember-me cookie would let the second factor be bypassed for 30 days
        Auth::login($user, false);
        $request->session()->forget(['mfa.pending.id', 'mfa.pending.at', 'mfa.pending.attempts', 'mfa.pending.remember']);
        $request->session()->regenerate();
        // Phase 4 — Item 2: stamp the session-start clock (the Auth::login(.., false) behavior is preserved)
        $request->session()->put('session_started_at', now()->getTimestamp());
        $request->session()->put('last_activity_at', now()->getTimestamp());
        // Phase 4 — Item 3: a successful MFA login is a login.success (mfa:true) — consistent with
        // the password-only path so the Security panel sees both kinds of successful sign-in.
        // mfa stays true on BOTH paths (a second factor genuinely was presented); `via` distinguishes
        // them, so the trail can answer "was this login backed by the authenticator or a backup code?"
        AuditLog::create([
            'actor_id' => $user->id, 'actor_name' => $user->name, 'action' => 'login.success',
            'entity_type' => 'user', 'entity_id' => (string) $user->id,
            'details' => ['mfa' => true, 'via' => $viaRecovery ? 'recovery_code' : 'totp'], 'ip' => request()->ip(),
        ]);

        $redirect = redirect()->intended(route('dashboard'));

        // Trusted device (2026-07-19): waive the CODE — never the password — on THIS browser for a
        // fixed window. Granted only by an explicit tick, and only while the admin window is
        // non-zero (0 = feature off). The cookie carries "selector:validator"; EncryptCookies then
        // AES-encrypts it in transit as a free extra layer (it is not in $except).
        //
        // NEVER on the recovery path. A recovery code is a printed, non-expiring, single-use
        // credential kept near ward terminals; minting a multi-hour waiver from one would convert it
        // into a standing second factor for whoever holds the printout — the exact single-use →
        // reusable escalation mandatory MFA exists to prevent. Trust requires a real TOTP challenge.
        $hours = (int) (Setting::current()->mfa_trusted_device_hours ?? 0);
        if (! $viaRecovery && $request->boolean('trust_device') && $hours > 0) {
            $raw = TrustedDevice::issue($user, (string) $request->userAgent(), $request->ip(), $hours);
            // resolve the row by its (unique) selector rather than "the newest row" — deterministic
            // under concurrent logins, and it keeps issue()'s raw-string contract unchanged
            $device = TrustedDevice::where('selector', explode(':', $raw, 2)[0])->first();
            $redirect->withCookie(cookie()->make(
                'dmc_trusted_device', $raw, $hours * 60, '/', null, config('session.secure'), true, false, 'lax'
            ));
            // Granting a second-factor waiver is security-relevant in its own right. Never log the
            // selector or the validator — the audit trail is not a place for credential material.
            AuditLog::create([
                'actor_id' => $user->id, 'actor_name' => $user->name, 'action' => 'mfa.device_trusted',
                'entity_type' => 'user', 'entity_id' => (string) $user->id,
                'details' => [
                    'device_id' => $device?->id,
                    'label' => $device?->label,
                    'expires_at' => optional($device?->expires_at)->toIso8601String(),
                ],
                'ip' => request()->ip(),
            ]);
        } elseif ($viaRecovery && $request->boolean('trust_device') && $hours > 0) {
            // Refusing the tick silently would leave the user believing this browser is trusted and
            // expecting no prompt next time. Say so, and say what to do about it.
            $redirect->with('flash', ['type' => 'error',
                'message' => 'This device was not trusted — a recovery code cannot authorise it. Sign in with your authenticator app to trust this browser.']);
        }

        return $redirect;
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

    /** Phase 4 — Item 3: a failed MFA challenge — identity is known, so actor_id is populated. */
    private function auditFailed(User $user, string $reason, int $attempts): void
    {
        AuditLog::create([
            'actor_id' => $user->id, 'actor_name' => $user->name, 'action' => 'login.failed',
            'entity_type' => 'user', 'entity_id' => (string) $user->id,
            'details' => ['reason' => $reason, 'attempts' => $attempts], 'ip' => request()->ip(),
        ]);
    }
}
