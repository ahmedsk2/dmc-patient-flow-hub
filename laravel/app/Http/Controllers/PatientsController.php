<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Setting;
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
        $tbCodes = DB::table('tb_diagnoses')->pluck('icd10_code')->flip();

        $tbExists = fn ($q) => $q->whereExists(fn ($sub) => $sub->selectRaw('1')
            ->from('admission_diagnoses as ad')->join('tb_diagnoses as tb', 'tb.icd10_code', '=', 'ad.icd10_code')
            ->whereColumn('ad.admission_id', 'admissions.id'));

        $admissions = Admission::query()
            ->whereNull('discharge_date')
            ->whereNotNull('consultant_id')                       // assigned only (unassigned → New Admissions)
            ->with(['patient:id,mrn,name,gender,age', 'consultant:id,full_name,name,specialty_id,on_service', 'diagnoses:id,admission_id,icd10_code'])
            ->withCount('diagnoses')
            ->when($filters['location'] ?? null, fn ($q, $loc) => $q->where('current_location', $loc))
            ->when(($filters['view'] ?? null) === 'longterm', fn ($q) => $q->where('is_longterm', true))
            ->when(($filters['view'] ?? null) === 'tb', $tbExists)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->whereHas('patient',
                fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%")))
            ->orderBy('admit_date')
            ->get();

        // readmission flag: admitted within the configured window of a prior REAL discharge
        $readmitWindow = max(0, (int) ($settings->readmission_window_days ?? 3));
        $readmitIds = Admission::query()->whereIn('id', $admissions->pluck('id'))
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('admissions as prev')
                ->whereColumn('prev.patient_id', 'admissions.patient_id')->whereColumn('prev.id', '<>', 'admissions.id')
                ->whereColumn('prev.discharge_date', '<=', 'admissions.admit_date')
                ->whereRaw('DATEDIFF(admissions.admit_date, prev.discharge_date) BETWEEN 0 AND ?', [$readmitWindow])
                ->whereIn('prev.transfer_type', ['discharge from ward', 'discharge from ICU']))
            ->pluck('id')->flip();

        $newCutoff = now()->subDay();   // "New" = assigned within the last 24h (rolling)

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
                    'counts' => ['new' => 0, 'active' => 0, 'ward' => 0, 'icu' => 0, 'tb' => 0, 'total' => 0],
                ];
            }
            $isTb = $a->diagnoses->contains(fn ($d) => $tbCodes->has($d->icd10_code));
            $isIcu = $a->current_location === 'ICU';
            $los = $a->lengthOfStay();
            $medDischarged = $a->medical_discharge_date !== null;

            $groups[$cid]['patients'][] = [
                'id' => $a->id,
                'name' => $a->patient?->name ?? 'Unknown',
                'mrn' => $a->patient?->mrn,
                'gender' => $a->patient?->gender,
                'age' => $a->patient?->age,
                'bed' => $a->bed,
                'location' => $a->current_location,
                'consultant_id' => $cid,
                'admit_date' => optional($a->admit_date)->toDateString(),
                'los' => $los,
                'los_band' => $los === null ? null : ($los < $settings->short_los ? 'short' : ($los > $settings->long_los ? 'long' : 'mid')),
                'dx_count' => $a->diagnoses_count,
                'is_longterm' => (bool) $a->is_longterm,
                'is_new' => $a->assigned_at !== null && $a->assigned_at->greaterThanOrEqualTo($newCutoff),
                'is_tb' => $isTb,
                'is_readmission' => $readmitIds->has($a->id),
                'medically_discharged' => $medDischarged,
            ];

            $c = &$groups[$cid]['counts'];
            $c['total']++;
            if ($a->assigned_at !== null && $a->assigned_at->greaterThanOrEqualTo($newCutoff)) $c['new']++;
            if ($isIcu) { $c['icu']++; } else { $c['ward']++; }
            if ($isTb) $c['tb']++;
            if (! $isIcu && ! $medDischarged && ! $a->is_longterm && ! $isTb) $c['active']++;
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

        return Inertia::render('Patients/Index', [
            'groups' => $groups,
            'filters' => $filters,
            'readmitWindow' => $readmitWindow,
            'consultants' => User::where('role', User::ROLE_CONSULTANT)->where('active', 1)
                ->orderBy('full_name')->get(['id', 'full_name', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name ?: $u->name]),
            'stats' => [
                'total' => Admission::active()->whereNotNull('consultant_id')->count(),
                'ward' => Admission::active()->whereNotNull('consultant_id')->nonIcu()->count(),
                'icu' => Admission::active()->whereNotNull('consultant_id')->icu()->count(),
                'longterm' => Admission::active()->whereNotNull('consultant_id')->where('is_longterm', true)->count(),
                'tb' => Admission::active()->whereNotNull('consultant_id')->where($tbExists)->count(),
                'unassigned' => Admission::active()->whereNull('consultant_id')->count(),
            ],
        ]);
    }
}
