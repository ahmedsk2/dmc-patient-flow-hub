<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026-07-11 auth-hardening: an existing user whose email is ON FILE but UNVERIFIED must confirm
 * a code before doing anything else. Runs BEFORE mfa.enroll in the authenticated group (email
 * first, then MFA) — see routes/web.php.
 *
 * A NULL email is EXEMPT: these are legacy-imported accounts with no reachable address, so there
 * is no code we could send. They fall straight through to the MFA gate instead.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->email !== null && $user->email_verified_at === null
            && ! $request->routeIs('email.verify.show', 'email.verify.send', 'email.verify.confirm', 'logout', 'mfa.challenge')) {
            return redirect()->route('email.verify.show');
        }

        return $next($request);
    }
}
