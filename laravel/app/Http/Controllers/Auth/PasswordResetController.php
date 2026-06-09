<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forgot/reset password via Laravel's password broker. In dev the mailer is `log`, so the reset
 * link is written to storage/logs/laravel.log; in production point MAIL_* at the SMTP relay.
 */
class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => session('status')]);
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));

        return back()->with('status', __($status));
    }

    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            fn ($user) => $user->forceFill(['password' => Hash::make($request->password), 'pass_exp_date' => now()->toDateString()])->save()
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('flash', ['type' => 'success', 'message' => 'Password reset — please sign in.'])
            : back()->withErrors(['email' => __($status)]);
    }
}
