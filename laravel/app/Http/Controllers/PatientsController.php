<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Active Patients board — grouped by consultant (on-service hospitalists, then on-service
 * subspecialists, then off-service), like the legacy active list. Shows ASSIGNED patients only;
 * not-yet-assigned admissions live on the New Admissions queue.
 */
class PatientsController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = Setting::current();
        $filters = $request->only('search', 'location', 'view');
        [$scope, $ownOnlyId] = $this->boardScope($request);
        $tbExists = $this->tbExists();
        [$groups, $readmitWindow] = $this->boardGroups($filters, $settings, $scope, $tbExists, $ownOnlyId);

        return Inertia::render('Patients/Index', [
            'groups' => $groups,
            'filters' => $filters,
            'readmitWindow' => $readmitWindow,
            'consultants' => User::consultantOptions(),
            'countries' => \App\Models\Country::orderBy('name')->pluck('name'),   // Modify modal nationality select
            'specialties' => Specialty::where('is_external', false)->orderBy('name')->get(['id', 'name']),
            'externalServices' => Specialty::where('is_external', true)->orderBy('name')->pluck('name'),
            'stats' => [
                // chips mirror what the viewer can SEE (D1-scoped for consultants) — except the
                // assignment queue, which stays global so they can still grab new patients from it
                'total' => Admission::active()->whereNotNull('consultant_id')->tap($scope)->count(),
                'ward' => Admission::active()->whereNotNull('consultant_id')->tap($scope)->nonIcu()->count(),
                'icu' => Admission::active()->whereNotNull('consultant_id')->tap($scope)->icu()->count(),
                'longterm' => Admission::active()->whereNotNull('consultant_id')->tap($scope)->where('is_longterm', true)->count(),
                'tb' => Admission::active()->whereNotNull('consultant_id')->tap($scope)->where($tbExists)->count(),
                'unassigned' => Admission::active()->whereNull('consultant_id')->count(),
            ],
        ]);
    }

    /**
     * Printable read-only census board (all roles) — UNSCOPED: the legacy active-list.php
     * showed every consultant's list to every logged-in user (it is the printed ward census),
     * so unlike the interactive board there is NO D1 consultant scoping here (B11).
     */
    public function activeList(Request $request): Response
    {
        [$groups, $readmitWindow] = $this->boardGroups(
            [], Setting::current(), fn ($q) => $q, $this->tbExists(), null);

        return Inertia::render('ActiveList', [
            'groups' => $groups,
            'readmitWindow' => $readmitWindow,
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ]);
    }

    /**
     * D1 (legacy endorsement scope [0,2,4]): a consultant sees only THEIR OWN group;
     * admin/registrar/resident/observer see the whole board.
     *
     * @return array{0: \Closure, 1: ?int} [scope closure, own-only consultant id (null = unscoped)]
     */
    private function boardScope(Request $request): array
    {
        $u = $request->user();
        $ownOnly = (int) $u->role === User::ROLE_CONSULTANT && ! $u->isAdmin();

        return [fn ($q) => $ownOnly ? $q->where('consultant_id', $u->id) : $q, $ownOnly ? (int) $u->id : null];
    }

    /** Active-TB predicate (diagnosis on the tb_diagnoses list). */
    private function tbExists(): \Closure
    {
        return fn ($q) => $q->whereExists(fn ($sub) => $sub->selectRaw('1')
            ->from('admission_diagnoses as ad')->join('tb_diagnoses as tb', 'tb.icd10_code', '=', 'ad.icd10_code')
            ->whereColumn('ad.admission_id', 'admissions.id'));
    }

    /**
     * The board dataset: assigned active admissions, grouped per consultant with per-group
     * counts, ordered on-service hospitalists → on-service subspecialists → off-service.
     * Shared by the interactive board (index) and the printable census (activeList).
     *
     * On the UNFILTERED board, active ON-SERVICE consultants with ZERO patients still get a
     * group (legacy active-list built the table from the members list, so a freshly on-service
     * consultant shows up with zeros); off-service consultants appear only while they hold
     * patients (B9). $ownOnlyId keeps the injection inside the D1 consultant scope.
     *
     * @return array{0: array, 1: int} [$groups, $readmitWindow]
     */
    private function boardGroups(array $filters, Setting $settings, \Closure $scope, \Closure $tbExists, ?int $ownOnlyId): array
    {
        $tbCodes = DB::table('tb_diagnoses')->pluck('icd10_code')->flip();

        // view=longterm lists ALL long-term rows like legacy (the long-term registry is mostly
        // discharged episodes); every other view shows open episodes only
        $includeDischarged = ($filters['view'] ?? null) === 'longterm';

        // D1 exemption (J1-7): the legacy long-term and TB pages were UNIT-WIDE — a consultant
        // opening these views sees every consultant's rows; the default board stays own-only
        if (in_array($filters['view'] ?? null, ['longterm', 'tb'], true)) {
            $scope = fn ($q) => $q;
        }

        $admissions = Admission::query()
            ->when(! $includeDischarged, fn ($q) => $q->whereNull('discharge_date'))
            ->whereNotNull('consultant_id')                       // assigned only (unassigned → New Admissions)
            ->tap($scope)
            ->with(['patient:id,mrn,name,gender,age', 'consultant:id,full_name,name,specialty_id,on_service', 'diagnoses:id,admission_id,seq,icd10_code'])
            ->withCount('diagnoses')
            ->when($filters['location'] ?? null, fn ($q, $loc) => $q->where('current_location', $loc))
            ->when(($filters['view'] ?? null) === 'longterm', fn ($q) => $q->where('is_longterm', true))
            ->when(($filters['view'] ?? null) === 'tb', $tbExists)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->whereHas('patient',
                fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%")))
            ->orderBy('admit_date')
            ->get();

        // readmission flag: admitted within the configured window of a prior REAL discharge
        // (typed real discharge OR NULL-typed historical close — legacy parity, J1-4)
        $readmitWindow = max(0, (int) ($settings->readmission_window_days ?? 3));
        $readmitIds = Admission::query()->whereIn('id', $admissions->pluck('id'))
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('admissions as prev')
                ->whereColumn('prev.patient_id', 'admissions.patient_id')->whereColumn('prev.id', '<>', 'admissions.id')
                ->whereColumn('prev.discharge_date', '<=', 'admissions.admit_date')
                ->whereRaw('DATEDIFF(admissions.admit_date, prev.discharge_date) BETWEEN 0 AND ?', [$readmitWindow])
                ->where(fn ($w) => $w->whereIn('prev.transfer_type', Admission::REAL_DISCHARGE_TYPES)
                    ->orWhereNull('prev.transfer_type')))
            ->pluck('id')->flip();

        $newCutoff = now()->subDay();   // "New" = assigned within the last 24h (rolling)

        // handover meta + my pending signatures — two grouped lookups, no per-card queries
        $handovers = \App\Models\Handover::whereIn('admission_id', $admissions->pluck('id'))
            ->with('updatedBy:id,name,full_name')->get()->keyBy('admission_id');
        // sign-pending is matched by PATIENT (a specialty transfer leaves the signature on the
        // closed episode while the board shows the patient's new one)
        $signPendingPatientIds = \App\Models\HandoverSignature::where('to_consultant_id', auth()->id())
            ->whereNull('signed_at')->whereNull('voided_at')
            ->join('admissions', 'admissions.id', '=', 'handover_signatures.admission_id')
            ->pluck('admissions.patient_id')->flip();

        // ICD-10 names for every code on the board — ONE lookup, then an in-memory map
        $boardCodes = $admissions->pluck('diagnoses')->flatten()->pluck('icd10_code')->unique()->values();
        $dxNames = $boardCodes->isEmpty()
            ? collect()
            : DB::table('icd10')->whereIn('code', $boardCodes)->pluck('name', 'code');

        $groups = [];
        foreach ($admissions as $a) {
            $cid = (int) $a->consultant_id;
            if (! isset($groups[$cid])) {
                $groups[$cid] = [
                    'id' => $cid,
                    'name' => $a->consultant?->full_name ?: $a->consultant?->name ?: 'Unknown',
                    'specialty_id' => (int) ($a->consultant?->specialty_id ?? 0),
                    'on_service' => (bool) $a->consultant?->on_service,
                    'patients' => [],
                    'counts' => ['new' => 0, 'old' => 0, 'active' => 0, 'ward' => 0, 'icu' => 0, 'tb' => 0, 'total' => 0],
                ];
            }
            $isTb = $a->diagnoses->contains(fn ($d) => $tbCodes->has($d->icd10_code));
            $isIcu = $a->current_location === 'ICU';
            $los = $a->lengthOfStay();
            $medDischarged = $a->medical_discharge_date !== null;
            $discharged = $a->discharge_date !== null;   // only possible under view=longterm

            $groups[$cid]['patients'][] = [
                'id' => $a->id,
                'name' => $a->patient?->name ?? 'Unknown',
                'mrn' => $a->patient?->mrn,
                'gender' => $a->patient?->gender,
                'age' => $a->patient?->age,
                'bed' => $a->bed,
                'location' => $a->current_location,
                'consultant_id' => $cid,
                'admitted_from' => $a->admitted_from,   // discharge-modal record-review summary (J1-15c)
                'admit_date' => optional($a->admit_date)->toDateString(),
                'los' => $los,
                'los_band' => Admission::losBand($los, $settings),   // shared banding rule (J2-7)
                'dx_count' => $a->diagnoses_count,
                'diagnoses' => $a->diagnoses->sortBy('seq')->values()
                    ->map(fn ($d) => ['code' => $d->icd10_code, 'name' => $dxNames[$d->icd10_code] ?? $d->icd10_code])->all(),
                'is_longterm' => (bool) $a->is_longterm,
                'is_new' => $a->assigned_at !== null && $a->assigned_at->greaterThanOrEqualTo($newCutoff),
                'is_tb' => $isTb,
                'is_readmission' => $readmitIds->has($a->id),
                'medically_discharged' => $medDischarged,
                // closed episode shown on the long-term view — card renders a chip, no actions
                'discharged' => $discharged,
                'discharge_date' => optional($a->discharge_date)->toDateString(),
                // phase-1 values — prefill the complete-discharge modal's optional override selects
                'outcome' => $a->outcome,
                'discharge_to' => $a->discharge_to,
                'handover' => ($h = $handovers->get($a->id)) ? [
                    'updated_at' => $h->updated_at->toIso8601String(),
                    'updated_by' => $h->updatedBy ? ($h->updatedBy->full_name ?: $h->updatedBy->name) : null,
                    'today' => $h->updated_at->isToday(),
                ] : null,
                'sign_pending' => $signPendingPatientIds->has($a->patient_id),
            ];

            $c = &$groups[$cid]['counts'];
            $c['total']++;
            if ($a->assigned_at !== null && $a->assigned_at->greaterThanOrEqualTo($newCutoff)) { $c['new']++; } else { $c['old']++; }
            if ($isIcu) { $c['icu']++; } else { $c['ward']++; }
            if ($isTb) $c['tb']++;
            if (! $discharged && ! $isIcu && ! $medDischarged && ! $a->is_longterm && ! $isTb) $c['active']++;
            unset($c);
        }

        // zero-census on-service consultants get an empty group on the UNFILTERED board only —
        // a search / location / view filter shouldn't drown its hits in empty sections
        if (empty($filters['search']) && empty($filters['location']) && empty($filters['view'])) {
            $zeroCensus = User::where('role', User::ROLE_CONSULTANT)->where('active', 1)->where('on_service', 1)
                ->when($ownOnlyId !== null, fn ($q) => $q->where('id', $ownOnlyId))
                ->get(['id', 'full_name', 'name', 'specialty_id', 'on_service']);
            foreach ($zeroCensus as $u) {
                if (! isset($groups[$u->id])) {
                    $groups[$u->id] = [
                        'id' => (int) $u->id,
                        'name' => $u->full_name ?: $u->name,
                        'specialty_id' => (int) ($u->specialty_id ?? 0),
                        'on_service' => true,
                        'patients' => [],
                        'counts' => ['new' => 0, 'old' => 0, 'active' => 0, 'ward' => 0, 'icu' => 0, 'tb' => 0, 'total' => 0],
                    ];
                }
            }
        }

        // order: on-service hospitalist (specialty 1) → on-service subspecialty → off-service
        $rank = function ($g) {
            if ($g['on_service'] && $g['specialty_id'] === 1) return 0;
            if ($g['on_service']) return 1;
            return 2;
        };
        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => [$rank($a), $a['name']] <=> [$rank($b), $b['name']]);

        return [$groups, $readmitWindow];
    }
}
