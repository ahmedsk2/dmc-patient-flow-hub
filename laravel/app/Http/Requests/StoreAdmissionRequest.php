<?php

namespace App\Http\Requests;

use App\Models\Admission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * New-admission payload. The patient-demographics rule block is shared with
 * ModifyAdmissionRequest — change the MRN/demographics policy in ONE place.
 * authorize() carries the capability gate so a 403 precedes validation.
 */
class StoreAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Can-Add capability (legacy add_new_patient flag) — and Observers are read-only
        // regardless of any capability flag set on the account (J1-3 / J1-9).
        $u = $this->user();

        return ! $u->isObserver() && ($u->isAdmin() || $u->can_add);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['mrn' => trim((string) $this->input('mrn'))]);   // strip stray whitespace before validation
    }

    /** The legacy ADMFROM vocabulary — the Create select and (on change) the Modify datalist. */
    public const ADMIT_FROM = ['ER', 'Clinic', 'OPD', 'OR', 'ICU', 'Referral', 'Transfer', 'Direct', 'Other service'];

    /** Shared patient-demographics rules (MRN clean-data policy: digits only, ≤11). */
    public static function demographicRules(): array
    {
        return [
            'mrn' => ['required', 'string', 'regex:/^\d{1,11}$/'],
            'name' => ['required', 'string', 'max:191'],
            'age' => ['nullable', 'integer', 'between:0,150'],
            'gender' => ['nullable', 'in:Male,Female'],
            'nationality' => ['nullable', 'string', 'max:191'],
            'bed' => ['nullable', 'string', 'max:64'],
            'diagnoses' => ['array'],
            // Phase 4 — Item 5: each diagnosis code must exist in the icd10 reference table. Modify
            // (validate-on-change) relaxes this for unchanged dirty legacy codes — see
            // ModifyAdmissionRequest::rules(); NEW admissions enforce it fully (rules() below).
            'diagnoses.*' => ['string', 'max:100', Rule::exists('icd10', 'code')],
        ];
    }

    /**
     * NEW admissions enforce the legacy Fill-All policy: age, gender, nationality, bed and at
     * least one diagnosis are REQUIRED, and nationality/admitted_from come from controlled
     * vocabularies (countries table / legacy ADMFROM enum). Modify keeps the relaxed
     * demographicRules() + validate-only-on-change so dirty legacy records stay editable.
     */
    public function rules(): array
    {
        return array_merge(self::demographicRules(), [
            'age' => ['required', 'integer', 'between:0,150'],
            'gender' => ['required', 'in:Male,Female'],
            'nationality' => ['required', 'string', 'max:191', 'exists:countries,name'],
            'bed' => ['required', 'string', 'max:64'],
            'diagnoses' => ['required', 'array', 'min:1'],
            'diagnoses.*' => ['required', 'string', 'max:100', Rule::exists('icd10', 'code')],   // Phase 4 — Item 5: full ICD-10 enforcement on new admissions
            'admit_date' => ['required', 'date', 'before_or_equal:today'],
            'admitted_from' => ['nullable', 'string', 'max:64', 'in:'.implode(',', self::ADMIT_FROM)],
            'current_location' => ['required', 'in:ER,Ward,ICU'],
            'consultant_id' => ['nullable', 'exists:users,id'],
        ]);
    }

    public function messages(): array
    {
        return [
            'diagnoses.required' => 'Add at least one admission diagnosis.',
            'diagnoses.min' => 'Add at least one admission diagnosis.',
            'nationality.exists' => 'Pick a nationality from the list.',
        ];
    }

    /**
     * Duplicate active-MRN guard (legacy parity: newpatients/dmc-patients-add.php) — a patient
     * cannot be admitted twice while an episode is still open (discharge_date IS NULL).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->has('mrn')) {
                return;   // MRN already rejected — don't stack a misleading duplicate message
            }
            $active = Admission::whereNull('discharge_date')
                ->whereHas('patient', fn ($q) => $q->where('mrn', $this->input('mrn')))
                ->exists();
            if ($active) {
                $v->errors()->add('mrn', 'This MRN already has an active admission.');
            }
        });
    }
}
