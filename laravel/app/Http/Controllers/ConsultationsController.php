<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConsultationsController extends Controller
{
    public function index(Request $request): Response
    {
        // legacy access_PICU_patients [0,2,3,4] page gate — observers are read-only and the
        // consultations workspace is a clinical-role page (J2-12)
        if (Auth::user()->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }
        $filters = $request->only('search', 'status', 'scope');
        $status = $filters['status'] ?? 'active';
        $mine = ($filters['scope'] ?? '') === 'mine';
        $reasons = ConsultationReason::pluck('name', 'id');

        $consultations = Consultation::query()
            ->with('consultant:id,full_name,name')
            ->when($status === 'active', fn ($q) => $q->whereNull('signoff_date'))
            ->when($status === 'signed', fn ($q) => $q->whereNotNull('signoff_date'))
            ->when($mine, fn ($q) => $q->where('consultant_id', Auth::id()))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) =>
                $w->where('patient_name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%")))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Consultation $c) => [
                'id' => $c->id,
                'name' => $c->patient_name ?? 'Unknown',
                'mrn' => $c->mrn,
                'age' => $c->age,
                'bed' => $c->bed,
                'location' => $c->current_location,
                'from' => $c->consultation_from,
                'to' => $c->to_service,
                'consultant' => $c->consultant?->full_name ?? $c->consultant?->name ?? '—',
                'consultant_id' => $c->consultant_id,
                'date' => optional($c->consultation_date)->toDateString(),
                'signoff' => optional($c->signoff_date)->toDateString(),
                'reasons' => collect($c->indication ?? [])->map(fn ($id) => $reasons[$id] ?? null)->filter()->values(),
                'indication_ids' => array_map('intval', $c->indication ?? []),
                'other' => $c->other_indication,
            ]);

        return Inertia::render('Consultations/Index', [
            'consultations' => $consultations,
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $status, 'scope' => $mine ? 'mine' : ''],
            // full objects (not just names): the form filters the consultant dropdown by
            // INTERNAL specialty when "to service" matches one
            'specialties' => Specialty::orderBy('name')->get(['id', 'name', 'is_external']),
            'stats' => [
                'active' => Consultation::whereNull('signoff_date')->count(),
                'total' => Consultation::count(),
                // personal counter for consultant-role viewers (K1-13): own active out of total active
                'mine_active' => Consultation::whereNull('signoff_date')->where('consultant_id', Auth::id())->count(),
            ],
            'reasons' => $reasons->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'consultants' => User::consultantOptions(),
        ]);
    }

    public function store(\App\Http\Requests\ConsultationRequest $request): RedirectResponse
    {
        // Observer gate lives in ConsultationRequest::authorize() (403 before validation)
        $data = $request->validated();

        $patient = Patient::where('mrn', $data['mrn'])->first();
        $c = Consultation::create([
            ...$data,
            'patient_id' => $patient?->id,
            'indication' => $data['indication'] ?? [],
            'entered_by' => Auth::id(),                  // session-sourced
        ]);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'consultation.create',
            'entity_type' => 'consultation', 'entity_id' => (string) $c->id, 'details' => ['mrn' => $data['mrn']], 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation created.']);
    }

    public function signoff(Request $request, Consultation $consultation): RedirectResponse
    {
        $u = Auth::user();
        // observers are read-only EVEN with a Manage flag (J1-9 global guarantee)
        if ($u->isObserver() || ! ($u->isAdmin() || $u->can_manage || (int) $consultation->consultant_id === (int) $u->id)) {
            throw new AccessDeniedHttpException('Only the receiving consultant or a manager may sign off.');
        }
        if ($consultation->signoff_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Already signed off.']);
        }
        $consultation->update(['signoff_date' => now()->toDateString()]);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'consultation.signoff',
            'entity_type' => 'consultation', 'entity_id' => (string) $consultation->id, 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation signed off.']);
    }

    /** Undo a same-day sign-off (admin only) — clears the sign-off date. */
    public function reverseSignoff(Request $request, Consultation $consultation): RedirectResponse
    {
        if (! Auth::user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admin only.');
        }
        // Undo corrects a SAME-DAY mistake (legacy 48consultation.php undo) — mirrors the
        // same-day reverse-discharge guard; older sign-offs are history.
        if (! $consultation->signoff_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This consultation is not signed off.']);
        }
        if (! $consultation->signoff_date->isToday()) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Only same-day sign-offs can be reversed.']);
        }
        $consultation->update(['signoff_date' => null]);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'consultation.reverse_signoff',
            'entity_type' => 'consultation', 'entity_id' => (string) $consultation->id, 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Sign-off reversed.']);
    }

    /** Edit a consultation (receiving consultant / manager / admin). */
    public function update(\App\Http\Requests\ConsultationRequest $request, Consultation $consultation): RedirectResponse
    {
        // edit is open to any clinical role (J1-10 legacy parity); gate lives in ConsultationRequest::authorize()
        $data = $request->validated();
        $consultation->update([...$data, 'indication' => $data['indication'] ?? []]);
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'consultation.modify',
            'entity_type' => 'consultation', 'entity_id' => (string) $consultation->id, 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation updated.']);
    }

    /** Delete a consultation (admin only). */
    public function destroy(Request $request, Consultation $consultation): RedirectResponse
    {
        if (! Auth::user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admin only.');
        }
        $id = $consultation->id;
        $consultation->delete();
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'consultation.delete',
            'entity_type' => 'consultation', 'entity_id' => (string) $id, 'ip' => $request->ip()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation deleted.']);
    }
}
