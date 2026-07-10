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
 */
class EnsureMfaEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->mfaEnabled()
            && ! $request->routeIs('mfa.setup', 'mfa.confirm', 'logout', 'mfa.challenge')) {
            return redirect()->route('mfa.setup')
                ->with('flash', ['type' => 'error', 'message' => 'Two-factor authentication is required — please enrol to continue.']);
        }

        return $next($request);
    }
}
