<?php

// laravel/app/Http/Controllers/StyleGuideController.php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Wave 0 — the internal design-system reference page. Renders the "Census Board" signature: the
 * colour tokens, the status-rail language, button hierarchy, status chips, FlowAlert callouts, a
 * form field, and a KPI numeral — in whichever theme the viewer has selected.
 *
 * Deliberately holds NO patient data and issues NO queries. Admin-gated because it is internal
 * tooling, not because it is sensitive.
 */
class StyleGuideController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('StyleGuide');
    }
}
