<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Country;
use App\Models\Icd10;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registry — three search modes (Admissions, Consultations, Free-text Diagnosis) with the legacy
 * filter set, plus Excel/CSV export and edit-from-registry (reuses the Modify endpoint).
 */
class RegistryController extends Controller
{
    public function index(Request $request): Response
    {
        $mode = $this->mode($request);

        $results = match ($mode) {
            'consultations' => $this->consultationResults($request),
            'diagnosis' => $this->diagnosisResults($request),
            default => $this->admissionResults($request),
        };

        // PHI-read logging (Phase 2 — Item 3): every registry search that returns patient-
        // identifiable data is logged ONCE per request — actor + applied filters (free-text terms
        // redacted to a length) + result count. Break-glass intent: zero-result searches log too.
        Audit::log('registry.search', 'registry', null, [
            'mode' => $mode,
            'filters' => $this->redactedFilters($request),
            'result_count' => $results->total(),
        ]);

        return Inertia::render('Registry/Index', [
            'mode' => $mode,
            'results' => $results,
            'filters' => $request->only(['search', 'from', 'to', 'outcome', 'location', 'gender', 'nationality',
                'age_from', 'age_to', 'admitted_from', 'discharged_to', 'delay', 'consultant_id', 'longterm',
                'discharged', 'tb', 'readmit72',
                'dx', 'dx_match', 'keyword', 'indication', 'ind_match', 'consultation_from', 'to_service', 'signed_only']),
            'options' => [
                // option lists reflect the values actually present in the data (legacy approach),
                // so historical vocabularies (old sources/outcomes) stay filterable
                'outcomes' => $this->distinctValues('outcome'),
                'locations' => ['Ward', 'ICU', 'ER', 'Non-ICU'],   // Non-ICU = legacy year-extract preset (ward+ER+unset)
                'admittedFrom' => $this->distinctValues('admitted_from'),
                'dischargedTo' => $this->distinctValues('discharge_to'),
                'delays' => $this->distinctValues('delay_reason'),
                'countries' => Country::orderBy('name')->pluck('name'),
                'consultants' => User::consultantOptions(activeOnly: false),   // registry filters span inactive consultants
                'reasons' => ConsultationReason::orderBy('name')->get(['id', 'name']),
                'readmitWindow' => max(0, (int) (\App\Models\Setting::current()->readmission_window_days ?? 3)),
                // N1-2: resolve the ACTIVE dx-filter codes to names so the page can rebuild the
                // removable diagnosis chips after pagination/reload (selectedDx is client-only ref)
                'dxNames' => $this->dxFilterNames($request),
            ],
        ]);
    }

    private function mode(Request $request): string
    {
        return in_array($request->query('mode'), ['admissions', 'consultations', 'diagnosis'], true)
            ? $request->query('mode') : 'admissions';
    }

    /**
     * The applied filters for the PHI-read audit detail (Item 3): the free-text PHI fields (search
     * name/MRN, diagnosis keyword) are reduced to a length-only marker so the audit trail records
     * THAT a search happened without storing the searched name/MRN itself; all other filters are
     * non-PHI (dates, outcome enums, consultant IDs) and kept verbatim for investigative value.
     * Empty/false/unset filters are dropped.
     */
    private function redactedFilters(Request $request): array
    {
        $f = $request->only(['search', 'from', 'to', 'outcome', 'location', 'gender', 'nationality',
            'age_from', 'age_to', 'admitted_from', 'discharged_to', 'delay', 'consultant_id', 'longterm',
            'discharged', 'tb', 'readmit72',
            'dx', 'dx_match', 'keyword', 'indication', 'ind_match', 'consultation_from', 'to_service', 'signed_only']);

        foreach (['search', 'keyword'] as $phi) {
            if (! empty($f[$phi])) {
                $f[$phi] = mb_strlen((string) $f[$phi]) . ' chars';
            }
        }

        return array_filter($f, fn ($v) => $v !== null && $v !== '' && $v !== [] && $v !== false && $v !== '0');
    }

    /** DISTINCT non-empty values of an admissions column — registry filter options from live data. */
    private function distinctValues(string $column)
    {
        return Admission::query()->whereNotNull($column)->where($column, '<>', '')
            ->distinct()->orderBy($column)->limit(100)->pluck($column);
    }

