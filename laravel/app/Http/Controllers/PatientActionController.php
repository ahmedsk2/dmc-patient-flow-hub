<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ShuffleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    /** Full edit of an admission's patient demographics + diagnoses (Modify capability). */
    public function modify(\App\Http\Requests\ModifyAdmissionRequest $request, Admission $admission): RedirectResponse
    {
        // capability gate lives in ModifyAdmissionRequest::authorize() (403 before validation)
        $patient = $admission->patient;
        $data = $request->validated();

        DB::transaction(function () use ($admission, $patient, $data) {
            $patient->update([
                'mrn' => $data['mrn'], 'name' => $data['name'], 'age' => $data['age'] ?? null,
                'gender' => $data['gender'] ?? null, 'nationality' => $data['nationality'] ?? null,
            ]);
            $admission->update(['bed' => $data['bed'] ?? null]);
            $admission->diagnoses()->delete();
            $seq = 1;
            foreach (array_unique(array_filter(array_map('trim', $data['diagnoses'] ?? []))) as $code) {
                $admission->diagnoses()->create(['seq' => $seq++, 'icd10_code' => $code]);
            }
        });
        $this->audit('patient.modify', $admission, ['mrn' => $data['mrn']]);

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

    /** Bulk reassign every active admission from one consultant to another. */
    public function bulkReassign(Request $request): RedirectResponse
    {
        $u = Auth::user();
        if (! ($u->isAdmin() || $u->can_assign || $u->can_manage)) {
            throw new AccessDeniedHttpException('Requires the Assign or Manage capability.');
        }
        $data = $request->validate([
            'from_consultant_id' => ['required', 'exists:users,id'],
            'to_consultant_id' => ['required', 'exists:users,id', 'different:from_consultant_id'],
        ]);
        $count = Admission::whereNull('discharge_date')->where('consultant_id', $data['from_consultant_id'])
            ->update(['consultant_id' => $data['to_consultant_id'], 'is_new_assignment' => true, 'assigned_on' => now()->toDateString(), 'assigned_at' => now()]);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'admission.bulk_reassign',
            'entity_type' => 'consultant', 'entity_id' => (string) $data['from_consultant_id'],
            'details' => ['to' => $data['to_consultant_id'], 'count' => $count], 'ip' => $request->ip()]);

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
        $data = $request->validate(['consultant_id' => ['required', 'exists:users,id']]);

        $admission->update([
            'consultant_id' => $data['consultant_id'],
            'is_new_assignment' => true,
            'assigned_on' => now()->toDateString(),
            'assigned_at' => now(),
        ]);
        $this->audit('admission.assign', $admission, ['consultant_id' => $data['consultant_id']]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultant assigned.']);
    }

    /** Phase 1 — medical discharge: clinically done but still occupying a bed ("discharged still in"). */
    public function medicalDischarge(Request $request, Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may discharge.');
        }
        $data = $request->validate([
            'outcome' => ['required', 'in:Alive,Dead,LAMA,DAMA,Transferred'],
            'medical_discharge_date' => ['required', 'date', 'before_or_equal:today'],
            'discharge_to' => ['nullable', 'string', 'max:128'],
            'delay_reason' => ['nullable', 'string', 'max:191'],
        ]);
        if ($admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This admission is already fully discharged.']);
        }
        $admission->update([
            'medical_discharge_date' => $data['medical_discharge_date'],
            'outcome' => $data['outcome'],
            'discharge_to' => $data['discharge_to'] ?? null,
            'delay_reason' => $data['delay_reason'] ?? null,
            'discharged_by' => Auth::id(),
        ]);
        $this->audit('admission.medical_discharge', $admission, ['outcome' => $data['outcome']]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Medically discharged — awaiting bed exit.']);
    }

    /** Phase 2 — complete discharge: file closed, leaves the active board. */
    public function completeDischarge(Request $request, Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may discharge.');
        }
        $data = $request->validate(['discharge_date' => ['required', 'date', 'before_or_equal:today']]);
        if ($admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Already discharged.']);
        }
        // Phase 2 only follows phase 1: outcome is captured at medical discharge. Without this guard
        // a direct POST could close the file with outcome=NULL — counted in discharges but invisible
        // to the deaths metric. (ICU patients use the single-step icu-discharge, which sets outcome.)
        if (! $admission->medical_discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Record a medical discharge first (it captures the outcome).']);
        }
        $admission->update([
            'discharge_date' => $data['discharge_date'],
            'transfer_type' => $admission->current_location === 'ICU' ? 'discharge from ICU' : 'discharge from ward',
            'discharged_by' => Auth::id(),
        ]);
        $this->audit('admission.complete_discharge', $admission, ['date' => $data['discharge_date']]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Discharge completed.']);
    }

    /** Single-step ICU discharge. */
    public function icuDischarge(Request $request, Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may discharge.');
        }
        $data = $request->validate([
            'outcome' => ['required', 'in:Alive,Dead,LAMA,DAMA,Transferred'],
            'discharge_date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        if ($admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Already discharged.']);
        }
        $admission->update([
            'discharge_date' => $data['discharge_date'],
            'outcome' => $data['outcome'],
            'transfer_type' => 'discharge from ICU',
            'discharged_by' => Auth::id(),
        ]);
        $this->audit('admission.icu_discharge', $admission, ['outcome' => $data['outcome']]);

        return back()->with('flash', ['type' => 'success', 'message' => "ICU discharge complete ({$data['outcome']})."]);
    }

    /** Reverse a recent discharge (admin only) — clears the discharge fields. */
    public function reverseDischarge(Admission $admission): RedirectResponse
    {
        if (! Auth::user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admin only.');
        }
        // Undo is for correcting recent mistakes (the 48h Recent registry), not silently
        // reopening months-old closed episodes — that would corrupt historical statistics.
        if (! $admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This admission is not discharged.']);
        }
        if ($admission->discharge_date->lt(now()->subDays(2)->startOfDay())) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Only discharges from the last 48 hours can be reversed.']);
        }
        $admission->update(['discharge_date' => null, 'medical_discharge_date' => null, 'outcome' => null, 'transfer_type' => null, 'discharged_by' => null]);
        $this->audit('admission.reverse_discharge', $admission);

        return back()->with('flash', ['type' => 'success', 'message' => 'Discharge reversed.']);
    }

    public function transfer(Request $request, Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may transfer.');
        }
        $data = $request->validate(['target' => ['required', 'in:Ward,ICU']]);
        if ($admission->current_location === $data['target']) {
            return back()->with('flash', ['type' => 'error', 'message' => "Patient is already in {$data['target']}."]);
        }

        $new = DB::transaction(function () use ($admission, $data) {
            // close the current episode as a transfer (continuation of care, not a discharge);
            // a pending phase-1 medical discharge is superseded by the transfer, so clear its
            // fields rather than leaving a stale outcome/delay on a transfer-closed row
            $admission->update([
                'discharge_date' => now()->toDateString(),
                'transfer_type' => $admission->current_location === 'ICU' ? 'Transfer from ICU' : 'other transfer',
                'medical_discharge_date' => null,
                'outcome' => null,
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
}
