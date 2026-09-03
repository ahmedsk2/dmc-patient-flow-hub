<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Public legal pages. GET /privacy is reachable with NO session (patients and visitors are not
 * users of the hub) and equally by a signed-in staff member — so it is registered outside both the
 * guest and auth route groups. It carries no PHI; the whole notice is data from
 * resources/lang/{en,ar}/privacy.php, so wording changes never touch code.
 */
class LegalController extends Controller
{
    public function privacy(): Response
    {
        return Inertia::render('Legal/Privacy', [
            'en' => trans('privacy', [], 'en'),
            'ar' => trans('privacy', [], 'ar'),
        ]);
    }
}
