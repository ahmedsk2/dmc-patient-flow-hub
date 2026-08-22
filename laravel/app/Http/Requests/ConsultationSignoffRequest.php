<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The sign-off payload. Sign-off is the moment the consulting team asserts its work is done, so it
 * now carries a structured response instead of being a bare click.
 *
 * authorize() keeps the EXISTING sign-off gate byte-for-byte: User::canManageConsultation(), which
 * refuses observers FIRST and then allows admin / can_manage / the receiving consultant. It lives
 * here rather than in the controller so the 403 still precedes any 422 — an unauthorized caller
 * learns nothing from validation. Coordinators (can_coordinate_consultations) are NOT part of that
 * predicate and are therefore refused: booking work into a team is not asserting the work is done.
 */
class ConsultationSignoffRequest extends FormRequest
{
    /** The agreed disposition vocabulary — the one definition, mirrored by the sign-off modal. */
    public const DISPOSITIONS = ['advice_given', 'taking_over', 'follow_up_arranged', 'no_further_input'];

    public function authorize(): bool
    {
        $consultation = $this->route('consultation');

        return $consultation instanceof Consultation
            && $this->user()->canManageConsultation($consultation);
    }

    public function rules(): array
    {
        return [
            'response_disposition' => ['required', 'string', Rule::in(self::DISPOSITIONS)],
            'response_followup_needed' => ['nullable', 'boolean'],
            'response_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'response_disposition.required' => 'Record what the team advised before signing off.',
            'response_disposition.in' => 'Choose one of the listed responses.',
        ];
    }
}
