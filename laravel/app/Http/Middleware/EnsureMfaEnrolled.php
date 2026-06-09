<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When MFA enforcement is on (settings.mfa_enforcement: 1 = admins, 2 = everyone), an in-scope user
 * who hasn't enrolled is funnelled to /mfa/setup until they do. The setup/confirm/logout routes are
 * exempt so they can actually complete (or leave) enrollment.
 */
class EnsureMfaEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->mfaEnabled()) {
            $level = (int) Setting::current()->mfa_enforcement;
            $inScope = $level === 2 || ($level === 1 && $user->isAdmin());

            if ($inScope && ! $request->routeIs('mfa.setup', 'mfa.confirm', 'logout', 'mfa.challenge')) {
                return redirect()->route('mfa.setup')
                    ->with('flash', ['type' => 'error', 'message' => 'Two-factor authentication is required — please enrol to continue.']);
            }
        }

        return $next($request);
    }
}
