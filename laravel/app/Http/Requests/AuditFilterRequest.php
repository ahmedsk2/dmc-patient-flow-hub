<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 2 — Item 1: filter payload for the admin audit-log viewer + its Excel/CSV export.
 * Admin-only (authorize) — the viewer surfaces actor identity, IPs and PHI-bearing detail JSON,
 * so it is gated to the exact audience of the rest of the admin route group.
 */
class AuditFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:64'],
            'entity_type' => ['nullable', 'string', 'max:64'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'ip' => ['nullable', 'string', 'max:45'],
        ];
    }
}
