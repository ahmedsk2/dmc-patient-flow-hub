<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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

    /**
     * Accepts a USERNAME or an email in the same field (many clinical accounts only know their
     * username). A value without '@' is looked up by username and the account's email is used.
     * The response is the SAME generic status in every case — found or not, email on file or
     * not — so the endpoint cannot be used to enumerate accounts.
     */
    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'max:255']]);
        $value = trim((string) $request->input('email'));
        $email = str_contains($value, '@')
            ? $value
            : User::where('username', $value)->value('email');

        if ($email) {
            Password::sendResetLink(['email' => $email]);
        }

        return back()->with('status', 'If that account exists, a password reset link has been sent.');
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
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'pass_exp_date' => now()->toDateString(),
                    'remember_token' => Str::random(60),   // rotate any recaller (defense in depth)
                ])->save();
                // Kill every existing session for this account. A reset is the recovery action a
                // compromised user takes — a stolen live session cookie must NOT survive it.
                // (SESSION_DRIVER=database in prod; a no-op for other drivers.)
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('flash', ['type' => 'success', 'message' => 'Password reset — please sign in.'])
            : back()->withErrors(['email' => __($status)]);
    }
}
