<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 3 — §3.2: month/quarter selection for the Governance / M&M pack PDF. Admin-only
 * (authorize). The pack is de-identified (MRN only, no patient name), so it sits in the same
 * admin audience as the rest of the reporting/export surface.
 */
class GovernanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'period_type' => ['required', 'in:month,quarter'],
            'year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
            'month' => ['required_if:period_type,month', 'nullable', 'integer', 'min:1', 'max:12'],
            'quarter' => ['required_if:period_type,quarter', 'nullable', 'integer', 'min:1', 'max:4'],
        ];
    }
}
