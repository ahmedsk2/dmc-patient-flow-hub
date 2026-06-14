<?php

namespace App\Http\Requests;

use App\Models\Admission;
use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Phase 4 — Item 9: patient-merge payload. Admin-only. Both ids must reference a LIVE (not
 * soft-deleted) patient and must differ. The admin may optionally override individual demographic
 * fields on the TARGET (name/gender/age/nationality) — any field not submitted keeps the target's
 * existing value (the target is canonical by default; the admin explicitly picks the best spelling).
 *
 * Two cross-row guards run in withValidator so they reject as a clean 422/redirect-with-errors
 * BEFORE the merge() transaction opens:
 *   - merge-into-self (belt & braces on top of `different`) and
 *   - double-open: a SOURCE with an open episode merged into a TARGET that also has one would leave
 *     the patient with two simultaneously-open admissions (the data-quality "double open" canary).
 */
class PatientMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->isAdmin());
    }

    public function rules(): array
    {
        // exists-and-live: the SoftDeletes global scope does not apply to the `exists` rule (it hits
        // the raw table), so exclude trashed patients explicitly — a merged-away source is not a
        // valid merge operand.
        $livePatient = fn () => Rule::exists('patients', 'id')->whereNull('deleted_at');

        return [
            'source_id' => ['required', 'integer', $livePatient()],
            'target_id' => ['required', 'integer', 'different:source_id', $livePatient()],
            'canonical_demographics' => ['nullable', 'array'],
            'canonical_demographics.name' => ['nullable', 'string', 'max:191'],
            'canonical_demographics.gender' => ['nullable', 'string', 'max:32'],
            'canonical_demographics.age' => ['nullable', 'integer', 'between:0,150'],
            'canonical_demographics.nationality' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;   // ids already failed required/exists/different — no point on the cross-checks
            }
            $sourceId = (int) $this->input('source_id');
            $targetId = (int) $this->input('target_id');

            if ($sourceId === $targetId) {
                $v->errors()->add('target_id', 'A patient cannot be merged into itself.');

                return;
            }

            $sourceOpen = Admission::whereNull('discharge_date')->where('patient_id', $sourceId)->exists();
            $targetOpen = Admission::whereNull('discharge_date')->where('patient_id', $targetId)->exists();
            if ($sourceOpen && $targetOpen) {
                $v->errors()->add('source_id',
                    'Both patients have an open admission — merging would leave two simultaneously-open episodes. '
                    . 'Discharge or transfer one of them first.');
            }
        });
    }

    public function sourcePatient(): Patient
    {
        return Patient::findOrFail((int) $this->validated()['source_id']);
    }

    public function targetPatient(): Patient
    {
        return Patient::findOrFail((int) $this->validated()['target_id']);
    }
}
