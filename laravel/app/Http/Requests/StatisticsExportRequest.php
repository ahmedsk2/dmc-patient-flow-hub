<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 3 — §3.4: filter payload for the Statistics XLSX/PDF exports. Mirrors the inline validate
 * in StatisticsController::index, lifted to a FormRequest. Admin-only (authorize) — the export
 * surfaces the same aggregate KPIs as the admin-gated Statistics screen.
 */
class StatisticsExportRequest extends FormRequest
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
            'interval' => ['nullable', 'in:day,month,quarter'],
            'consultant_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
