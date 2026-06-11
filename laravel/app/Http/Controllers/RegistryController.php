<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Country;
use App\Models\Icd10;
use App\Models\User;
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

        return Inertia::render('Registry/Index', [
            'mode' => $mode,
            'results' => $results,
            'filters' => $request->only(['search', 'from', 'to', 'outcome', 'location', 'gender', 'nationality',
                'age_from', 'age_to', 'admitted_from', 'discharged_to', 'delay', 'consultant_id', 'longterm',
                'discharged', 'tb', 'readmit72',
                'dx', 'dx_match', 'keyword', 'indication', 'consultation_from', 'to_service', 'signed_only']),
            'options' => [
                // option lists reflect the values actually present in the data (legacy approach),
                // so historical vocabularies (old sources/outcomes) stay filterable
                'outcomes' => $this->distinctValues('outcome'),
                'locations' => ['Ward', 'ICU', 'ER'],
                'admittedFrom' => $this->distinctValues('admitted_from'),
                'dischargedTo' => $this->distinctValues('discharge_to'),
                'delays' => $this->distinctValues('delay_reason'),
                'countries' => Country::orderBy('name')->pluck('name'),
                'consultants' => User::consultantOptions(activeOnly: false),   // registry filters span inactive consultants
                'reasons' => ConsultationReason::orderBy('name')->get(['id', 'name']),
                'readmitWindow' => max(0, (int) (\App\Models\Setting::current()->readmission_window_days ?? 3)),
            ],
        ]);
    }

    private function mode(Request $request): string
    {
        return in_array($request->query('mode'), ['admissions', 'consultations', 'diagnosis'], true)
            ? $request->query('mode') : 'admissions';
    }

    /** DISTINCT non-empty values of an admissions column — registry filter options from live data. */
    private function distinctValues(string $column)
    {
        return Admission::query()->whereNotNull($column)->where($column, '<>', '')
            ->distinct()->orderBy($column)->limit(100)->pluck($column);
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
            ->when($request->input('location'), fn ($q, $l) => $q->where('current_location', $l))
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
            ->when($request->boolean('readmit72'), fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')
                ->from('admissions as prev')->whereColumn('prev.patient_id', 'admissions.patient_id')
                ->whereColumn('prev.id', '<>', 'admissions.id')->whereColumn('prev.discharge_date', '<=', 'admissions.admit_date')
                ->whereRaw('DATEDIFF(admissions.admit_date, prev.discharge_date) BETWEEN 0 AND ?',
                    [max(0, (int) (\App\Models\Setting::current()->readmission_window_days ?? 3))])
                ->whereIn('prev.transfer_type', Admission::REAL_DISCHARGE_TYPES)))
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

    private function admissionResults(Request $request)
    {
        return $this->admissionQuery($request)->paginate(20)->withQueryString()->through(fn (Admission $a) => [
            'id' => $a->id, 'name' => $a->patient?->name ?? 'Unknown', 'mrn' => $a->patient?->mrn,
            'age' => $a->patient?->age, 'gender' => $a->patient?->gender, 'nationality' => $a->patient?->nationality,
            'location' => $a->current_location, 'consultant' => $a->consultant?->full_name ?? $a->consultant?->name ?? '—',
            'admit_date' => optional($a->admit_date)->toDateString(), 'discharge_date' => optional($a->discharge_date)->toDateString(),
            'outcome' => $a->outcome, 'los' => $a->lengthOfStay(), 'status' => $a->discharge_date ? 'Discharged' : 'Active',
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
            ->when($indication, fn ($q) => $q->where(function ($w) use ($indication) {
                foreach ($indication as $id) { $w->orWhereJsonContains('indication', (int) $id); }
            }))
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
        $codes = Icd10::where('name', 'like', "%{$kw}%")->limit(500)->pluck('code');

        return Admission::query()->with(['patient:id,mrn,name,gender,age,nationality', 'consultant:id,full_name,name'])
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('admission_diagnoses as adx')
                ->whereColumn('adx.admission_id', 'admissions.id')->whereIn('adx.icd10_code', $codes))
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate('admit_date', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate('admit_date', '<=', $d))
            ->orderByDesc('admit_date')->orderByDesc('id');
    }

    private function diagnosisResults(Request $request)
    {
        return $this->diagnosisQuery($request)
            ->paginate(20)->withQueryString()->through(fn (Admission $a) => [
                'id' => $a->id, 'name' => $a->patient?->name ?? 'Unknown', 'mrn' => $a->patient?->mrn,
                'age' => $a->patient?->age, 'gender' => $a->patient?->gender, 'location' => $a->current_location,
                'consultant' => $a->consultant?->full_name ?? $a->consultant?->name ?? '—',
                'admit_date' => optional($a->admit_date)->toDateString(), 'discharge_date' => optional($a->discharge_date)->toDateString(),
                'outcome' => $a->outcome, 'los' => $a->lengthOfStay(), 'status' => $a->discharge_date ? 'Discharged' : 'Active',
            ]);
    }

    /* ---------- Exports (mode-aware, de-identified) ---------- */

    public function export(Request $request): StreamedResponse
    {
        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            $this->writeExport($request, fn (array $row) => fputcsv($out, $row));
            fclose($out);
        }, 'dmc-registry-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportXlsx(Request $request): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'reg') . '.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($tmp);
        $this->writeExport($request, fn (array $row) => $writer->addRow(Row::fromValues(
            array_map(fn ($v) => $v ?? '', $row))));
        $writer->close();

        return response()->download($tmp, 'dmc-registry-' . now()->format('Ymd-His') . '.xlsx')->deleteFileAfterSend();
    }

    /**
     * Emit header + rows for the CURRENT mode through $write — shared by CSV and XLSX so the two
     * formats can never drift. Exports are DE-IDENTIFIED (no patient name — legacy parity; MRN is
     * the clinical identifier) and carry the legacy clinical column set.
     */
    private function writeExport(Request $request, callable $write): void
    {
        if ($this->mode($request) === 'consultations') {
            $this->writeConsultationExport($request, $write);

            return;
        }

        $write(['MRN', 'Age', 'Gender', 'Nationality', 'Diagnoses', 'Admitted', 'Admitted From', 'Location',
            'Clinical Discharge Date', 'Discharged', 'Discharged To', 'Outcome', 'Delay Reason', 'Transfer Type',
            'Long-term', 'LOS (d)', 'Consultant', 'Status']);

        $query = $this->mode($request) === 'diagnosis' ? $this->diagnosisQuery($request) : $this->admissionQuery($request);
        $query->chunk(500, function ($chunk) use ($write) {
            // diagnosis names: load the chunk's codes once (one whereIn), not one query per row
            $chunk->load('diagnoses');
            $codes = $chunk->flatMap(fn ($a) => $a->diagnoses->pluck('icd10_code'))->unique()->values();
            $names = $codes->isEmpty() ? collect() : Icd10::whereIn('code', $codes)->pluck('name', 'code');
            foreach ($chunk as $a) {
                $write([
                    (string) $a->patient?->mrn, $a->patient?->age, $a->patient?->gender, $a->patient?->nationality,
                    $a->diagnoses->sortBy('seq')->map(fn ($d) => $names[$d->icd10_code] ?? $d->icd10_code)->implode('; '),
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

    private function writeConsultationExport(Request $request, callable $write): void
    {
        $write(['MRN', 'Age', 'Location', 'From', 'To Service', 'Consultant', 'Date', 'Signoff', 'Reasons']);
        $reasons = ConsultationReason::pluck('name', 'id');
        $this->consultationQuery($request)->chunk(500, function ($chunk) use ($write, $reasons) {
            foreach ($chunk as $c) {
                $write([
                    (string) $c->mrn, $c->age, $c->current_location, $c->consultation_from, $c->to_service,
                    $c->consultant?->full_name ?? $c->consultant?->name,
                    optional($c->consultation_date)->toDateString(), optional($c->signoff_date)->toDateString(),
                    collect($c->indication ?? [])->map(fn ($id) => $reasons[$id] ?? null)->filter()->implode('; '),
                ]);
            }
        });
    }
}
