<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\HandoverRevision;
use App\Models\HandoverSignature;
use App\Models\Notification;
use App\Models\User;
use App\Services\ShuffleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Patient-flow transitions on an active admission. Every action enforces authorization SERVER-SIDE
 * (the legacy app left these UI-only), runs in a transaction where multiple rows change, records the
 * acting user from the SESSION (never client-supplied), and writes an audit_log entry.
 */
class PatientActionController extends Controller
{
    private function audit(string $action, Admission $a, array $details = []): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(), 'actor_name' => Auth::user()->name,
            'action' => $action, 'entity_type' => 'admission', 'entity_id' => (string) $a->id,
            'details' => $details, 'ip' => request()->ip(),
        ]);
    }

    private function canManage(Admission $a): bool
    {
        $u = Auth::user();
        return $u->isAdmin() || $u->can_manage || (int) $a->consultant_id === (int) $u->id;
    }

    // ---- same-day handover gate (consultant-changing moves only) --------------------------------

    /**
     * A patient may only move to a DIFFERENT consultant when their handover was updated TODAY —
     * no admin override. First assignments, shuffle, self-assign, ICU pull, location/external
     * transfers and discharges are NOT gated.
     */
    private function assertHandoverToday(Admission $a): void
    {
        if (! Handover::updatedToday($a->id)) {
            throw ValidationException::withMessages(['handover' => 'Handover must be updated today before transfer.']);
        }
    }

    /**
     * Record the receiving consultant's pending signature for a gated transfer (call INSIDE the
     * transfer transaction): supersede any prior pending signature for the admission, then pin
     * the latest handover revision at transfer time.
     */
    private function createHandoverSignature(Admission $a, ?int $fromId, int $toId): HandoverSignature
    {
        HandoverSignature::voidPendingFor($a->id);

        return HandoverSignature::create([
            'admission_id' => $a->id,
            'from_consultant_id' => $fromId,
            'to_consultant_id' => $toId,
            'revision_id' => HandoverRevision::latestIdFor($a->id),
            'required_at' => now(),
        ]);
    }

    /** Display name for notification payloads. */
    private function consultantName(?int $id): ?string
    {
        $u = $id ? User::find($id) : null;

        return $u ? ($u->full_name ?: $u->name) : null;
    }

    /** Discharge/delete closes the episode — a still-unsigned handover signature is moot. */
    private function voidPendingSignatures(Admission $a): void
    {
        HandoverSignature::voidPendingFor($a->id);
    }

    /**
     * Full edit of an admission's patient demographics + diagnoses (Modify capability).
     *
     * Duplicate-MRN repoint (legacy duplicate-MRN-is-normal shape): when the MRN is CHANGED to
     * another existing patient's MRN, the admission is RE-POINTED to that patient instead of
     * failing a uniqueness check; demographics then update THAT patient only where the user
     * deliberately changed them (vs. the record loaded into the form).
     */
    public function modify(\App\Http\Requests\ModifyAdmissionRequest $request, Admission $admission): RedirectResponse
    {
        // capability gate lives in ModifyAdmissionRequest::authorize() (403 before validation)
        $patient = $admission->patient;
        $data = $request->validated();

        $target = ((string) $data['mrn'] !== (string) $patient->mrn)
            ? \App\Models\Patient::where('mrn', $data['mrn'])->where('id', '<>', $patient->id)->first()
            : null;

        // data correction may move the admit date — keep the old one in the audit trail
        $oldAdmitDate = optional($admission->admit_date)->toDateString();
        $newAdmitDate = \Carbon\Carbon::parse($data['admit_date'])->toDateString();

        DB::transaction(function () use ($admission, $patient, $target, $data, $newAdmitDate) {
            if ($target) {
                $admission->update(['patient_id' => $target->id]);
                $this->audit('patient.repoint', $admission, [
                    'from_patient_id' => $patient->id, 'to_patient_id' => $target->id, 'mrn' => $target->mrn,
                ]);
                // only deliberately-changed fields propagate — the form was prefilled from the
                // ORIGINAL patient, so unchanged values must not clobber the target's record
                $changes = [];
                foreach (['name', 'age', 'gender', 'nationality'] as $field) {
                    $submitted = $data[$field] ?? null;
                    if ((string) ($submitted ?? '') !== (string) ($patient->{$field} ?? '')) {
                        $changes[$field] = $submitted;
                    }
                }
                if ($changes) {
                    $target->update($changes);
                }
            } else {
                $patient->update([
                    'mrn' => $data['mrn'], 'name' => $data['name'], 'age' => $data['age'] ?? null,
                    'gender' => $data['gender'] ?? null, 'nationality' => $data['nationality'] ?? null,
                ]);
            }
            $admission->update([
                'bed' => $data['bed'] ?? null,
                'admit_date' => $newAdmitDate,
                'admitted_from' => $data['admitted_from'] ?? null,
                'current_location' => $data['current_location'],
            ]);
            $admission->diagnoses()->delete();
            $seq = 1;
            foreach (array_unique(array_filter(array_map('trim', $data['diagnoses'] ?? []))) as $code) {
                $admission->diagnoses()->create(['seq' => $seq++, 'icd10_code' => $code]);
            }
        });
        $details = ['mrn' => $data['mrn']];
        if ($oldAdmitDate !== $newAdmitDate) {
            $details['admit_date_was'] = $oldAdmitDate;
        }
        $this->audit('patient.modify', $admission, $details);

        return back()->with('flash', ['type' => 'success', 'message' => 'Patient details updated.']);
    }

    /** Self-assign — any consultant may take an admission onto their own list. */
    public function assignToMe(Request $request, Admission $admission): RedirectResponse
    {
        $u = Auth::user();
        if (! ($u->isAdmin() || (int) $u->role === User::ROLE_CONSULTANT)) {
            throw new AccessDeniedHttpException('Only a consultant can self-assign.');
        }
        $admission->update(['consultant_id' => $u->id, 'is_new_assignment' => true, 'assigned_on' => now()->toDateString(), 'assigned_at' => now()]);
        $this->audit('admission.assign_to_me', $admission, ['consultant_id' => $u->id]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Assigned to you.']);
    }

    /** Label / unlabel long-term (any clinical role; observers are read-only). */
    public function toggleLongterm(Request $request, Admission $admission): RedirectResponse
    {
        if ((int) Auth::user()->role === User::ROLE_OBSERVER) {
            throw new AccessDeniedHttpException('Observers are read-only.');
        }
        $admission->update(['is_longterm' => ! $admission->is_longterm]);
        $this->audit('admission.longterm', $admission, ['is_longterm' => $admission->is_longterm]);

        return back()->with('flash', ['type' => 'success', 'message' => $admission->is_longterm ? 'Marked long-term.' : 'Long-term label removed.']);
    }

    /**
     * Bulk reassign active admissions from one consultant to another. The preflight modal lists
     * the consultant's patients with per-patient checkboxes — `admission_ids[]` selects the
     * SUBSET to move (omitted = legacy move-everything). Every submitted id must be an ACTIVE
     * admission of the from-consultant; the handover gate + signatures apply per selected patient.
     */
    public function bulkReassign(Request $request): RedirectResponse
    {
        $u = Auth::user();
        if (! ($u->isAdmin() || $u->can_assign || $u->can_manage)) {
            throw new AccessDeniedHttpException('Requires the Assign or Manage capability.');
        }
        $data = $request->validate([
            'from_consultant_id' => ['required', 'exists:users,id'],
            'to_consultant_id' => ['required', 'exists:users,id', 'different:from_consultant_id'],
            'mark_new' => ['nullable', 'boolean'],
            'admission_ids' => ['nullable', 'array'],
            'admission_ids.*' => ['integer'],
        ]);
        // mark_new=false (legacy "New Patient?" unchecked) = quiet administrative move:
        // the new-assignment fields are left UNTOUCHED, preserving any existing assigned_at
        $markNew = $request->boolean('mark_new', true);

        $moving = Admission::whereNull('discharge_date')->where('consultant_id', $data['from_consultant_id'])->get();

        // subset selection: every submitted id must belong to the from-consultant AND be active
        if (array_key_exists('admission_ids', $data) && $data['admission_ids'] !== null) {
            $selected = collect($data['admission_ids'])->map(fn ($id) => (int) $id)->unique();
            $owned = $moving->pluck('id')->flip();
            if ($selected->contains(fn ($id) => ! $owned->has($id))) {
                throw ValidationException::withMessages([
                    'admission_ids' => 'Every selected patient must be an active patient of the outgoing consultant.',
                ]);
            }
            $moving = $moving->whereIn('id', $selected->all())->values();
        }

        // every SELECTED patient needs a handover updated TODAY (use the preflight endpoint /
        // bulk modal editors to bring them current before confirming)
        $freshIds = Handover::whereIn('admission_id', $moving->pluck('id'))
            ->whereDate('updated_at', today())->pluck('admission_id')->flip();
        if ($moving->contains(fn ($a) => ! $freshIds->has($a->id))) {
            throw ValidationException::withMessages(['handover' => 'Handover must be updated today before transfer.']);
        }

        [$count, $sigIds] = DB::transaction(function () use ($data, $markNew, $moving) {
            $count = Admission::whereIn('id', $moving->pluck('id'))
                ->update(['consultant_id' => $data['to_consultant_id']]
                    + ($markNew ? ['is_new_assignment' => true, 'assigned_on' => now()->toDateString(), 'assigned_at' => now()] : []));
            // one signature per moved admission, ONE notification for the receiving consultant
            $sigIds = $moving->map(fn ($a) => $this->createHandoverSignature(
                $a, (int) $data['from_consultant_id'], (int) $data['to_consultant_id'])->id)->all();
            if ($count > 0) {
                Notification::create(['user_id' => $data['to_consultant_id'], 'type' => 'handover.transfer', 'created_at' => now(), 'payload' => [
                    'count' => $count, 'from_name' => $this->consultantName((int) $data['from_consultant_id']),
                ]]);
            }

            return [$count, $sigIds];
        });
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'admission.bulk_reassign',
            'entity_type' => 'consultant', 'entity_id' => (string) $data['from_consultant_id'],
            'details' => ['to' => $data['to_consultant_id'], 'count' => $count, 'mark_new' => $markNew,
                'handover_signature_ids' => $sigIds], 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => "Reassigned {$count} patient(s)."]);
    }

    /** Auto-balance the unassigned queue across on-service consultants. */
    public function shuffle(Request $request, ShuffleService $shuffle): RedirectResponse
    {
        $u = Auth::user();
        if (! ($u->isAdmin() || $u->can_assign)) {
            throw new AccessDeniedHttpException('Requires the Assign capability.');
        }
        $r = $shuffle->run(Auth::id());
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'admission.shuffle',
            'entity_type' => 'admission', 'entity_id' => null, 'details' => $r, 'ip' => $request->ip()]);

        $msg = $r['assigned'] > 0
            ? "Shuffle assigned {$r['assigned']} patient(s) across {$r['consultants']} consultant(s)." . ($r['skipped'] ? " {$r['skipped']} skipped (at capacity)." : '')
            : 'No unassigned patients to shuffle.';

        return back()->with('flash', ['type' => $r['assigned'] > 0 ? 'success' : 'error', 'message' => $msg]);
    }

    public function assign(Request $request, Admission $admission): RedirectResponse
    {
        $u = Auth::user();
        if (! ($u->isAdmin() || $u->can_assign)) {
            throw new AccessDeniedHttpException('You do not have the Assign capability.');
        }
        $data = $request->validate([
            'consultant_id' => ['required', 'exists:users,id'],
            'mark_new' => ['nullable', 'boolean'],
        ]);
        // mark_new=false (legacy "New Patient?" unchecked) = quiet administrative assignment:
        // the new-assignment fields are left UNTOUCHED, preserving any existing assigned_at
        $markNew = $request->boolean('mark_new', true);

        // moving an ALREADY-assigned patient to a different consultant is a handover —
        // gated on a same-day handover note; first assignments are not
        $oldConsultant = $admission->consultant_id ? (int) $admission->consultant_id : null;
        $gated = $oldConsultant !== null && $oldConsultant !== (int) $data['consultant_id'];
        if ($gated) {
            $this->assertHandoverToday($admission);
        }

        $sig = DB::transaction(function () use ($admission, $data, $markNew, $gated, $oldConsultant) {
            $admission->update(['consultant_id' => $data['consultant_id']]
                + ($markNew ? ['is_new_assignment' => true, 'assigned_on' => now()->toDateString(), 'assigned_at' => now()] : []));
            if (! $gated) {
                return null;
            }
            $sig = $this->createHandoverSignature($admission, $oldConsultant, (int) $data['consultant_id']);
            Notification::create(['user_id' => $data['consultant_id'], 'type' => 'handover.transfer', 'created_at' => now(), 'payload' => [
                'admission_id' => $admission->id,
                'patient_name' => $admission->patient?->name,
                'mrn' => $admission->patient?->mrn,
                'from_name' => $this->consultantName($oldConsultant),
                'count_hint' => 1,
            ]]);

            return $sig;
        });
        $this->audit('admission.assign', $admission, ['consultant_id' => $data['consultant_id'], 'mark_new' => $markNew]
            + ($sig ? ['handover_signature_id' => $sig->id] : []));

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultant assigned.']);
    }

    /** Inline bed edit on a board card. */
    public function updateBed(Request $request, Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may change the bed.');
        }
        $data = $request->validate(['bed' => ['nullable', 'string', 'max:64']]);
        $old = $admission->bed;
        $admission->update(['bed' => $data['bed'] ?? null]);
        $this->audit('admission.bed', $admission, ['bed' => $admission->bed, 'was' => $old]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Bed updated.']);
    }

    /** Controlled destination vocabulary (legacy "Discharged to" list; ICU kept for historical rows). */
    private const DISCHARGE_DESTINATIONS = 'in:Home,Other Facility,LAMA,Absconded,Mortuary,Intensive Care (ICU)';

    /**
     * Phase 1 — medical discharge: clinically done but still occupying a bed ("discharged still in").
     * With complete=true (legacy "both") the SAME request also closes the file — discharge_date
     * mirrors the medical discharge date and the transfer_type follows the current location.
     *
     * Outcome vocabulary is strictly Alive/Dead (maintainer-confirmed; LAMA/Absconded are
     * DESTINATIONS). The medical-only path LOCKS the outcome to Alive like legacy phase-1 —
     * the status is (re-)asked at the COMPLETE step, where Dead forces the Mortuary destination.
     */
    public function medicalDischarge(Request $request, Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may discharge.');
        }
        $complete = $request->boolean('complete');
        $data = $request->validate([
            // medical-only carries no status (forced Alive below); the one-step close asks it
            'outcome' => [$complete ? 'required' : 'nullable', 'in:Alive,Dead'],
            'medical_discharge_date' => ['required', 'date', 'before_or_equal:today'],
            'discharge_to' => ['nullable', self::DISCHARGE_DESTINATIONS],
            'delay_reason' => ['nullable', 'in:Physical,System'],
            'complete' => ['nullable', 'boolean'],
        ]);
        if ($admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This admission is already fully discharged.']);
        }
        // legacy phase-1 stored Alive always — any client-sent status on medical-only is ignored
        $outcome = $complete ? $data['outcome'] : 'Alive';
        DB::transaction(function () use ($admission, $data, $complete, $outcome) {
            $admission->update([
                'medical_discharge_date' => $data['medical_discharge_date'],
                'outcome' => $outcome,
                // a death can only go to the mortuary — enforced here, not just in the UI;
                // medical-only stores NO destination (it is asked at the COMPLETE step, like legacy)
                'discharge_to' => ! $complete ? null : ($outcome === 'Dead' ? 'Mortuary' : ($data['discharge_to'] ?? null)),
                // a closed file has no "still-in" delay — only the medical-only path keeps the reason
                'delay_reason' => $complete ? null : ($data['delay_reason'] ?? null),
                'discharged_by' => Auth::id(),
            ] + ($complete ? [
                'discharge_date' => $data['medical_discharge_date'],
                'transfer_type' => $admission->current_location === 'ICU' ? 'discharge from ICU' : 'discharge from ward',
            ] : []));
            // only the file-closing path voids a pending handover signature (phase-1 keeps it)
            if ($complete) {
                $this->voidPendingSignatures($admission);
            }
        });
        $this->audit($complete ? 'admission.discharge_both' : 'admission.medical_discharge', $admission, ['outcome' => $outcome]);

        return back()->with('flash', ['type' => 'success', 'message' => $complete
            ? 'Patient discharged — file closed.'
            : 'Medically discharged — awaiting bed exit.']);
    }

    /**
     * Phase 2 — complete discharge: file closed, leaves the active board. The outcome/destination
     * captured at phase 1 may be OVERRIDDEN here (the patient's status can change while they wait
     * for the bed exit); without an override the phase-1 values stand.
     */
    public function completeDischarge(Request $request, Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may discharge.');
        }
        $data = $request->validate([
            'discharge_date' => ['required', 'date', 'before_or_equal:today'],
            'outcome' => ['nullable', 'in:Alive,Dead'],   // strict vocabulary — LAMA etc. are destinations
            'discharge_to' => ['nullable', self::DISCHARGE_DESTINATIONS],
        ]);
        if ($admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Already discharged.']);
        }
        // Phase 2 only follows phase 1: outcome is captured at medical discharge. Without this guard
        // a direct POST could close the file with outcome=NULL — counted in discharges but invisible
        // to the deaths metric. (ICU patients use the single-step icu-discharge, which sets outcome.)
        if (! $admission->medical_discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Record a medical discharge first (it captures the outcome).']);
        }
        $oldOutcome = $admission->outcome;
        $updates = [
            'discharge_date' => $data['discharge_date'],
            'transfer_type' => $admission->current_location === 'ICU' ? 'discharge from ICU' : 'discharge from ward',
            'discharged_by' => Auth::id(),
        ];
        if (($data['outcome'] ?? null) !== null || ($data['discharge_to'] ?? null) !== null) {
            $outcome = $data['outcome'] ?? $admission->outcome;
            $updates['outcome'] = $outcome;
            // a death can only go to the mortuary — same rule as medicalDischarge
            $updates['discharge_to'] = $outcome === 'Dead' ? 'Mortuary' : ($data['discharge_to'] ?? $admission->discharge_to);
        }
        DB::transaction(function () use ($admission, $updates) {
            $admission->update($updates);
            $this->voidPendingSignatures($admission);   // the file is closed — nothing left to sign
        });
        $details = ['date' => $data['discharge_date']];
        if (array_key_exists('outcome', $updates) && $updates['outcome'] !== $oldOutcome) {
            $details['outcome'] = $updates['outcome'];
            $details['outcome_was'] = $oldOutcome;
        }
        $this->audit('admission.complete_discharge', $admission, $details);

        return back()->with('flash', ['type' => 'success', 'message' => 'Discharge completed.']);
    }

    /** Single-step ICU discharge. */
    public function icuDischarge(Request $request, Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may discharge.');
        }
        $data = $request->validate([
            'outcome' => ['required', 'in:Alive,Dead'],   // strict vocabulary — LAMA etc. are destinations
            'discharge_date' => ['required', 'date', 'before_or_equal:today'],
            'discharge_to' => ['nullable', self::DISCHARGE_DESTINATIONS],
        ]);
        if ($admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Already discharged.']);
        }
        DB::transaction(function () use ($admission, $data) {
            $admission->update([
                'discharge_date' => $data['discharge_date'],
                'outcome' => $data['outcome'],
                // a death can only go to the mortuary — enforced here, not just in the UI
                'discharge_to' => $data['outcome'] === 'Dead' ? 'Mortuary' : ($data['discharge_to'] ?? null),
                'transfer_type' => 'discharge from ICU',
                'discharged_by' => Auth::id(),
            ]);
            $this->voidPendingSignatures($admission);   // the file is closed — nothing left to sign
        });
        $this->audit('admission.icu_discharge', $admission, ['outcome' => $data['outcome']]);

        return back()->with('flash', ['type' => 'success', 'message' => "ICU discharge complete ({$data['outcome']})."]);
    }

    /** Reverse a same-day discharge (admin only) — clears the discharge fields. */
    public function reverseDischarge(Admission $admission): RedirectResponse
    {
        if (! Auth::user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admin only.');
        }
        // Undo corrects a SAME-DAY mistake (legacy 48discharge.php showed the undo only while
        // DISDATE == today); anything older is history — reopening it would corrupt statistics.
        if (! $admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This admission is not discharged.']);
        }
        if (! $admission->discharge_date->isToday()) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Only same-day discharges can be reversed.']);
        }
        $admission->update(['discharge_date' => null, 'medical_discharge_date' => null, 'outcome' => null, 'discharge_to' => null, 'transfer_type' => null, 'discharged_by' => null]);
        $this->audit('admission.reverse_discharge', $admission);

        return back()->with('flash', ['type' => 'success', 'message' => 'Discharge reversed.']);
    }

    /** Undo a phase-1 medical discharge ("discharged still in") — returns the row to plain active. */
    public function undoMedicalDischarge(Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may undo a medical discharge.');
        }
        // a fully-discharged file is out of scope here — that correction is reverseDischarge (admin, ≤48h)
        if ($admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Already fully discharged — use reverse discharge instead.']);
        }
        if (! $admission->medical_discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This admission has no medical discharge to undo.']);
        }
        $admission->update(['medical_discharge_date' => null, 'outcome' => null, 'discharge_to' => null, 'delay_reason' => null, 'discharged_by' => null]);
        $this->audit('admission.undo_medical_discharge', $admission);

        return back()->with('flash', ['type' => 'success', 'message' => 'Medical discharge undone.']);
    }

    /**
     * Three-mode transfer (legacy semantics):
     *   location  — ward<->ICU move: close + reopen under the SAME consultant (default; a bare
     *               {target} payload without `mode` keeps its pre-wave behavior).
     *   specialty — internal handover: close as 'transfer to other speciality' + open a new
     *               episode under the CHOSEN consultant (it IS a new assignment).
     *   external  — out of the department: close as 'other transfer' to the named allied
     *               service; NO new episode.
     */
    public function transfer(Request $request, Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may transfer.');
        }
        $mode = $request->validate(['mode' => ['nullable', 'in:location,specialty,external']])['mode'] ?? 'location';

        if ($mode !== 'location' && $admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This admission is already discharged.']);
        }

        return match ($mode) {
            'specialty' => $this->transferSpecialty($request, $admission),
            'external' => $this->transferExternal($request, $admission),
            default => $this->transferLocation($request, $admission),
        };
    }

    /** Internal handover — new episode under the chosen consultant of the receiving specialty. */
    private function transferSpecialty(Request $request, Admission $admission): RedirectResponse
    {
        $data = $request->validate([
            'specialty_id' => ['required', Rule::exists('specialties', 'id')->where('is_external', false)],
            'consultant_id' => ['required', Rule::exists('users', 'id')->where('role', User::ROLE_CONSULTANT)],
        ]);
        $specialty = \App\Models\Specialty::findOrFail($data['specialty_id']);

        // an internal handover to a DIFFERENT consultant is gated on a same-day handover note
        $oldConsultant = $admission->consultant_id ? (int) $admission->consultant_id : null;
        $gated = $oldConsultant !== null && $oldConsultant !== (int) $data['consultant_id'];
        if ($gated) {
            $this->assertHandoverToday($admission);
        }

        $sig = null;
        $new = DB::transaction(function () use ($admission, $data, $specialty, $gated, $oldConsultant, &$sig) {
            // close the episode as a specialty handover (continuation of care); a pending phase-1
            // medical discharge is superseded — clear its fields like the location transfer does.
            // Legacy stamps MORTALITY='Alive' on every transfer-closed row (dmc-patients.php:120).
            $admission->update([
                'discharge_date' => now()->toDateString(),
                'transfer_type' => 'transfer to other speciality',
                'discharge_to' => $specialty->name,
                'medical_discharge_date' => null,
                'outcome' => 'Alive',
                'delay_reason' => null,
                'discharged_by' => Auth::id(),
            ]);

            // open the receiving episode under the CHOSEN consultant — same physical location,
            // bed cleared (reassign on the new service), and it IS a new assignment
            $new = Admission::create([
                'patient_id' => $admission->patient_id,
                'bed' => null,
                'admitted_from' => 'Transfer',
                'admit_date' => now()->toDateString(),
                'current_location' => $admission->current_location,
                'consultant_id' => $data['consultant_id'],
                'admitted_by' => Auth::id(),
                'is_new_assignment' => true,
                'assigned_on' => now()->toDateString(),
                'assigned_at' => now(),
            ]);

            // carry the diagnoses forward
            foreach ($admission->diagnoses as $dx) {
                $new->diagnoses()->create(['seq' => $dx->seq, 'icd10_code' => $dx->icd10_code]);
            }

            // the signature lives on the CLOSING episode — that's the one carrying the
            // handover text + revisions the receiving consultant is acknowledging
            if ($gated) {
                $sig = $this->createHandoverSignature($admission, $oldConsultant, (int) $data['consultant_id']);
                Notification::create(['user_id' => $data['consultant_id'], 'type' => 'handover.transfer', 'created_at' => now(), 'payload' => [
                    'admission_id' => $admission->id,
                    'patient_name' => $admission->patient?->name,
                    'mrn' => $admission->patient?->mrn,
                    'from_name' => $this->consultantName($oldConsultant),
                    'count_hint' => 1,
                ]]);
            }

            return $new;
        });
        $this->audit('admission.transfer_specialty', $admission, [
            'specialty_id' => (int) $data['specialty_id'], 'specialty' => $specialty->name,
            'consultant_id' => (int) $data['consultant_id'], 'new_admission_id' => $new->id,
        ] + ($sig ? ['handover_signature_id' => $sig->id] : []));

        return back()->with('flash', ['type' => 'success', 'message' => "Patient transferred to {$specialty->name}."]);
    }

    /** Out-of-department transfer — closes the episode only (no receiving episode here). */
    private function transferExternal(Request $request, Admission $admission): RedirectResponse
    {
        $data = $request->validate([
            'service' => ['required', 'string', 'max:128', Rule::exists('specialties', 'name')->where('is_external', true)],
        ]);

        // legacy stamps MORTALITY='Alive' on every transfer-closed row (dmc-patients.php:43)
        $admission->update([
            'discharge_date' => now()->toDateString(),
            'transfer_type' => 'other transfer',
            'discharge_to' => $data['service'],
            'medical_discharge_date' => null,
            'outcome' => 'Alive',
            'delay_reason' => null,
            'discharged_by' => Auth::id(),
        ]);
        $this->audit('admission.transfer_external', $admission, ['service' => $data['service']]);

        return back()->with('flash', ['type' => 'success', 'message' => "Patient transferred to {$data['service']}."]);
    }

    /** Ward<->ICU move — the original single-mode behavior, unchanged. */
    private function transferLocation(Request $request, Admission $admission): RedirectResponse
    {
        $data = $request->validate(['target' => ['required', 'in:Ward,ICU']]);
        if ($admission->current_location === $data['target']) {
            return back()->with('flash', ['type' => 'error', 'message' => "Patient is already in {$data['target']}."]);
        }

        $new = DB::transaction(function () use ($admission, $data) {
            // close the current episode as a transfer (continuation of care, not a discharge);
            // a pending phase-1 medical discharge is superseded by the transfer, so clear its
            // fields rather than leaving a stale delay on a transfer-closed row.
            // Legacy stamps the destination + MORTALITY='Alive' (dmc-patients.php:43,120;
            // icu-transfer.php:41) — the Trans-to-ICU stat keys on discharge_to='Intensive Care (ICU)'.
            $admission->update([
                'discharge_date' => now()->toDateString(),
                'transfer_type' => $admission->current_location === 'ICU' ? 'Transfer from ICU' : 'other transfer',
                'discharge_to' => $data['target'] === 'ICU' ? 'Intensive Care (ICU)' : 'Ward',
                'medical_discharge_date' => null,
                'outcome' => 'Alive',
                'delay_reason' => null,
                'discharged_by' => Auth::id(),
            ]);

            // open the receiving episode for the same patient; bed is NOT carried — the old
            // location's bed number is meaningless after a ward<->ICU move (assign on arrival)
            $new = Admission::create([
                'patient_id' => $admission->patient_id,
                'bed' => null,
                'admitted_from' => 'Transfer',
                'admit_date' => now()->toDateString(),
                'current_location' => $data['target'],
                'consultant_id' => $admission->consultant_id,
                'admitted_by' => Auth::id(),
            ]);

            // carry the diagnoses forward
            foreach ($admission->diagnoses as $dx) {
                $new->diagnoses()->create(['seq' => $dx->seq, 'icd10_code' => $dx->icd10_code]);
            }

            return $new;
        });
        $this->audit('admission.transfer', $admission, ['to' => $data['target'], 'new_admission_id' => $new->id]);

        return back()->with('flash', ['type' => 'success', 'message' => "Patient transferred to {$data['target']}."]);
    }

    /**
     * "Admission from ICU" — pull an active ICU patient onto the ward (legacy D2 behavior):
     * gated by the Add capability (not Manage), and the new ward episode is UNASSIGNED so the
     * patient re-enters the assignment queue instead of keeping the ICU consultant.
     */
    public function icuPull(Request $request, Admission $admission): RedirectResponse
    {
        $u = Auth::user();
        if (! ($u->isAdmin() || $u->can_add)) {
            throw new AccessDeniedHttpException('Requires the Add capability.');
        }
        if ($admission->discharge_date || $admission->current_location !== 'ICU') {
            return back()->with('flash', ['type' => 'error', 'message' => 'Only an active ICU patient can be admitted from ICU.']);
        }

        $new = DB::transaction(function () use ($admission) {
            // close the ICU episode as a transfer-out (continuation of care, not a discharge);
            // any pending phase-1 medical discharge is superseded — clear its fields like transfer().
            // Legacy stamps DISTO='Ward' + MORTALITY='Alive' (newpatients/dmc-patients-icu-transfer.php:41).
            $admission->update([
                'discharge_date' => now()->toDateString(),
                'transfer_type' => 'Transfer from ICU',
                'discharge_to' => 'Ward',
                'medical_discharge_date' => null,
                'outcome' => 'Alive',
                'delay_reason' => null,
                'discharged_by' => Auth::id(),
            ]);

            // open the ward episode unassigned (no consultant, no bed yet — assign on arrival)
            $new = Admission::create([
                'patient_id' => $admission->patient_id,
                'bed' => null,
                'admitted_from' => 'ICU',
                'admit_date' => now()->toDateString(),
                'current_location' => 'Ward',
                'consultant_id' => null,
                'is_new_assignment' => false,
                'assigned_on' => null,
                'assigned_at' => null,
                'admitted_by' => Auth::id(),
            ]);

            // carry the diagnoses forward
            foreach ($admission->diagnoses as $dx) {
                $new->diagnoses()->create(['seq' => $dx->seq, 'icd10_code' => $dx->icd10_code]);
            }

            return $new;
        });
        $this->audit('admission.icu_pull', $admission, ['new_admission_id' => $new->id]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Patient admitted from ICU — now in the assignment queue.']);
    }

    /**
     * Hard-delete an admission (admin only) — the legacy "delete" on the queue/board. The audit
     * row captures the identifying details BEFORE the row disappears; recovery is via backups.
     */
    public function destroy(Admission $admission): RedirectResponse
    {
        if (! Auth::user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admin only.');
        }
        $details = [
            'mrn' => $admission->patient?->mrn,
            'patient' => $admission->patient?->name,
            'admit_date' => optional($admission->admit_date)->toDateString(),
        ];

        DB::transaction(function () use ($admission, $details) {
            $this->audit('admission.delete', $admission, $details);   // written first — survives the delete
            $this->voidPendingSignatures($admission);                 // moot before the cascade, but explicit
            $admission->diagnoses()->delete();                        // explicit, even though the FK cascades
            $admission->delete();
        });

        return back()->with('flash', ['type' => 'success', 'message' => 'Admission deleted.']);
    }
}
