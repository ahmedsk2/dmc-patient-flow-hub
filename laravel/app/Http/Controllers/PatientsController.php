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
    public function index(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        // SPC-TM-011 (Wave 1): the free-text term is patient name/MRN — it now travels in a POST
        // body only. A legacy GET-with-term (old bookmark/history entry) is still accepted but
        // redirects to the term-less board, keeping every non-PII filter.
        if ($request->isMethod('get') && trim((string) $request->query('search', '')) !== '') {
            return redirect()->route('patients.index', \Illuminate\Support\Arr::except($request->query(), ['search']));
        }

        $settings = Setting::current();
        // consultant_id / specialty_id are dashboard drill-through filters (Phase 1, Item 3); they
        // apply ON TOP of the D1 consultant scope, never replacing it.
        $filters = $request->only('search', 'location', 'view', 'consultant_id', 'specialty_id', 'needs_handover');
        [$scope, $ownOnlyId] = $this->boardScope($request);
        $tbExists = $this->tbExists();
        [$groups, $readmitWindow] = $this->boardGroups($filters, $settings, $scope, $tbExists, $ownOnlyId);

        // Wave 2, Item 1: discharged/unassigned fall-through. The board only matches active+assigned
        // patients, so a search for a discharged or not-yet-assigned patient silently returns nothing.
        // When a search yielded an EMPTY board, run two cheap COUNTs so the zero-state can say
        // "Found N discharged / M awaiting assignment". The discharged count keeps the D1 scope
        // (a consultant only sees fall-through counts within their own unit); the unassigned queue is
        // global (already so on the board chips) since anyone may grab from it. Computed here BEFORE
        // $scope is broadened for the longterm/TB chips below.
        $fallback = $this->boardFallback($filters, $groups, $scope);

        // chips follow the SAME D1 exemption as boardGroups (J1-7): the longterm/TB views are
        // unit-wide, so their chips must count the unit too — not the viewer's own list (L1-14)
        if (in_array($filters['view'] ?? null, ['longterm', 'tb'], true)) {
            $scope = fn ($q) => $q;
        }

        return Inertia::render('Patients/Index', [
            'groups' => $groups,
            'filters' => $filters,
            'fallback' => $fallback,
            // deep-link target for the incomplete-handover reminder bell (?highlight=<admission_id>).
            // Deliberately NOT part of $filters — it doesn't filter the board, it just tells the
            // client which already-visible admission to expand/scroll/flash to.
            'highlight' => $request->integer('highlight') ?: null,
            'readmitWindow' => $readmitWindow,
            // "needs handover" count (TD-T3): SAME predicate as the needs_handover board filter
            // above (own-only for a plain consultant, unit-wide otherwise) — the chip, the pinned
            // banner and the filtered result must always agree on what "the number" means.
            'needsHandoverCount' => Admission::handoverPending()
                ->when($request->user()->seesOwnPatientsOnly(), fn ($q) => $q->where('consultant_id', $request->user()->id))
                ->count(),
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
                // N1-6: stats.longterm/stats.tb were never read by Patients/Index.vue (the board's
                // per-group counts come from $c['tb'] below) — two unused COUNT queries removed
                'unassigned' => Admission::active()->whereNull('consultant_id')->count(),
            ],
        ]);
    }

    /**
     * Wave 2, Item 1: the discharged/unassigned fall-through counts for the board zero-state.
     * Returns null unless a search was entered AND it matched no active+assigned patient (so the
     * affordance only ever appears when the user is otherwise stuck). The match closure mirrors the
     * board's own search (name OR mrn LIKE). $scope is the D1 closure — a consultant sees discharged
     * counts only within their own unit; the unassigned queue is global (anyone may grab from it).
     *
     * @return array{discharged:int,unassigned:int,search:string}|null
     */
    private function boardFallback(array $filters, array $groups, \Closure $scope): ?array
    {
        $s = trim((string) ($filters['search'] ?? ''));
        if ($s === '' || ! empty($groups)) {
            return null;
        }

        $match = fn ($q) => $q->whereHas('patient',
            fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%"));

        return [
            'discharged' => Admission::whereNotNull('discharge_date')->tap($scope)->tap($match)->count(),
            'unassigned' => Admission::whereNull('discharge_date')->whereNull('consultant_id')->tap($match)->count(),
            'search' => $s,
        ];
    }

    /**
     * Wave 2, Item 2: global patient quick-jump (header). Returns up to 8 results as JSON for the
     * keyboard-first "/" search. Scope rules (PHI-aware, server-enforced — NOT just by blocking the
     * route):
     *   • Admin: all patients (active + discharged). Discharged results route to the admin registry.
     *   • Non-admin (registrar/resident/consultant/observer): ACTIVE episodes only, inside the SAME
     *     D1 scope as the board (consultants get their own group; everyone else gets all active).
     * The match is a prepared LIKE binding (no interpolation). < 2 chars → 422 + [] (client debounces
     * and also guards). This is deliberately NOT the admin-only PatientMergeController::searchPatients
     * (different scope + payload); the shared search shape lives here.
     *
     * Wave 1 (EHC UI): POST-only now (SPC-TM-011 — the term rides the request body). The same
     * endpoint also re-hydrates the palette's RECENTS: given `ids` (opaque admission row ids, max
     * 10) instead of `q`, it returns the same row shape for the ids the SAME scope allows — a
     * recent the viewer can no longer see (or a discharged one, for non-admins) silently drops out.
     * Rows carry `dest` (board|registry) instead of a URL so the client never builds an
     * MRN-bearing query string.
     */
    public function quickSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $u = $request->user();
        $isAdmin = $u->isAdmin();
        [$scope] = $this->boardScope($request);

        $base = Admission::query()
            // admin sees full history; everyone else only open episodes, D1-scoped
            ->when(! $isAdmin, fn ($a) => $a->whereNull('discharge_date')->tap($scope))
            ->with(['patient:id,mrn,name,gender,age', 'consultant:id,full_name,name']);

        $ids = collect((array) $request->input('ids', []))
            ->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->take(10)->values();

        if ($ids->isNotEmpty()) {
            $rows = $base->whereIn('id', $ids)->orderByDesc('admit_date')->get();
        } else {
            $q = trim((string) $request->input('q', ''));
            if (mb_strlen($q) < 2) {
                return response()->json([], 422);
            }
            $like = "%{$q}%";
            $rows = $base
                ->whereHas('patient', fn ($p) => $p->where('name', 'like', $like)->orWhere('mrn', 'like', $like))
                ->orderByDesc('admit_date')
                ->limit(8)
                ->get();
        }

        return response()->json($rows->map(function (Admission $a) {
            $discharged = $a->discharge_date !== null;

            return [
                'id' => $a->id,
                'mrn' => $a->patient?->mrn,
                'name' => $a->patient?->name ?? 'Unknown',
                // identity-ribbon fields (Wave 1): age·sex, location, last-admission date, outcome
                'age' => $a->patient?->age,
                'gender' => $a->patient?->gender,
                'location' => $a->current_location,
                'admit_date' => optional($a->admit_date)->toDateString(),
                'deceased' => $a->outcome === 'Dead',
                'status' => $discharged ? 'discharged' : ($a->consultant_id ? 'active' : 'unassigned'),
                'consultant' => $a->consultant ? ($a->consultant->full_name ?: $a->consultant->name) : null,
                // active/unassigned → the board; discharged (admin-only result) → the registry.
                // The client POSTs the MRN there — no URL ever carries it (SPC-TM-011).
                'dest' => $discharged ? 'registry' : 'board',
            ];
        })->values());
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
        $ownOnly = $u->seesOwnPatientsOnly();

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
     * A consultant appears only while they hold at least one matching admission — a consultant
     * holding zero patients is hidden everywhere (TD-T5), even on-service ones. $ownOnlyId keeps
     * the D1 consultant scope (unused now that the zero-census injection is gone, kept for the
     * shared boardScope() call signature).
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
            // boarding (Phase 1, Item 1): medically cleared, bed still occupied. Open episodes only
            // ($includeDischarged is false here), so whereNotNull(medical_discharge_date) is the rule.
            // It does NOT get the D1 scope exemption (boarding belongs to specific consultants).
            ->when(($filters['view'] ?? null) === 'boarding', fn ($q) => $q->whereNotNull('medical_discharge_date'))
            // drill-through filters (Phase 1, Item 3): applied ON TOP of the D1 scope above
            ->when($filters['consultant_id'] ?? null, fn ($q, $id) => $q->where('consultant_id', (int) $id))
            ->when($filters['specialty_id'] ?? null, fn ($q, $id) =>
                $q->whereHas('consultant', fn ($u) => $u->where('specialty_id', (int) $id)))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->whereHas('patient',
                fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%")))
            // needs-handover filter (TD-T3): active admissions carrying an unresolved transfer-driven reminder
            ->when($filters['needs_handover'] ?? null, fn ($q) => $q->handoverPending())
            ->orderBy('admit_date')
            ->get();

        // readmission flag: admitted within the configured window of a prior REAL discharge
        // (typed real discharge OR NULL-typed historical close — legacy parity, J1-4)
        $readmitWindow = max(0, (int) ($settings->readmission_window_days ?? 3));
        $readmitIds = Admission::query()->whereIn('id', $admissions->pluck('id'))
            ->whereExists(Admission::readmissionExists($readmitWindow))
            ->pluck('id')->flip();

        // "New" = the is_new_assignment flag (set on assign/handover/shuffle, cleared on discharge/
        // reassign) — the managed legacy signal, NOT a rolling 24h assigned_at window (which reads 0
        // on imported data once assignments age past 24h). Keeps the board's New badge/count in step
        // with the dashboard's "Patient count per consultant" table and the legacy app.

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
                'is_new' => (bool) $a->is_new_assignment,
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
                    'checkpoints' => $h->checkpoints,
                ] : null,
                'sign_pending' => $signPendingPatientIds->has($a->patient_id),
            ];

            $c = &$groups[$cid]['counts'];
            $c['total']++;
            if ($a->is_new_assignment) { $c['new']++; } else { $c['old']++; }
            if ($isIcu) { $c['icu']++; } else { $c['ward']++; }
            if ($isTb) $c['tb']++;
            if (! $discharged && ! $isIcu && ! $medDischarged && ! $a->is_longterm && ! $isTb) $c['active']++;
            unset($c);
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
