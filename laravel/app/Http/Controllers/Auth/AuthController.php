<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            $request->session()->put('mfa.pending.id', $user->id);
            $request->session()->put('mfa.pending.at', now()->getTimestamp());
            $request->session()->put('mfa.pending.attempts', 0);
            return redirect()->route('mfa.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
