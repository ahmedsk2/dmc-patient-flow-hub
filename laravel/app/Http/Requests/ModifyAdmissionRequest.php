<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Modify-patient payload — the same demographics block as StoreAdmissionRequest, but the
 * clean-data rules (MRN regex, gender enum, age bounds) apply ONLY when the submitted value
 * actually CHANGED (maintainer decision: ~1.4% dirty legacy records must stay editable — an
 * unrelated bed/date correction must never be blocked by already-stored data).
 *
 * There is deliberately NO unique-MRN rule: changing the MRN to another patient's MRN
 * RE-POINTS the admission to that patient (legacy duplicate-MRN-is-normal shape) — see
 * PatientActionController::modify().
 */
class ModifyAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Modify capability; Observers are read-only regardless of capability flags (J1-9)
        $u = $this->user();

        return ! $u->isObserver() && ($u->isAdmin() || $u->can_modify);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['mrn' => trim((string) $this->input('mrn'))]);
    }

    /** Did the submitted value change vs. what is stored on the patient being edited? */
    private function changed(string $field, mixed $stored): bool
    {
        return (string) ($this->input($field) ?? '') !== (string) ($stored ?? '');
    }

    public function rules(): array
    {
        $rules = StoreAdmissionRequest::demographicRules();
        $admission = $this->route('admission');
        $patient = $admission?->patient;

        // validate-only-on-change (legacy decision): untouched dirty values stay save-able
        if ($patient) {
            if (! $this->changed('mrn', $patient->mrn)) {
                $rules['mrn'] = ['required', 'string', 'max:64'];
            }
            $rules['age'] = $this->changed('age', $patient->age)
                ? ['nullable', 'integer', 'between:0,150']   // legacy cap (newpatients/patients v_int_range 0..150)
                : ['nullable'];
            if (! $this->changed('gender', $patient->gender)) {
                $rules['gender'] = ['nullable', 'string', 'max:32'];
            }
            // a CHANGED nationality must come from the countries list; untouched dirty stays
            if ($this->changed('nationality', $patient->nationality)) {
                $rules['nationality'] = ['nullable', 'string', 'max:191', 'exists:countries,name'];
            }
        }

        // data-correction fields (wrong admit date / source / location on the existing episode);
        // a CHANGED admitted_from must come from the legacy ADMFROM enum, untouched dirty stays
        $rules['admit_date'] = ['required', 'date', 'before_or_equal:today'];
        $rules['admitted_from'] = ($admission && $this->changed('admitted_from', $admission->admitted_from))
            ? ['nullable', 'string', 'max:64', 'in:' . implode(',', StoreAdmissionRequest::ADMIT_FROM)]
            : ['nullable', 'string', 'max:64'];
        $rules['current_location'] = ['required', 'in:ER,Ward,ICU'];
        // optional QUIET consultant change (legacy Modify semantics, J2-13) — must be a consultant
        $rules['consultant_id'] = ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')
            ->where('role', \App\Models\User::ROLE_CONSULTANT)];

        return $rules;
    }
}
