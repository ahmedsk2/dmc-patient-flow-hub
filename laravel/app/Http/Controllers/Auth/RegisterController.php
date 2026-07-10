<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationCodeMail;
use App\Models\AuditLog;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public self-registration — 2026-07-11 auth-hardening: no account is created until the applicant
 * has verified a real email address (mid-form, via a mailed 6-digit code) AND confirmed a TOTP
 * authenticator, both tracked server-side in a `pending_registrations` row keyed by a random token
 * held in the session (`reg.token`) — never in a URL. New accounts are still created INACTIVE
 * (active = 0) and can never be admins — an administrator must activate them.
 *
 * The four step endpoints (send/verify/provision/confirm) are AJAX/JSON — they use the base
 * Controller's jsonValidate()/jsonFail() so errors are ALWAYS JSON (bootstrap/app.php restricts
 * Laravel's automatic exception-to-JSON rendering to `api/*` routes, and these aren't). `store()`
 * stays a normal Inertia-style redirect+session-errors submission, matching the original
 * single-step form it replaces.
 */
class RegisterController extends Controller
{
    private const CODE_TTL_MINUTES = 10;
    private const ROW_TTL_MINUTES = 30;
    private const MAX_SEND_COUNT = 5;
    private const MAX_VERIFY_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;

    public function show(): Response
    {
        return Inertia::render('Auth/Register', [
            'roles' => [2 => 'Registrar', 3 => 'Consultant', 4 => 'Resident', 5 => 'Observer'],
        ]);
    }

    /**
     * Step 1: validate the email, mint/reuse the pending row for this session, mail a fresh
     * 6-digit code. Rate-limited by a resend cooldown + a per-row send cap (in addition to the
     * route-level throttle:register).
     */
    public function sendEmailCode(Request $request): JsonResponse
    {
        $data = $this->jsonValidate($request, [
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
        ]);

        $pending = $this->currentPending($request);

        // reject a collision with ANOTHER in-flight (non-expired) pending registration — defense
        // in depth alongside the users-table uniqueness check above
        $collision = PendingRegistration::where('email', $data['email'])
            ->where('expires_at', '>', now())
            ->when($pending, fn ($q) => $q->where('id', '!=', $pending->id))
            ->exists();
        if ($collision) {
            $this->jsonFail(['email' => 'That email is already registered.']);
        }

        if (! $pending) {
            $token = Str::random(40);
            $request->session()->put('reg.token', $token);
            $pending = new PendingRegistration(['token' => $token]);
        }

        // Changing the target address mid-flow resets the email-OTP lifecycle (a new address must
        // be re-verified from scratch) but leaves any already-provisioned TOTP secret alone — the
        // authenticator isn't tied to the email, and `store()` re-checks the email match anyway.
        if ($pending->email !== $data['email']) {
            $pending->email_verified_at = null;
            $pending->email_send_count = 0;
            $pending->email_attempts = 0;
            $pending->email_sent_at = null;
        }

        if ($pending->email_sent_at && $pending->email_sent_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
            $this->jsonFail(['email' => 'Please wait before requesting another code.']);
        }
        if ($pending->email_send_count >= self::MAX_SEND_COUNT) {
            $this->jsonFail(['email' => 'Too many codes requested — please try again later.']);
        }

        $code = self::generateCode();
        $pending->fill([
            'email' => $data['email'],
            'email_code_hash' => Hash::make($code),
            'email_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'email_sent_at' => now(),
            'email_send_count' => $pending->email_send_count + 1,
            'email_attempts' => 0,
            'expires_at' => now()->addMinutes(self::ROW_TTL_MINUTES),
        ])->save();

        Mail::to($data['email'])->send(new RegistrationCodeMail($code));

        return response()->json(['sent' => true]);
    }

    /** Step 2: check the emailed code (≤5 attempts, then the caller must resend). */
    public function verifyEmailCode(Request $request): JsonResponse
    {
        $this->jsonValidate($request, ['code' => ['required', 'string']]);
        $pending = $this->pendingOrFailJson($request, 'code');

        if ($pending->email_attempts >= self::MAX_VERIFY_ATTEMPTS) {
            $this->jsonFail(['code' => 'Too many incorrect attempts — request a new code.']);
        }
        $pending->increment('email_attempts');
        $pending->refresh();

        $code = preg_replace('/\s+/', '', (string) $request->input('code'));
        $valid = $pending->email_code_hash
            && $pending->email_code_expires_at
            && ! $pending->email_code_expires_at->isPast()
            && Hash::check($code, $pending->email_code_hash);

        if (! $valid) {
            $this->jsonFail(['code' => 'Incorrect or expired code.']);
        }

        // clear the code on success so it can't be re-accepted within its remaining TTL (hygiene;
        // re-verification was idempotent but the code should be single-use)
        $pending->update(['email_verified_at' => now(), 'email_code_hash' => null, 'email_code_expires_at' => null]);

        return response()->json(['verified' => true]);
    }

