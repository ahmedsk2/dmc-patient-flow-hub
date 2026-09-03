<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        // Phase 4 — Item 5: ICD-10 validate-on-change. demographicRules() applies Rule::exists on
        // every diagnosis code; relax it here UNLESS the submitted set introduces a NEW code vs. the
        // stored set — so an unrelated bed/date correction never trips on pre-existing dirty codes,
        // but adding a brand-new code is validated against icd10. (Mirrors the mrn/age/gender
        // validate-on-change policy below.)
        $storedCodes = $admission ? $admission->diagnoses->pluck('icd10_code')->all() : [];
        $submitted = array_map(fn ($c) => trim((string) $c), (array) $this->input('diagnoses', []));
        $newCodes = array_diff($submitted, $storedCodes);
        $rules['diagnoses.*'] = count($newCodes) > 0
            ? ['string', 'max:100', Rule::exists('icd10', 'code')]
            : ['string', 'max:100'];

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
            ? ['nullable', 'string', 'max:64', 'in:'.implode(',', StoreAdmissionRequest::ADMIT_FROM)]
            : ['nullable', 'string', 'max:64'];
        $rules['current_location'] = ['required', 'in:ER,Ward,ICU'];
        // optional QUIET consultant change (legacy Modify semantics, J2-13). The consultant-role
        // constraint applies ONLY when the value actually CHANGED (validate-on-change, like
        // mrn/age/gender above): the Modify modals round-trip the CURRENT assignee, and a patient
        // legitimately held by a registrar/resident (assign-to-me, K1-9) must stay editable for an
        // unrelated bed/date correction — an unchanged consultant_id always passes (N1-1).
        $rules['consultant_id'] = ($admission && $this->changed('consultant_id', $admission->consultant_id))
            ? ['nullable', Rule::exists('users', 'id')
                ->where('role', User::ROLE_CONSULTANT)]
            : ['nullable', 'exists:users,id'];

        return $rules;
    }
}
