<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * New-admission payload. The patient-demographics rule block is shared with
 * ModifyAdmissionRequest — change the MRN/demographics policy in ONE place.
 * authorize() carries the role gate so a 403 precedes validation (Observers are read-only).
 */
class StoreAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (int) $this->user()->role !== \App\Models\User::ROLE_OBSERVER;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['mrn' => trim((string) $this->input('mrn'))]);   // strip stray whitespace before validation
    }

    /** Shared patient-demographics rules (MRN clean-data policy: digits only, ≤11). */
    public static function demographicRules(): array
    {
        return [
            'mrn' => ['required', 'string', 'regex:/^\d{1,11}$/'],
            'name' => ['required', 'string', 'max:191'],
            'age' => ['nullable', 'integer', 'between:0,130'],
            'gender' => ['nullable', 'in:Male,Female'],
            'nationality' => ['nullable', 'string', 'max:191'],
            'bed' => ['nullable', 'string', 'max:64'],
            'diagnoses' => ['array'],
            'diagnoses.*' => ['string', 'max:100'],
        ];
    }

    public function rules(): array
    {
        return self::demographicRules() + [
            'admit_date' => ['required', 'date', 'before_or_equal:today'],
            'admitted_from' => ['nullable', 'string', 'max:64'],
            'current_location' => ['required', 'in:ER,Ward,ICU'],
            'consultant_id' => ['nullable', 'exists:users,id'],
        ];
    }

    /**
     * Duplicate active-MRN guard (legacy parity: newpatients/dmc-patients-add.php) — a patient
     * cannot be admitted twice while an episode is still open (discharge_date IS NULL).
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v) {
            if ($v->errors()->has('mrn')) {
                return;   // MRN already rejected — don't stack a misleading duplicate message
            }
            $active = \App\Models\Admission::whereNull('discharge_date')
                ->whereHas('patient', fn ($q) => $q->where('mrn', $this->input('mrn')))
                ->exists();
            if ($active) {
                $v->errors()->add('mrn', 'This MRN already has an active admission.');
            }
        });
    }
}