    /**
     * Step 3: provision a TOTP secret + recovery codes once the email is verified. Idempotent —
     * calling again before confirmation returns the SAME secret rather than minting a new one.
     */
    public function provisionMfa(Request $request): JsonResponse
    {
        $pending = $this->pendingOrFailJson($request, 'email');
        if (! $pending->emailVerified()) {
            $this->jsonFail(['email' => 'Verify your email before setting up an authenticator.']);
        }

        if ($pending->totp_secret) {
            $secret = $pending->totp_secret;
            $codes = $pending->totp_recovery_codes ?? [];
        } else {
            $secret = Totp::secret();
            $codes = Totp::recoveryCodes();
            $pending->update(['totp_secret' => $secret, 'totp_recovery_codes' => $codes]);
        }

        return response()->json([
            'secret' => $secret,
            'otpauthUri' => Totp::uri($secret, $pending->email, config('app.name')),
            'recoveryCodes' => $codes,
        ]);
    }

    /** Step 4: confirm a code from the provisioned secret. */
    public function confirmMfa(Request $request): JsonResponse
    {
        $this->jsonValidate($request, ['code' => ['required', 'string']]);
        $pending = $this->pendingOrFailJson($request, 'code');

        if (! $pending->totp_secret) {
            $this->jsonFail(['code' => 'Set up the authenticator before confirming a code.']);
        }

        $counter = Totp::verifyWithCounter($pending->totp_secret, (string) $request->input('code'));
        if ($counter === null) {
            $this->jsonFail(['code' => 'That code is incorrect — check your authenticator and try again.']);
        }

        $pending->update(['totp_confirmed_at' => now()]);

        return response()->json(['confirmed' => true]);
    }

    /**
     * Final step: re-validate the whole form, then require BOTH factors verified on the pending
     * row (and that its email matches the submitted one — defense in depth) before creating the
     * account. Never admin (role restricted to 2..5); still created inactive pending admin
     * activation. Inertia-style: redirect + session-flashed errors, NOT JSON.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:240', 'unique:users,username'],
            'full_name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'role' => ['required', 'integer', 'in:2,3,4,5'],   // never admin via self-registration
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $pending = $this->pendingOrFailRedirect($request, 'email');
        if (! $pending->emailVerified() || ! $pending->totpConfirmed()) {
            throw ValidationException::withMessages([
                'email' => 'Please verify your email and confirm your authenticator app before creating an account.',
            ]);
        }
        if ($pending->email !== $data['email']) {
            throw ValidationException::withMessages(['email' => 'The verified email does not match — please verify this email address.']);
        }

        $user = User::create([
            'username' => $data['username'],
            'name' => $data['full_name'],
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $data['password'],          // hashed by the model cast
            'active' => 0,                            // pending admin activation
            'pass_exp_date' => now()->toDateString(),
            'email_verified_at' => now(),
            'mfa_secret' => $pending->totp_secret,
            'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $pending->totp_recovery_codes ?? []),
            'mfa_enrolled_at' => now(),
        ]);

        $pending->delete();
        $request->session()->forget('reg.token');

        AuditLog::create([
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'action' => 'user.self_register',
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
            'ip' => $request->ip(),
        ]);

        return redirect()->route('login')->with('flash', [
            'type' => 'success',
            'message' => 'Registered — an administrator must activate your account before you can sign in.',
        ]);
    }

    /** The pending row for this session's token, or null if there isn't one (yet). */
    private function currentPending(Request $request): ?PendingRegistration
    {
        $token = $request->session()->get('reg.token');

        return $token ? PendingRegistration::where('token', $token)->first() : null;
    }

    /** JSON-endpoint variant: fails closed (missing/expired) with a 422 JSON error. */
    private function pendingOrFailJson(Request $request, string $errorField): PendingRegistration
    {
        $pending = $this->currentPending($request);
        if (! $pending || $pending->isExpired()) {
            $this->jsonFail([$errorField => 'Your registration session has expired — please start again from your email address.']);
        }

        return $pending;
    }

    /** store()'s redirect-style variant: fails closed with a session-flashed validation error. */
    private function pendingOrFailRedirect(Request $request, string $errorField): PendingRegistration
    {
        $pending = $this->currentPending($request);
        if (! $pending || $pending->isExpired()) {
            throw ValidationException::withMessages([
                $errorField => 'Your registration session has expired — please start again from your email address.',
            ]);
        }

        return $pending;
    }

    private static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
