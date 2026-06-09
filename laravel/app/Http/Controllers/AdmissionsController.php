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
    /** New Admissions queue: patients admitted but not yet assigned to a consultant (unshuffled). */
    public function index(): Response
    {
        $queue = Admission::query()
            ->whereNull('discharge_date')->whereNull('consultant_id')
            ->with('patient:id,mrn,name,gender,age')->withCount('diagnoses')
            ->orderByDesc('admit_date')->orderByDesc('id')->get()
            ->map(fn (Admission $a) => [
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
            'consultants' => User::where('role', User::ROLE_CONSULTANT)->where('active', 1)
                ->orderBy('full_name')->get(['id', 'full_name', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name ?: $u->name]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admissions/Create', [
            'consultants' => User::where('role', User::ROLE_CONSULTANT)->where('active', 1)
                ->orderBy('full_name')->get(['id', 'full_name', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name ?: $u->name]),
            'countries' => Country::orderBy('name')->pluck('name'),
            'locations' => ['ER', 'Ward', 'ICU'],
            'admitFrom' => ['ER', 'Clinic', 'Referral', 'Transfer', 'Direct'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ((int) Auth::user()->role === User::ROLE_OBSERVER) {
            throw new AccessDeniedHttpException('Observers cannot admit patients.');
        }

        $request->merge(['mrn' => trim((string) $request->input('mrn'))]);   // strip stray whitespace before validation
        $data = $request->validate([
            'mrn' => ['required', 'string', 'regex:/^\d{1,11}$/'],   // digits only, ≤11 (clean-data rule)
            'name' => ['required', 'string', 'max:191'],
            'age' => ['nullable', 'integer', 'between:0,130'],
            'gender' => ['nullable', 'in:Male,Female'],
            'nationality' => ['nullable', 'string', 'max:191'],
            'bed' => ['nullable', 'string', 'max:64'],
            'admit_date' => ['required', 'date', 'before_or_equal:today'],
            'admitted_from' => ['nullable', 'string', 'max:64'],
            'current_location' => ['required', 'in:ER,Ward,ICU'],
            'consultant_id' => ['nullable', 'exists:users,id'],
            'diagnoses' => ['array'],
            'diagnoses.*' => ['string', 'max:100'],
        ]);

        $admission = DB::transaction(function () use ($data) {
            $patient = Patient::firstOrCreate(
                ['mrn' => $data['mrn']],
                ['name' => $data['name'], 'gender' => $data['gender'] ?? null, 'age' => $data['age'] ?? null]
            );
            // refresh demographics on the canonical record
            $patient->fill(['name' => $data['name'], 'gender' => $data['gender'] ?? $patient->gender, 'age' => $data['age'] ?? $patient->age])->save();

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
            'diagnoses' => $admission->diagnoses->map(fn ($d) => ['code' => $d->icd10_code, 'name' => $names[$d->icd10_code] ?? $d->icd10_code])->values(),
        ]);
    }

    /** ICD-10 typeahead for the diagnosis picker. */
    public function icd10(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $rows = Icd10::query()
            ->where('code', 'like', "{$q}%")->orWhere('name', 'like', "%{$q}%")
            ->limit(20)->get(['code', 'name']);

        return response()->json($rows->map(fn ($r) => ['code' => $r->code, 'name' => $r->name]));
    }
}
