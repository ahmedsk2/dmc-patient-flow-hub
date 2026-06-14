<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 3 — §3.7: year/month payload for the annual + monthly report screens and PDFs. Admin-only
 * (authorize). The `year` max is now()->year + 1 (a one-year lookahead) to avoid a Dec-31 timezone
 * race rejecting a valid current-year request — the controller still clamps an out-of-data year to
 * the most-recent year that actually has admissions.
 */
class ReportYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'min:2000', 'max:' . (now()->year + 1)],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}
