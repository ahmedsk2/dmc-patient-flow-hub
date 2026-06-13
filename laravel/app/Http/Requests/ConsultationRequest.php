<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Consultation payload — one rule set for BOTH create and edit (they were duplicated verbatim).
 * authorize(): create AND edit are open to any clinical role (admin/registrar/consultant/
 * resident) — legacy had NO ownership check on modify (J1-10); sign-off and delete keep their
 * own gates in the controller. Observers are read-only regardless of capability flags.
 */
class ConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->user()->isObserver();
    }

    public function rules(): array
    {
        // legacy parity: the old form required ALL of these client-side — enforce server-side too.
        // EXCEPT age + consultation_date, which legacy validated only when present / never future-
        // bounded: age was NULL-allowed (legacy v_int_range skipped empties) and the date carried no
        // future bound (legacy v_date_ymd accepted any 2000–2100). Mirroring that here keeps imported
        // rows — null age, blank/old dates — editable. See age/consultation_date overrides below.
        $rules = [
            'mrn' => ['required', 'string', 'max:64'],
            'patient_name' => ['required', 'string', 'max:191'],
            // legacy validated age only when non-empty (NULL allowed) — keep the 0..150 cap on a value
            'age' => ['nullable', 'integer', 'between:0,150'],
            'bed' => ['required', 'string', 'max:64'],
            'current_location' => ['nullable', 'string', 'max:32'],
            // CREATE: required + no future date. UPDATE: validate-on-change (mirrors
            // ModifyAdmissionRequest) so an imported consultation with a blank/old date stays save-able
            // for an unrelated edit; a CHANGED date still cannot be in the future.
            'consultation_date' => $this->consultationDateRules(),
            'consultation_from' => ['required', 'string', 'max:128'],
            'to_service' => ['required', 'string', 'max:128'],
            // a receiving consultant only exists for an INTERNAL specialty; external / free-text
            // services store none (legacy shape — imported NULL-consultant rows stay re-savable)
            'consultant_id' => [$this->toServiceIsInternal() ? 'required' : 'nullable', 'exists:users,id'],
            'indication' => ['required', 'array', 'min:1'],
            'indication.*' => ['integer'],
            // legacy: free text becomes REQUIRED when the 'Other' indication (reason id 0) is selected
            'other_indication' => [
                \Illuminate\Validation\Rule::requiredIf(fn () => in_array(0, array_map('intval', (array) $this->input('indication', [])), true)),
                'nullable', 'string', 'max:255',
            ],
        ];

        return $rules;
    }

    /**
     * CREATE keeps the legacy-strict shape (required + not-future). On UPDATE the future bound is
     * relaxed to validate-on-change — an imported/historical consultation whose stored date is blank
     * or pre-2000 must stay save-able for an unrelated edit, but a date the user actually CHANGES
     * still cannot be in the future. Mirrors ModifyAdmissionRequest's validate-on-change pattern.
     */
    private function consultationDateRules(): array
    {
        $consultation = $this->route('consultation');   // route-model-bound only on update
        if ($consultation === null) {
            return ['required', 'date', 'before_or_equal:today'];   // CREATE
        }
        $stored = optional($consultation->consultation_date)->toDateString();
        $changed = (string) ($this->input('consultation_date') ?? '') !== (string) ($stored ?? '');

        return $changed
            ? ['required', 'date', 'before_or_equal:today']   // a changed date is still bounded
            : ['nullable', 'date'];                            // untouched stored date stays save-able
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
