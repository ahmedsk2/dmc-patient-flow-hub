<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        ]);
        $this->audit('admission.assign', $admission, ['consultant_id' => $data['consultant_id']]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultant assigned.']);
    }

    public function discharge(Request $request, Admission $admission): RedirectResponse
    {
        if (! $this->canManage($admission)) {
            throw new AccessDeniedHttpException('Only the primary consultant or a manager may discharge.');
        }
        $data = $request->validate([
            'outcome' => ['required', 'in:Alive,Dead,LAMA,DAMA,Transferred'],
            'discharge_date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        if ($admission->discharge_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This admission is already discharged.']);
        }

        $admission->update([
            'discharge_date' => $data['discharge_date'],
            'outcome' => $data['outcome'],
            'transfer_type' => $admission->current_location === 'ICU' ? 'discharge from ICU' : 'discharge from ward',
            'discharged_by' => Auth::id(),
        ]);
        $this->audit('admission.discharge', $admission, ['outcome' => $data['outcome'], 'date' => $data['discharge_date']]);

        return back()->with('flash', ['type' => 'success', 'message' => "Patient discharged ({$data['outcome']})."]);
    }

    /** Reverse a same-day discharge (admin only) — clears the discharge fields. */
    public function reverseDischarge(Admission $admission): RedirectResponse
    {
        if (! Auth::user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admin only.');
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
            // close the current episode as a transfer (continuation of care, not a discharge)
            $admission->update([
                'discharge_date' => now()->toDateString(),
                'transfer_type' => $admission->current_location === 'ICU' ? 'Transfer from ICU' : 'other transfer',
                'discharged_by' => Auth::id(),
            ]);

            // open the receiving episode for the same patient
            $new = Admission::create([
                'patient_id' => $admission->patient_id,
                'bed' => $admission->bed,
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
