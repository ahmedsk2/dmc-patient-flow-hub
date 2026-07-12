<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026-07-11 auth-hardening: MFA is now MANDATORY for every authenticated user, unconditionally —
 * the settings.mfa_enforcement toggle can no longer switch this off (the column stays in the
 * schema for now — SecurityPanel/dashboard still read it for their own display — but it no longer
 * gates enrollment). Any authenticated user who hasn't enrolled is funnelled to /mfa/setup until
 * they do. The setup/confirm/logout/challenge routes are exempt so they can actually complete
 * (or leave) enrollment.
 *
 * The email.verify.* routes are ALSO exempt: EnsureEmailVerified runs BEFORE this gate and parks an
 * email-unverified user on /email/verify, but that user is (still) un-enrolled, so without this
 * exemption this gate would bounce them to /mfa/setup, which EnsureEmailVerified in turn bounces
 * back to /email/verify — an infinite redirect loop for anyone who is both email-unverified AND
 * un-enrolled (i.e. every freshly-imported legacy account). Letting email verification finish first
 * breaks the loop; once verified, EnsureEmailVerified stops redirecting and this gate takes over.
 */
class EnsureMfaEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->mfaEnabled()
            && ! $request->routeIs('mfa.setup', 'mfa.confirm', 'logout', 'mfa.challenge',
                'email.verify.show', 'email.verify.send', 'email.verify.confirm')) {
            return redirect()->route('mfa.setup')
                ->with('flash', ['type' => 'error', 'message' => 'Two-factor authentication is required — please enrol to continue.']);
        }

        return $next($request);
    }
}
