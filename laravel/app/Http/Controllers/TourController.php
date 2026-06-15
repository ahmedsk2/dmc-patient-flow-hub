<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Wave 2, Item 10: first-login onboarding tour. The single action stamps tour_completed_at so the
 * auto-tour never nags again. Idempotent (re-posting just re-stamps now()); NO audit row — this is a
 * UI preference, not PHI/clinical data. The "?" replay button never hits this endpoint.
 */
class TourController extends Controller
{
    public function complete(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['tour_completed_at' => now()])->save();

        return back();
    }
}
