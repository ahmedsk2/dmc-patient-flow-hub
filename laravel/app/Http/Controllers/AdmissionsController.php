<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\AdmissionDiagnosis;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Inertia\Inertia;
use Inertia\Response;

class AdmissionsController extends Controller
{
    /**
     * New Admissions queue: patients admitted but not yet assigned to a consultant (unshuffled).
     * FIFO — oldest admission first (legacy queue order), so the longest-waiting patient is on top.
     */
    public function index(): Response
    {
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

    public function create(): Response
    {
        return Inertia::render('Admissions/Create', [
            'consultants' => User::consultantOptions(),
            'countries' => Country::orderBy('name')->pluck('name'),
            'locations' => ['ER', 'Ward', 'ICU'],
            'admitFrom' => \App\Http\Requests\StoreAdmissionRequest::ADMIT_FROM,   // full legacy ADMFROM vocabulary
        ]);
    }

    public function store(\App\Http\Requests\StoreAdmissionRequest $request): RedirectResponse
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

            AuditLog::create([
                'actor_id' => Auth::id(), 'actor_name' => Auth::user()->name,
                'action' => 'admission.create', 'entity_type' => 'admission', 'entity_id' => (string) $admission->id,
                'details' => ['mrn' => $patient->mrn, 'location' => $admission->current_location],
                'ip' => request()->ip(),
            ]);

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
        // Feeds the Modify modal with full demographics — gate to those who may actually modify
        // (or manage / own) the admission so read-only users can't pull PHI through this JSON.
        $u = Auth::user();
        if (! ($u->isAdmin() || $u->can_modify || $u->can_manage || (int) $admission->consultant_id === (int) $u->id)) {
            throw new AccessDeniedHttpException('You do not have access to this patient\'s details.');
        }
        $admission->load('patient', 'diagnoses');
        $names = Icd10::whereIn('code', $admission->diagnoses->pluck('icd10_code'))->pluck('name', 'code');

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
            'diagnoses' => $admission->diagnoses->map(fn ($d) => ['code' => $d->icd10_code, 'name' => $names[$d->icd10_code] ?? $d->icd10_code])->values(),
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
