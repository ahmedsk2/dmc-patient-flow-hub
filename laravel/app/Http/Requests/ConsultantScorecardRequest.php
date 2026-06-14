<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 3 — §3.1: date-range payload for the per-consultant scorecard PDF. Admin-only (authorize)
 * — the scorecard surfaces a named consultant's per-patient-derived KPIs over a range.
 */
class ConsultantScorecardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }
}
