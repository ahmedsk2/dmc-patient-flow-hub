<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Consultation payload — one rule set for BOTH create and edit (they were duplicated verbatim).
 * authorize() is route-aware: create = any non-Observer; edit = receiving consultant,
 * manager, or admin (the object-level ownership rule).
 */
class ConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();
        if ($c = $this->route('consultation')) {
            return $u->isAdmin() || $u->can_manage || (int) $c->consultant_id === (int) $u->id;
        }
        return (int) $u->role !== \App\Models\User::ROLE_OBSERVER;
    }

    public function rules(): array
    {
        return [
            'mrn' => ['required', 'string', 'max:64'],
            'patient_name' => ['required', 'string', 'max:191'],
            'age' => ['nullable', 'integer', 'between:0,130'],
            'bed' => ['nullable', 'string', 'max:64'],
            'current_location' => ['nullable', 'string', 'max:32'],
            'consultation_date' => ['required', 'date', 'before_or_equal:today'],
            'consultation_from' => ['nullable', 'string', 'max:128'],
            'to_service' => ['nullable', 'string', 'max:128'],
            'consultant_id' => ['nullable', 'exists:users,id'],
            'indication' => ['array'],
            'indication.*' => ['integer'],
            'other_indication' => ['nullable', 'string', 'max:255'],
        ];
    }
}
