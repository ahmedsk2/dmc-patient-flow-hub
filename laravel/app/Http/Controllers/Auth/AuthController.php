<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Verify credentials (username, only ACTIVE accounts) WITHOUT logging in yet — so we can
        // interpose a second factor before establishing the session.
        $user = User::where('username', $data['username'])->where('active', 1)->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // Phase 4 — Item 3: record the failed attempt (actor_name = the submitted username; no
            // actor_id since identity is unconfirmed) and notify admins on a consecutive-failure run.
            AuditLog::create([
                'actor_id' => null,
                'actor_name' => $data['username'],
                'action' => 'login.failed',
                'entity_type' => 'user',
                'entity_id' => null,
                'details' => ['reason' => 'bad_credentials'],
                'ip' => $request->ip(),
            ]);
            $this->notifyOnFailureBurst($data['username'], $request->ip());

            throw ValidationException::withMessages([
                'username' => 'These credentials do not match an active account.',
            ]);
        }

        // If MFA is enrolled, hold the identity in the session and challenge for a code.
        // The pending identity is short-lived: a timestamp lets the challenge reject stale
        // sessions (5 min) and the attempt counter caps guesses (8) — see MfaController.
        // "Remember me" is deliberately NOT carried over: MFA logins re-authenticate every
        // session (K1-5) — the challenge always calls Auth::login($user, false).
        if ($user->mfaEnabled()) {
            // Trusted device (2026-07-19): a browser this user explicitly opted in on may skip the
            // CODE for a bounded window. Reached only AFTER the password verified above, and
            // resolved against THAT user — so a cookie belonging to someone else is inert here and
            // a stolen cookie without the password never gets this far.
            $hours = (int) (Setting::current()->mfa_trusted_device_hours ?? 0);
            $device = $hours > 0 ? TrustedDevice::resolve($request->cookie('dmc_trusted_device'), $user) : null;
            if ($device) {
                // last_used_at ONLY — the window is fixed from when trust was granted and is never
                // extended by use (owner decision; a sliding window would be a change here).
                $device->forceFill(['last_used_at' => now()])->save();

                // identical success sequence to MfaController::verifyChallenge, so session-timeout
                // behaviour is the same on both paths
                Auth::login($user, false);
                $request->session()->regenerate();
                $request->session()->put('session_started_at', now()->getTimestamp());
                $request->session()->put('last_activity_at', now()->getTimestamp());
                // mfa is recorded FALSE because no second factor was presented — claiming true
                // would be a lie in the record; trusted_device explains the legitimate skip.
                AuditLog::create([
                    'actor_id' => $user->id,
                    'actor_name' => $user->name,
                    'action' => 'login.success',
                    'entity_type' => 'user',
                    'entity_id' => (string) $user->id,
                    'details' => ['mfa' => false, 'trusted_device' => true],
                    'ip' => $request->ip(),
                ]);

                return redirect()->intended(route('dashboard'));
            }

            $request->session()->put('mfa.pending.id', $user->id);
            $request->session()->put('mfa.pending.at', now()->getTimestamp());
            $request->session()->put('mfa.pending.attempts', 0);
            return redirect()->route('mfa.challenge');
        }

        // 2026-07-11 auth-hardening: remember-me is DISABLED (always false) now that MFA is mandatory
        // for everyone. A recaller cookie auto-authenticates via Laravel's SessionGuard WITHOUT ever
        // hitting this controller or the MFA challenge — so a persistent-login cookie set in the brief
        // pre-enrolment grace window would let an (now-enrolled) user skip the second factor entirely.
        // Never issuing a recaller closes that bypass; the request's `remember` flag is ignored.
        Auth::login($user, false);
        $request->session()->regenerate();
        // Phase 4 — Item 2: stamp the session-start clock for the absolute-timeout check
        $request->session()->put('session_started_at', now()->getTimestamp());
        $request->session()->put('last_activity_at', now()->getTimestamp());
        // Phase 4 — Item 3: audit the successful (password-only) login; the MFA path audits its own
        // login.success in MfaController::verifyChallenge.
        AuditLog::create([
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'action' => 'login.success',
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
            'details' => ['mfa' => false],
            'ip' => $request->ip(),
        ]);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Phase 4 — Item 3: when the count of recent (last 10 min) login.failed rows for this username
     * EXACTLY reaches the admin-tunable threshold, raise ONE security.failed_logins notification for
     * every active admin. "Exactly equals" (not >=) so the alert fires once per burst, not on every
     * further attempt. Threshold 0 disables it. The login.failed audit row has already been written.
     */
    private function notifyOnFailureBurst(string $username, ?string $ip): void
    {
        $threshold = (int) (Setting::current()->failed_login_notify_threshold ?? 0);
        if ($threshold <= 0) {
            return;
        }
        // audit_log.created_at is written by the DB (useCurrent) in the DB session timezone, which
        // may differ from the app timezone — compare against the DB clock (NOW()) so the 10-minute
        // window is correct regardless of any app/DB timezone offset.
        $recentFailures = (int) DB::table('audit_log')
            ->where('action', 'login.failed')
            ->where('actor_name', $username)
            ->where('created_at', '>=', DB::raw('NOW() - INTERVAL 10 MINUTE'))
            ->count();
        if ($recentFailures !== $threshold) {
            return;
        }
        foreach (User::where('role', User::ROLE_ADMIN)->where('active', 1)->pluck('id') as $adminId) {
            Notification::create([
                'user_id' => $adminId,
                'type' => 'security.failed_logins',
                'created_at' => now(),
                'payload' => ['username' => $username, 'count' => $recentFailures, 'ip' => $ip],
            ]);
        }
    }
}
