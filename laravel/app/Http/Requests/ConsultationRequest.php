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
        // legacy parity: the old form required ALL of these client-side — enforce server-side too
        return [
            'mrn' => ['required', 'string', 'max:64'],
            'patient_name' => ['required', 'string', 'max:191'],
            'age' => ['required', 'integer', 'between:0,130'],
            'bed' => ['required', 'string', 'max:64'],
            'current_location' => ['nullable', 'string', 'max:32'],
            'consultation_date' => ['required', 'date', 'before_or_equal:today'],
            'consultation_from' => ['required', 'string', 'max:128'],
            'to_service' => ['required', 'string', 'max:128'],
            // a receiving consultant only exists for an INTERNAL specialty; external / free-text
            // services store none (legacy shape — imported NULL-consultant rows stay re-savable)
            'consultant_id' => [$this->toServiceIsInternal() ? 'required' : 'nullable', 'exists:users,id'],
            'indication' => ['required', 'array', 'min:1'],
            'indication.*' => ['integer'],
            'other_indication' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Does the submitted to_service name an INTERNAL specialty (case-insensitive)? */
    private function toServiceIsInternal(): bool
    {
        $wanted = mb_strtolower(trim((string) $this->input('to_service')));
        if ($wanted === '') {
            return false;
        }

        return \App\Models\Specialty::where('is_external', false)->pluck('name')
            ->contains(fn ($name) => mb_strtolower(trim($name)) === $wanted);
    }
}
