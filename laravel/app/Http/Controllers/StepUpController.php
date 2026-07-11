<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 4 — Item 4: step-up re-authentication. The user re-enters their password (and a current
 * TOTP code if MFA-enrolled — belt & suspenders) to refresh a 5-minute step-up window held in the
 * session. The window is consumed by RequireStepUp (route middleware) and the inline role-escalation
 * guard in ControlController. A successful step-up writes a `stepup.verified` audit row.
 */
class StepUpController extends Controller
{
    private const MAX_ATTEMPTS = 8;   // per session — matches the MFA challenge budget

    public function show(): Response
    {
        return Inertia::render('Auth/StepUp', [
            // the intended action's URL/label is purely informational on the form
            'intended' => session('stepup.intended'),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']]);

        // guess budget: cap in-session attempts (mirrors the MFA challenge) so a hijacked session
        // can't brute-force the step-up password/TOTP; throttle:stepup bounds the per-minute rate.
        $attempts = (int) $request->session()->get('stepup.attempts', 0) + 1;
        $request->session()->put('stepup.attempts', $attempts);
        if ($attempts > self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['password' => 'Too many attempts — please wait a moment and try again.']);
        }

        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'That password is incorrect.']);
        }

        // MFA-enrolled users must ALSO provide a current TOTP code (with replay protection)
        if ($user->mfaEnabled()) {
            $request->validate(['code' => ['required', 'string']]);
            $counter = Totp::verifyWithCounter($user->mfa_secret, (string) $request->input('code'));
            if ($counter === null) {
                throw ValidationException::withMessages(['code' => 'That authentication code is incorrect.']);
            }
            if ($user->mfa_last_counter !== null && $counter <= $user->mfa_last_counter) {
                throw ValidationException::withMessages(['code' => 'That code was already used — wait for the next one.']);
            }
            $user->update(['mfa_last_counter' => $counter]);
        }

        $request->session()->forget('stepup.attempts');   // clear the guess budget on success
        $request->session()->put('stepup.verified_at', now()->getTimestamp());
        Audit::log('stepup.verified', 'user', (string) Auth::id());

        $intended = $request->session()->pull('stepup.intended');
        $method = $request->session()->pull('stepup.intended_method', 'GET');
        $request->session()->forget('stepup.intended_method');

        // a GET intended (e.g. the Control panel for role escalation) can be returned to directly; a
        // non-GET action (DELETE/POST) cannot be replayed by a redirect, so land on a safe page and
        // let the user re-trigger it — the step-up window is now open for the next 5 minutes.
        $target = ($intended && $method === 'GET') ? $intended : route('control.index');

        return redirect($target)->with('flash', [
            'type' => 'success', 'message' => 'Identity confirmed — you can complete the action now.',
        ]);
    }
}
