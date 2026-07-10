<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationCodeMail;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 2026-07-11 auth-hardening: the existing-user counterpart to the registration email-OTP. Reached
 * via the `email.verify` middleware gate when a logged-in user's on-file email is unverified. The
 * live code is held in the SESSION only (`email.verify.*`) — never persisted on the user row —
 * since the user account already exists; on success it stamps `users.email_verified_at`.
 */
class EmailVerificationController extends Controller
{
    private const CODE_TTL_MINUTES = 10;
    private const MAX_VERIFY_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;

    public function show(Request $request): Response
    {
        return Inertia::render('Auth/EmailVerify', [
            'maskedEmail' => self::mask((string) $request->user()->email),
        ]);
    }

    /** AJAX/JSON — see Controller::jsonFail() for why this can't just throw ValidationException. */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->email) {
            $this->jsonFail(['code' => 'No email is on file for your account.']);
        }

        $sentAt = $request->session()->get('email.verify.sent_at');
        if ($sentAt && (now()->getTimestamp() - (int) $sentAt) < self::RESEND_COOLDOWN_SECONDS) {
            $this->jsonFail(['code' => 'Please wait before requesting another code.']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $request->session()->put('email.verify.code_hash', Hash::make($code));
        $request->session()->put('email.verify.expires', now()->addMinutes(self::CODE_TTL_MINUTES)->getTimestamp());
        $request->session()->put('email.verify.sent_at', now()->getTimestamp());
        $request->session()->put('email.verify.attempts', 0);

        Mail::to($user->email)->send(new RegistrationCodeMail($code));

        return response()->json(['sent' => true]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $attempts = (int) $request->session()->get('email.verify.attempts', 0);
        if ($attempts >= self::MAX_VERIFY_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => 'Too many incorrect attempts — request a new code.']);
        }
        $request->session()->put('email.verify.attempts', $attempts + 1);

        $hash = $request->session()->get('email.verify.code_hash');
        $expires = $request->session()->get('email.verify.expires');
        $code = preg_replace('/\s+/', '', (string) $request->input('code'));

        $valid = $hash && $expires && now()->getTimestamp() <= (int) $expires && Hash::check($code, $hash);
        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'Incorrect or expired code.']);
        }

        $user = $request->user();
        $user->update(['email_verified_at' => now()]);
        $request->session()->forget(['email.verify.code_hash', 'email.verify.expires', 'email.verify.sent_at', 'email.verify.attempts']);
        Audit::log('email.verified', 'user', (string) $user->id);

        return redirect()->intended(route('dashboard'));
    }

    /** j***@example.com — first char + fixed-length mask + domain, so nothing else leaks. */
    private static function mask(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 1);

        return $visible . str_repeat('*', max(mb_strlen($local) - 1, 1)) . '@' . $domain;
    }
}
