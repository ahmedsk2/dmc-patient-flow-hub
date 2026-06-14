<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 4 — Item 4: step-up re-authentication gate for the highest-risk destructive actions
 * (admission delete + reverse-discharge; user delete; role->0 grant is gated inline in
 * ControlController since its route is shared with non-escalating updates). If the session carries
 * a step-up verified within the last 5 minutes the request passes; otherwise the intended URL is
 * stored and the user is bounced to the step-up form.
 */
class RequireStepUp
{
    public const WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $verifiedAt = $request->session()->get('stepup.verified_at');
        if ($verifiedAt && (now()->getTimestamp() - (int) $verifiedAt) <= self::WINDOW_SECONDS) {
            return $next($request);
        }

        // remember where to return — the original action route (method preserved by the form replay)
        $request->session()->put('stepup.intended', $request->fullUrl());
        $request->session()->put('stepup.intended_method', $request->method());

        return redirect()->route('stepup.show');
    }
}
