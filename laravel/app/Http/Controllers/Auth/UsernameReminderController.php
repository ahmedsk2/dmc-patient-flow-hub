<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\UsernameReminderMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot username" — mirrors PasswordResetController's anti-enumeration pattern: the response is
 * the SAME generic flash whether or not the email matches an active account, so the endpoint
 * cannot be used to enumerate accounts.
 */
class UsernameReminderController extends Controller
{
    public const GENERIC_MESSAGE = "If that email matches an active account, we've sent the username to it.";

    public function request(): Response
    {
        return Inertia::render('Auth/ForgotUsername');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        $user = User::where('email', strtolower(trim($request->input('email'))))
            ->where('active', 1)
            ->first();

        if ($user) {
            Mail::to($user->email)->send(new UsernameReminderMail($user->username));

            AuditLog::create([
                'actor_id' => $user->id,
                'actor_name' => $user->name,
                'action' => 'username.reminder.sent',
                'entity_type' => 'user',
                'entity_id' => (string) $user->id,
                'details' => ['email' => $user->email],
                'ip' => $request->ip(),
            ]);
        }

        return back()->with('status', self::GENERIC_MESSAGE);
    }
}
