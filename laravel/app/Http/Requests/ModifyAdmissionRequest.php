<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Modify-patient payload — the same demographics block as StoreAdmissionRequest, with the
 * MRN uniqueness check ignoring the patient being edited (route-bound admission).
 */
class ModifyAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin() || $this->user()->can_modify;   // Modify capability
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['mrn' => trim((string) $this->input('mrn'))]);
    }

    public function rules(): array
    {
        $rules = StoreAdmissionRequest::demographicRules();
        $rules['mrn'][] = Rule::unique('patients', 'mrn')->ignore($this->route('admission')->patient?->id);

        // data-correction fields (wrong admit date / source / location on the existing episode)
        $rules['admit_date'] = ['required', 'date', 'before_or_equal:today'];
        $rules['admitted_from'] = ['nullable', 'string', 'max:64'];
        $rules['current_location'] = ['required', 'in:ER,Ward,ICU'];

        return $rules;
    }
}
