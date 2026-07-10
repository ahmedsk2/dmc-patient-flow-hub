<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Prod-readiness closeout follow-up: server-side sink for CSP violation reports, so `report`
 * mode (and violations under `enforce`) produce an operator-visible signal instead of dying in
 * end-user browser consoles. Browsers POST these WITHOUT credentials or a CSRF token (the route
 * is CSRF-exempted in bootstrap/app.php and throttled at the route); the body is either the
 * legacy report-uri envelope {"csp-report": {...}} or a report-to JSON array. Log-only — no
 * storage, no PHI (reports carry URLs/directives, and our own URLs are PHI-free by SPC-TM-011).
 */
class CspReportController extends Controller
{
    /** Fields worth logging, per the CSP report schema (kebab-case as browsers send them). */
    private const FIELDS = [
        'document-uri', 'violated-directive', 'effective-directive',
        'blocked-uri', 'source-file', 'line-number', 'disposition',
    ];

    public function store(Request $request): Response
    {
        // Never 500 on garbage — a broken reporter must not generate error noise of its own.
        $raw = json_decode($request->getContent() ?: '', true);

        // Legacy envelope, report-to array, or a bare object — normalise to a list of reports.
        $reports = match (true) {
            is_array($raw) && isset($raw['csp-report']) => [$raw['csp-report']],
            is_array($raw) && array_is_list($raw) => array_column($raw, 'body'),
            is_array($raw) => [$raw],
            default => [],
        };

        foreach (array_slice(array_filter($reports, 'is_array'), 0, 5) as $report) {
            $context = [];
            foreach (self::FIELDS as $field) {
                // report-to uses camelCase field names; accept both spellings.
                $camel = lcfirst(str_replace('-', '', ucwords($field, '-')));
                $value = $report[$field] ?? $report[$camel] ?? null;
                if ($value !== null) {
                    $context[$field] = is_scalar($value) ? mb_substr((string) $value, 0, 300) : null;
                }
            }
            Log::warning('CSP violation reported', $context);
        }

        return response()->noContent();
    }
}