    /**
     * [{code, name}] for each ICD-10 code currently in the dx filter — lets the page rebuild the
     * removable diagnosis chips on a paginated/reloaded visit (an unresolved code falls back to
     * its own code as the label so it stays removable). N1-2.
     */
    private function dxFilterNames(Request $request): array
    {
        $codes = array_values(array_unique(array_filter((array) $request->input('dx', []))));
        if (! $codes) {
            return [];
        }
        $names = Icd10::whereIn('code', $codes)->pluck('name', 'code');

        return array_map(fn ($code) => ['code' => $code, 'name' => $names[$code] ?? $code], $codes);
    }

    /* ---------- Admissions ---------- */

    private function admissionQuery(Request $request)
    {
        $dx = array_filter((array) $request->input('dx', []));
        return Admission::query()
            ->with(['patient:id,mrn,name,gender,age,nationality', 'consultant:id,full_name,name'])
            ->when($request->input('search'), fn ($q, $s) => $q->whereHas('patient', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%")))
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate('admit_date', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate('admit_date', '<=', $d))
            ->when($request->input('outcome'), fn ($q, $o) => $q->where('outcome', $o))
            ->when($request->input('location'), fn ($q, $l) => $l === 'Non-ICU'
                ? $q->whereRaw(Admission::NON_ICU_SQL)   // legacy year-extract preset: ward + ER + unset
                : $q->where('current_location', $l))
            ->when($request->input('admitted_from'), fn ($q, $a) => $q->where('admitted_from', $a))
            ->when($request->input('discharged_to'), fn ($q, $d) => $q->where('discharge_to', $d))
            ->when($request->input('delay'), fn ($q, $d) => $q->where('delay_reason', $d))
            ->when($request->input('consultant_id'), fn ($q, $c) => $q->where('consultant_id', $c))
            ->when($request->input('gender'), fn ($q, $g) => $q->whereHas('patient', fn ($p) => $p->where('gender', $g)))
            ->when($request->input('nationality'), fn ($q, $n) => $q->whereHas('patient', fn ($p) => $p->where('nationality', $n)))
            ->when($request->input('age_from'), fn ($q, $a) => $q->whereHas('patient', fn ($p) => $p->where('age', '>=', (int) $a)))
            ->when($request->input('age_to'), fn ($q, $a) => $q->whereHas('patient', fn ($p) => $p->where('age', '<=', (int) $a)))
            ->when($request->boolean('longterm'), fn ($q) => $q->where('is_longterm', true))
            ->when($request->boolean('discharged'), fn ($q) => $q->whereNotNull('discharge_date'))
            ->when($request->boolean('tb'), fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')
                ->from('admission_diagnoses as adt')->join('tb_diagnoses as tb', 'tb.icd10_code', '=', 'adt.icd10_code')
                ->whereColumn('adt.admission_id', 'admissions.id')))
            // prior REAL discharge (typed OR NULL-typed historical close — legacy parity, J1-4)
            ->when($request->boolean('readmit72'), fn ($q) => $q->whereExists(
                Admission::readmissionExists(
                    max(0, (int) (\App\Models\Setting::current()->readmission_window_days ?? 3))
                )
            ))
            ->when($dx, function ($q) use ($dx, $request) {
                if ($request->input('dx_match') === 'and') {
                    foreach ($dx as $code) {
                        $q->whereExists(fn ($s) => $s->selectRaw('1')->from('admission_diagnoses as adx')
                            ->whereColumn('adx.admission_id', 'admissions.id')->where('adx.icd10_code', $code));
                    }
                } else {
                    $q->whereExists(fn ($s) => $s->selectRaw('1')->from('admission_diagnoses as adx')
                        ->whereColumn('adx.admission_id', 'admissions.id')->whereIn('adx.icd10_code', $dx));
                }
            })
            ->orderByDesc('admit_date')->orderByDesc('id');
    }

    /** transfer_type -> human transfer label (real discharges get NO label). */
    private const TRANSFER_LABELS = [
        'transfer to other speciality' => 'Intra-dept transfer',
        'other transfer' => 'Out-dept transfer',
        'Transfer from ICU' => 'Back from ICU',
    ];

    private function admissionResults(Request $request)
    {
        $page = $this->admissionQuery($request)->paginate(20)->withQueryString();

        // expandable-row detail payload, computed for the CURRENT PAGE only (≤20 rows):
        // one diagnoses load + one icd10 name lookup, one users lookup (admitted_by/discharged_by),
        // one readmit whereIn-exists, one TB whereIn — no per-row queries.
        $rows = $page->getCollection();
        $ids = $rows->pluck('id');
        $rows->load('diagnoses:id,admission_id,seq,icd10_code');
        $codes = $rows->flatMap(fn ($a) => $a->diagnoses->pluck('icd10_code'))->unique()->values();
        $dxNames = $codes->isEmpty() ? collect() : Icd10::whereIn('code', $codes)->pluck('name', 'code');

        $userIds = $rows->pluck('admitted_by')->merge($rows->pluck('discharged_by'))->filter()->unique()->values();
        $userNames = $userIds->isEmpty() ? collect() : User::whereIn('id', $userIds)
            ->get(['id', 'full_name', 'name'])->mapWithKeys(fn ($u) => [$u->id => $u->full_name ?: $u->name]);

        $window = max(0, (int) (\App\Models\Setting::current()->readmission_window_days ?? 3));
        $readmitIds = $ids->isEmpty() ? collect() : Admission::whereIn('id', $ids)
            ->whereExists(Admission::readmissionExists($window))
            ->pluck('id')->flip();

        $tbIds = $ids->isEmpty() ? collect() : \Illuminate\Support\Facades\DB::table('admission_diagnoses as ad')
            ->join('tb_diagnoses as tb', 'tb.icd10_code', '=', 'ad.icd10_code')
            ->whereIn('ad.admission_id', $ids)->distinct()->pluck('ad.admission_id')->flip();

        $settings = \App\Models\Setting::current();   // LOS bands, same rule as the board (J2-7)

        return $page->through(fn (Admission $a) => [
            'id' => $a->id, 'name' => $a->patient?->name ?? 'Unknown', 'mrn' => $a->patient?->mrn,
            'age' => $a->patient?->age, 'gender' => $a->patient?->gender, 'nationality' => $a->patient?->nationality,
            'location' => $a->current_location, 'consultant' => $a->consultant?->full_name ?? $a->consultant?->name ?? '—',
            'admit_date' => optional($a->admit_date)->toDateString(), 'discharge_date' => optional($a->discharge_date)->toDateString(),
            'outcome' => $a->outcome, 'los' => $a->lengthOfStay(), 'status' => $a->discharge_date ? 'Discharged' : 'Active',
            'los_band' => Admission::losBand($a->lengthOfStay(), $settings),
            // detail-panel fields
            'diagnoses' => $a->diagnoses->sortBy('seq')->values()
                ->map(fn ($d) => ['code' => $d->icd10_code, 'name' => $dxNames[$d->icd10_code] ?? $d->icd10_code])->all(),
            'admitted_by' => $a->admitted_by ? ($userNames[$a->admitted_by] ?? null) : null,
            'discharged_by' => $a->discharged_by ? ($userNames[$a->discharged_by] ?? null) : null,
            'admitted_from' => $a->admitted_from,
            'medical_discharge_date' => optional($a->medical_discharge_date)->toDateString(),
            'discharge_to' => $a->discharge_to,
            'delay_reason' => $a->delay_reason,
            'transfer_label' => self::TRANSFER_LABELS[$a->transfer_type] ?? null,
            // badges
            'is_tb' => $tbIds->has($a->id),
            'is_readmission' => $readmitIds->has($a->id),
            'is_longterm' => (bool) $a->is_longterm,
            'disch_still_in' => $a->medical_discharge_date !== null && $a->discharge_date === null,
        ]);
    }

    /* ---------- Consultations ---------- */

    private function consultationQuery(Request $request)
    {
        $indication = array_filter((array) $request->input('indication', []));

        return Consultation::query()->with('consultant:id,full_name,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('patient_name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%")))
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate('consultation_date', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate('consultation_date', '<=', $d))
            ->when($request->input('consultation_from'), fn ($q, $f) => $q->where('consultation_from', 'like', "%{$f}%"))
            ->when($request->input('to_service'), fn ($q, $t) => $q->where('to_service', 'like', "%{$t}%"))
            ->when($request->input('consultant_id'), fn ($q, $c) => $q->where('consultant_id', $c))
            ->when($request->input('age_from'), fn ($q, $a) => $q->where('age', '>=', (int) $a))
            ->when($request->input('age_to'), fn ($q, $a) => $q->where('age', '<=', (int) $a))
            ->when($request->boolean('signed_only'), fn ($q) => $q->whereNotNull('signoff_date'))
            // ind_match=and: EVERY selected indication must be present (chained both-type matches);
            // default 'or': any selected indication matches (same pattern as dx_match in admissions).
            // Each id is matched as BOTH JSON types: app rows store ints ([1,3]) but legacy-imported
            // rows store strings (["1","3"]) — a single-typed whereJsonContains misses half (J1-2).
            ->when($indication, function ($q) use ($indication, $request) {
                $both = fn ($w, $id) => $w->whereJsonContains('indication', (int) $id)
                    ->orWhereJsonContains('indication', (string) $id);
                if ($request->input('ind_match') === 'and') {
                    foreach ($indication as $id) { $q->where(fn ($w) => $both($w, $id)); }
                } else {
                    $q->where(function ($w) use ($indication, $both) {
                        foreach ($indication as $id) { $w->orWhere(fn ($x) => $both($x, $id)); }
                    });
                }
            })
            ->orderByDesc('consultation_date')->orderByDesc('id');
    }

    private function consultationResults(Request $request)
    {
        $reasons = ConsultationReason::pluck('name', 'id');

        return $this->consultationQuery($request)
            ->paginate(20)->withQueryString()->through(fn (Consultation $c) => [
                'id' => $c->id, 'name' => $c->patient_name ?? 'Unknown', 'mrn' => $c->mrn, 'age' => $c->age,
                'location' => $c->current_location, 'from' => $c->consultation_from, 'to' => $c->to_service,
                'consultant' => $c->consultant?->full_name ?? $c->consultant?->name ?? '—',
                'date' => optional($c->consultation_date)->toDateString(), 'signoff' => optional($c->signoff_date)->toDateString(),
                'reasons' => collect($c->indication ?? [])->map(fn ($id) => $reasons[$id] ?? null)->filter()->values(),
            ]);
    }

    /* ---------- Free-text diagnosis ---------- */

    private function diagnosisQuery(Request $request)
    {
        $kw = trim((string) $request->input('keyword', ''));
        if (mb_strlen($kw) < 2) {
            return Admission::query()->whereRaw('1=0');
        }
        // uncapped (J1-6): a broad keyword may match >500 ICD-10 codes — capping silently
        // dropped admissions from the page AND the export
        $codes = Icd10::where('name', 'like', "%{$kw}%")->pluck('code');

        return Admission::query()->with(['patient:id,mrn,name,gender,age,nationality', 'consultant:id,full_name,name'])
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('admission_diagnoses as adx')
                ->whereColumn('adx.admission_id', 'admissions.id')->whereIn('adx.icd10_code', $codes))
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate('admit_date', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate('admit_date', '<=', $d))
            ->orderByDesc('admit_date')->orderByDesc('id');
    }

    private function diagnosisResults(Request $request)
    {
        $settings = \App\Models\Setting::current();   // LOS bands, same rule as the board (J2-7)

        return $this->diagnosisQuery($request)
            ->paginate(20)->withQueryString()->through(fn (Admission $a) => [
                'id' => $a->id, 'name' => $a->patient?->name ?? 'Unknown', 'mrn' => $a->patient?->mrn,
                'age' => $a->patient?->age, 'gender' => $a->patient?->gender, 'location' => $a->current_location,
                'consultant' => $a->consultant?->full_name ?? $a->consultant?->name ?? '—',
                'admit_date' => optional($a->admit_date)->toDateString(), 'discharge_date' => optional($a->discharge_date)->toDateString(),
                'outcome' => $a->outcome, 'los' => $a->lengthOfStay(), 'status' => $a->discharge_date ? 'Discharged' : 'Active',
                'los_band' => Admission::losBand($a->lengthOfStay(), $settings),
            ]);
    }

    /* ---------- Exports (mode-aware, de-identified) ---------- */

    /** Legacy export filename — downstream spreadsheets key on Export-DD-MM-YYYY (C6). */
    private function exportFilename(string $ext): string
    {
        return 'Export-' . now()->format('d-m-Y') . '.' . $ext;
    }

    /** Row count of the CURRENT export mode's query — one cheap COUNT for the PHI-read audit detail. */
    private function exportRowCount(Request $request): int
    {
        $mode = $this->mode($request);
        $query = match ($mode) {
            'consultations' => $this->consultationQuery($request),
            'diagnosis' => $this->diagnosisQuery($request),
            default => $this->admissionQuery($request),
        };

        return $query->count();
    }

    /** One break-glass audit row per export request (Item 3): mode + redacted filters + row count. */
    private function logExport(Request $request, string $action): void
    {
        Audit::log($action, 'registry', null, [
            'mode' => $this->mode($request),
            'filters' => $this->redactedFilters($request),
            'row_count' => $this->exportRowCount($request),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->logExport($request, 'registry.export');

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            $this->writeExport($request, fn (array $row) => fputcsv($out, array_map(self::csvSafe(...), $row)));
            fclose($out);
        }, $this->exportFilename('csv'), ['Content-Type' => 'text/csv']);
    }

    /**
     * Neutralize spreadsheet formula injection in the CSV (Excel evaluates cells starting with
     * = + - @): prefix them with an apostrophe — legacy $safeCell parity
     * (registry/search-results-diagnosis.php:91-94). CSV ONLY: the XLSX writer emits typed
     * strings, which spreadsheet apps never evaluate.
     */
    private static function csvSafe(mixed $v): mixed
    {
        return preg_match('/^[=+\-@]/', (string) ($v ?? '')) ? "'" . $v : $v;
    }

    public function exportXlsx(Request $request): BinaryFileResponse
    {
        $this->logExport($request, 'registry.export_xlsx');

        $tmp = tempnam(sys_get_temp_dir(), 'reg') . '.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($tmp);
        $this->writeExport($request, fn (array $row) => $writer->addRow(Row::fromValues(
            array_map(fn ($v) => $v ?? '', $row))));
        $writer->close();

        return response()->download($tmp, $this->exportFilename('xlsx'))->deleteFileAfterSend();
    }

    /**
     * Emit header + rows for the CURRENT mode through $write — shared by CSV and XLSX so the two
     * formats can never drift. Exports are DE-IDENTIFIED (no patient name — legacy parity; MRN is
     * the clinical identifier) and carry the legacy clinical column set.
     *
     * Legacy downstream compatibility (C6): data VALUES are UPPERCASED and diagnoses joined with
     * ' || ' exactly as registry/export-results-exel.php produced them (header row keeps its
     * clinical Title Case — the legacy header row was never uppercased either).
     */
    private function writeExport(Request $request, callable $write): void
    {
        // uppercase string VALUES only — headers go through $write directly
        $emit = fn (array $row) => $write(array_map(
            fn ($v) => is_string($v) ? mb_strtoupper($v, 'UTF-8') : $v, $row));

        if ($this->mode($request) === 'consultations') {
            $this->writeConsultationExport($request, $write, $emit);

            return;
        }

        $write(['MRN', 'Age', 'Gender', 'Nationality', 'Diagnoses', 'Admitted', 'Admitted From', 'Location',
            'Clinical Discharge Date', 'Discharged', 'Discharged To', 'Outcome', 'Delay Reason', 'Transfer Type',
            'Long-term', 'LOS (d)', 'Consultant', 'Status']);

        $query = $this->mode($request) === 'diagnosis' ? $this->diagnosisQuery($request) : $this->admissionQuery($request);
        $query->chunk(500, function ($chunk) use ($emit) {
            // diagnosis names: load the chunk's codes once (one whereIn), not one query per row
            $chunk->load('diagnoses');
            $codes = $chunk->flatMap(fn ($a) => $a->diagnoses->pluck('icd10_code'))->unique()->values();
            $names = $codes->isEmpty() ? collect() : Icd10::whereIn('code', $codes)->pluck('name', 'code');
            foreach ($chunk as $a) {
                $emit([
                    (string) $a->patient?->mrn, $a->patient?->age, $a->patient?->gender, $a->patient?->nationality,
                    $a->diagnoses->sortBy('seq')->map(fn ($d) => $names[$d->icd10_code] ?? $d->icd10_code)->implode(' || '),
                    optional($a->admit_date)->toDateString(), $a->admitted_from, $a->current_location,
                    optional($a->medical_discharge_date)->toDateString(), optional($a->discharge_date)->toDateString(),
                    $a->discharge_to, $a->outcome, $a->delay_reason, $a->transfer_type,
                    $a->is_longterm ? 'Yes' : '',
                    $a->lengthOfStay(), $a->consultant?->full_name ?? $a->consultant?->name,
                    $a->discharge_date ? 'Discharged' : 'Active',
                ]);
            }
        });
    }

    private function writeConsultationExport(Request $request, callable $write, callable $emit): void
    {
        $write(['MRN', 'Age', 'Location', 'From', 'To Service', 'Consultant', 'Date', 'Signoff', 'Reasons']);
        $reasons = ConsultationReason::pluck('name', 'id');
        $this->consultationQuery($request)->chunk(500, function ($chunk) use ($emit, $reasons) {
            foreach ($chunk as $c) {
                $emit([
                    (string) $c->mrn, $c->age, $c->current_location, $c->consultation_from, $c->to_service,
                    $c->consultant?->full_name ?? $c->consultant?->name,
                    optional($c->consultation_date)->toDateString(), optional($c->signoff_date)->toDateString(),
                    collect($c->indication ?? [])->map(fn ($id) => $reasons[$id] ?? null)->filter()->implode('; '),
                ]);
            }
        });
    }
}
