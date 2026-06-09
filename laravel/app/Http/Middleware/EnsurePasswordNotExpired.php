<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force a password change once it's older than 3 months (legacy policy). The user is funnelled to
 * their profile (which has the change-password form) until they set a new one; profile/password/
 * logout/mfa routes are exempt so they can actually complete it.
 */
class EnsurePasswordNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->pass_exp_date && now()->greaterThan($user->pass_exp_date->copy()->addMonths(3))) {
            if (! $request->routeIs('profile.edit', 'profile.update', 'profile.password', 'logout', 'mfa.*')) {
                return redirect()->route('profile.edit')
                    ->with('flash', ['type' => 'error', 'message' => 'Your password has expired — please set a new one to continue.']);
            }
        }

        return $next($request);
    }
}
