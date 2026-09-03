<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdmissionRequest;
use App\Models\Admission;
use App\Models\AdmissionDiagnosis;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AdmissionsController extends Controller
{
    /**
     * New Admissions queue: patients admitted but not yet assigned to a consultant (unshuffled).
     * FIFO — oldest admission first (legacy queue order), so the longest-waiting patient is on top.
     */
    public function index(): Response
    {
        $this->denyObservers();   // legacy access_PICU_patients [0,2,3,4] page gate (J2-12)
        $rows = Admission::query()
            ->whereNull('discharge_date')->whereNull('consultant_id')
            ->with(['patient:id,mrn,name,gender,age', 'diagnoses:id,admission_id,seq,icd10_code'])
            ->withCount('diagnoses')
            ->orderBy('admit_date')->orderBy('id')->get();

        // ICD-10 names for every code on the queue — ONE lookup (J1-15b), like the board cards
        $codes = $rows->flatMap(fn ($a) => $a->diagnoses->pluck('icd10_code'))->unique()->values();
        $dxNames = $codes->isEmpty() ? collect() : Icd10::whereIn('code', $codes)->pluck('name', 'code');

        $queue = $rows->map(fn (Admission $a) => [
            'id' => $a->id,
            'name' => $a->patient?->name ?? 'Unknown',
            'mrn' => $a->patient?->mrn,
            'age' => $a->patient?->age,
            'gender' => $a->patient?->gender,
            'bed' => $a->bed,
            'location' => $a->current_location,
            'admitted_from' => $a->admitted_from,
            'admit_date' => optional($a->admit_date)->toDateString(),
            'dx_count' => $a->diagnoses_count,
            'diagnoses' => $a->diagnoses->sortBy('seq')->values()
                ->map(fn ($d) => ['code' => $d->icd10_code, 'name' => $dxNames[$d->icd10_code] ?? $d->icd10_code])->all(),
            'los' => $a->lengthOfStay(),
        ]);

        // active ICU patients that can be pulled onto the ward ("Admission from ICU")
        $icuPatients = Admission::query()
            ->whereNull('discharge_date')->where('current_location', 'ICU')
            ->with(['patient:id,mrn,name', 'consultant:id,full_name,name'])
            ->orderBy('bed')->get()
            ->map(fn (Admission $a) => [
                'id' => $a->id, 'name' => $a->patient?->name ?? 'Unknown', 'mrn' => $a->patient?->mrn,
                'bed' => $a->bed, 'consultant' => $a->consultant?->full_name ?? $a->consultant?->name ?? 'Unassigned',
            ]);

        return Inertia::render('Admissions/Index', [
            'queue' => $queue,
            'icuPatients' => $icuPatients,
            'consultants' => User::consultantOptions(),
            'countries' => Country::orderBy('name')->pluck('name'),   // Modify modal nationality select
        ]);
    }

    /** Observers are read-only: the admissions queue + admit form are clinical-role pages (J2-12). */
    private function denyObservers(): void
    {
        if (Auth::user()->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }
    }

    public function create(): Response
    {
        $this->denyObservers();   // legacy access_PICU_patients [0,2,3,4] page gate (J2-12)
        // legacy: the New Admission form is Can-Add only (button hidden + page gated)
        $u = request()->user();
        abort_unless($u->isAdmin() || $u->can_add, 403, 'Requires the Add capability.');

        return Inertia::render('Admissions/Create', [
            'consultants' => User::consultantOptions(),
            'countries' => Country::orderBy('name')->pluck('name'),
            'locations' => ['ER', 'Ward', 'ICU'],
            'admitFrom' => StoreAdmissionRequest::ADMIT_FROM,   // full legacy ADMFROM vocabulary
        ]);
    }

    public function store(StoreAdmissionRequest $request): RedirectResponse
    {
        // role gate lives in StoreAdmissionRequest::authorize() (403 before validation)
        $data = $request->validated();

        $admission = DB::transaction(function () use ($data) {
            $patient = Patient::firstOrCreate(
                ['mrn' => $data['mrn']],
                ['name' => $data['name'], 'gender' => $data['gender'] ?? null, 'age' => $data['age'] ?? null,
                    'nationality' => $data['nationality'] ?? null]
            );
            // refresh demographics on the canonical record
            $patient->fill(['name' => $data['name'], 'gender' => $data['gender'] ?? $patient->gender,
                'age' => $data['age'] ?? $patient->age,
                'nationality' => $data['nationality'] ?? $patient->nationality])->save();

            $admission = Admission::create([
                'patient_id' => $patient->id,
                'bed' => $data['bed'] ?? null,
                'admitted_from' => $data['admitted_from'] ?? null,
                'admit_date' => $data['admit_date'],
                'current_location' => $data['current_location'],
                'consultant_id' => $data['consultant_id'] ?? null,
                'admitted_by' => Auth::id(),                 // session-sourced (NOT client-supplied)
                'is_new_assignment' => ! empty($data['consultant_id']),
                'assigned_on' => ! empty($data['consultant_id']) ? now()->toDateString() : null,
                'assigned_at' => ! empty($data['consultant_id']) ? now() : null,
            ]);

            $seq = 1;
            foreach (array_unique(array_filter(array_map('trim', $data['diagnoses'] ?? []))) as $code) {
                AdmissionDiagnosis::create(['admission_id' => $admission->id, 'seq' => $seq++, 'icd10_code' => $code]);
            }

            Audit::log('admission.create', 'admission', (string) $admission->id,
                ['mrn' => $patient->mrn, 'location' => $admission->current_location]);

            return $admission;
        });

        return redirect()->route('patients.index')->with('flash', [
            'type' => 'success',
            'message' => "Patient {$data['name']} admitted (MRN {$data['mrn']}).",
        ]);
    }

    /** Full detail for the Modify modal (demographics + current diagnoses with names). */
    public function edit(Admission $admission): JsonResponse
    {
        // Feeds the Modify modal with full demographics — gated to admin/Can-Modify ONLY, the
        // exact audience of the legacy Can-Modify fragment (K1-10; was also can_manage/owner).
        // Managers/owners act through the action endpoints, not this PHI prefill.
        $u = Auth::user();
        // Observers are read-only regardless of capability flags (J1-9 global guarantee): an
        // Observer carrying a stray can_modify=1 must not pull full patient PHI via this endpoint.
        if ($u->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }
        if (! ($u->isAdmin() || $u->can_modify)) {
            throw new AccessDeniedHttpException('You do not have access to this patient\'s details.');
        }
        $admission->load('patient', 'diagnoses');
        $names = Icd10::whereIn('code', $admission->diagnoses->pluck('icd10_code'))->pluck('name', 'code');

        // PHI-read logging (Item 3): per-record detail opens are OFF by default — the request-level
        // registry search/export logs already cover the access event. When an admin turns
        // `log_record_opens` ON (Control → Settings) this writes ONE break-glass row per open.
        if (Setting::current()->log_record_opens) {
            Audit::log('registry.open', 'admission', (string) $admission->id, [
                'mrn' => $admission->patient?->mrn,
            ]);
        }

        return response()->json([
            'id' => $admission->id,
            'mrn' => $admission->patient?->mrn,
            'name' => $admission->patient?->name,
            'age' => $admission->patient?->age,
            'gender' => $admission->patient?->gender,
            'nationality' => $admission->patient?->nationality,
            'bed' => $admission->bed,
            'admit_date' => optional($admission->admit_date)->toDateString(),
            'admitted_from' => $admission->admitted_from,
            'current_location' => $admission->current_location,
            'consultant_id' => $admission->consultant_id,   // prefills the Modify consultant select (J2-13)
            'diagnoses' => $admission->diagnoses->map(fn ($d) => ['code' => $d->icd10_code, 'name' => $names[$d->icd10_code] ?? $d->icd10_code])->values(),
            // Per-patient activity panel (Item 2): the audit trail for THIS admission, latest first.
            // Behind the same admin/Can-Modify gate as the demographics above. Composite index
            // (entity_type, entity_id) backs this lookup.
            'activity' => AuditLog::where('entity_type', 'admission')
                ->where('entity_id', (string) $admission->id)
                ->latest('created_at')->latest('id')
                ->limit(50)
                ->get(['id', 'action', 'actor_name', 'details', 'created_at'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'action' => $row->action,
                    'actor' => $row->actor_name,
                    'details' => $row->details,   // already array-cast
                    'at' => $row->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    /** ICD-10 typeahead for the diagnosis picker — relevance: code-prefix, name-prefix, substring. */
    public function icd10(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $rows = Icd10::query()
            ->where(fn ($w) => $w->where('code', 'like', "{$q}%")->orWhere('name', 'like', "%{$q}%"))
            ->orderByRaw('CASE WHEN code LIKE ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END, code', ["{$q}%", "{$q}%"])
            ->limit(50)->get(['code', 'name']);

        return response()->json($rows->map(fn ($r) => ['code' => $r->code, 'name' => $r->name]));
    }
}
