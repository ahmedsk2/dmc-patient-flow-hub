<?php

namespace App\Http\Requests;

use App\Http\Controllers\ConsultationsController;
use App\Models\Patient;
use App\Models\Specialty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'to_service' => array_merge(['required', 'string', 'max:128'], $this->ownSpecialtyRule()),
            // a receiving consultant only exists for an INTERNAL specialty; external / free-text
            // services store none (legacy shape — imported NULL-consultant rows stay re-savable)
            'consultant_id' => [$this->toServiceIsInternal() ? 'required' : 'nullable', 'exists:users,id'],
            'indication' => ['required', 'array', 'min:1'],
            'indication.*' => ['integer'],
            // legacy: free text becomes REQUIRED when the 'Other' indication (reason id 0) is selected
            'other_indication' => [
                Rule::requiredIf(fn () => in_array(0, array_map('intval', (array) $this->input('indication', [])), true)),
                'nullable', 'string', 'max:255',
            ],
            // Wave 2b — patient lookup. The create form resolves the patient through
            // POST /api/patients/quick-search (term in the BODY — PHI never enters a URL) and posts
            // the chosen ids alongside the typed MRN. `unmatched_mrn_ack` is the user's explicit
            // "file it unlinked anyway" for an MRN that matches no patient record.
            // These three are CREATE-ONLY controls — ConsultationsController::update() strips them,
            // because relinking a filed consultation to a different patient is a re-identification,
            // not an edit.
            // `exists` is a RAW db check and is blind to SoftDeletes: without whereNull(deleted_at) a
            // trashed pick would validate here and then resolve to NULL in the controller, filing the
            // very silent orphan this guard exists to prevent.
            'patient_id' => ['nullable', 'integer',
                Rule::exists('patients', 'id')->whereNull('deleted_at')],
            'admission_id' => ['nullable', 'integer',
                Rule::exists('admissions', 'id')->whereNull('deleted_at')],
            'unmatched_mrn_ack' => ['nullable', 'boolean'],
        ];

        return $rules;
    }

    /**
     * CREATE-only guard against the silent orphan: an MRN matching no patient record used to store
     * `patient_id = NULL` without a word, producing a consultation nobody could ever join back to a
     * patient. The user must now either pick the patient from the lookup (`patient_id`) or tick the
     * acknowledgement. The point is the WARNING, not a block — acknowledging always succeeds.
     *
     * A PICKED patient must also BE the typed MRN's patient. The MRN is what a clinician reads on
     * the ledger; `patient_id` is what the row joins to. Letting a pick silently override the typed
     * MRN would make the two disagree about who the consultation is for — a quieter version of the
     * same defect.
     *
     * EDIT is deliberately exempt: 1,283 imported rows carry MRNs that match nothing and must stay
     * save-able for an unrelated edit (the same reasoning as consultationDateRules()).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->route('consultation') !== null) {
                return;                                  // update — legacy rows stay editable
            }
            if ($v->errors()->has('mrn')) {
                return;                                  // already failing on shape; don't pile on
            }
            $mrn = trim((string) $this->input('mrn'));

            if ($this->filled('patient_id')) {
                if ($v->errors()->has('patient_id')) {
                    return;                              // unknown / trashed pick — already refused
                }
                // the exists rule above guarantees a live row, so find() cannot be NULL here
                $picked = Patient::find((int) $this->input('patient_id'));
                if ($picked !== null && trim((string) $picked->mrn) !== $mrn) {
                    $v->errors()->add('mrn', "MRN {$mrn} is not the MRN of the patient you picked ({$picked->mrn}). Correct the MRN, or pick the patient this MRN belongs to.");
                }

                return;                                  // picked from the lookup
            }
            if ($this->boolean('unmatched_mrn_ack')) {
                return;                                  // acknowledged: file it unlinked
            }
            if (Patient::where('mrn', $mrn)->exists()) {
                return;                                  // the typed MRN does resolve
            }
            $v->errors()->add('mrn', "No patient record matches MRN {$mrn}. Pick the patient from the lookup, or tick 'Record anyway' to file this consultation without linking a patient record.");
        });
    }

    /**
     * W1 scoping: a consult belongs to the team it is booked into, so a plain clinical user may
     * only create into their OWN specialty. Coordinators (can_coordinate_consultations) and admins
     * may book into any specialty — that is exactly what the capability is for.
     *
     * On UPDATE the rule is VALIDATE-ON-CHANGE (the same idiom as consultationDateRules below): an
     * ordinary edit that leaves `to_service` alone keeps the legacy-open modify semantics (J1-10:
     * legacy modify carried no ownership check, pinned by Round5J1Test), but CHANGING the service is
     * a re-routing, and re-routing into a team you do not belong to is exactly the coordinator
     * capability. Without this, the create gate could simply be laundered through an edit — the
     * controller moves `owning_specialty_id` with the label, so the change is real, not cosmetic.
     * A wholesale reassignment UI still gets its own explicit action in W2.
     *
     * An unmatched / external / free-text service is unowned and stays creatable by anyone: those
     * referrals belong to no IM team and land in the Unassigned bucket.
     *
     * A user with NO specialty matches no team book. That refusal is deliberate — the way out is the
     * coordinator capability, which migration 2026_08_21_000500 grants at cutover to every
     * unaffiliated clinical account (on the real data, all 121 registrars and residents).
     *
     * @return array<int, \Closure>
     */
    private function ownSpecialtyRule(): array
    {
        $user = $this->user();
        if ($user->isAdmin() || $user->canCoordinateConsultations()) {
            return [];
        }

        $consultation = $this->route('consultation');   // route-model-bound only on update
        if ($consultation !== null
            && (string) $this->input('to_service') === (string) $consultation->to_service) {
            return [];   // UPDATE that does not touch the service — legacy-open modify, unchanged
        }

        return [function (string $attribute, mixed $value, \Closure $fail) use ($user) {
            $targetId = ConsultationsController::resolveOwningSpecialtyId((string) $value);
            if ($targetId === null) {
                return;   // external / free-text service — unowned
            }
            if ($user->specialty_id === null) {
                $fail('Your account is not attached to a specialty, so it cannot book a consultation into a team. Ask an administrator to set your specialty or to grant you the consultation-coordinator capability.');

                return;
            }
            if ((int) $user->specialty_id !== $targetId) {
                $fail('You may only book consultations for your own specialty. Ask a consultation coordinator to book this one.');
            }
        }];
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

        return Specialty::where('is_external', false)->pluck('name')
            ->contains(fn ($name) => mb_strtolower(trim($name)) === $wanted);
    }
}
