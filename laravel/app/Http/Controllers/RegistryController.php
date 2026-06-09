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
        $mode = in_array($request->query('mode'), ['admissions', 'consultations', 'diagnosis'], true)
            ? $request->query('mode') : 'admissions';

        $results = match ($mode) {
            'consultations' => $this->consultationResults($request),
            'diagnosis' => $this->diagnosisResults($request),
            default => $this->admissionResults($request),
        };

        return Inertia::render('Registry/Index', [
            'mode' => $mode,
            'results' => $results,
            'filters' => $request->only(['search', 'from', 'to', 'outcome', 'location', 'gender', 'nationality',
                'age_from', 'age_to', 'admitted_from', 'consultant_id', 'longterm', 'discharged', 'tb', 'readmit72',
                'dx', 'dx_match', 'keyword', 'indication', 'consultation_from', 'to_service', 'signed_only']),
            'options' => [
                'outcomes' => ['Alive', 'Dead', 'LAMA', 'DAMA', 'Transferred'],
                'locations' => ['Ward', 'ICU', 'ER'],
                'admittedFrom' => ['ER', 'Clinic', 'Referral', 'Transfer', 'Direct'],
                'countries' => Country::orderBy('name')->pluck('name'),
                'consultants' => User::where('role', User::ROLE_CONSULTANT)->orderBy('full_name')
                    ->get(['id', 'full_name', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name ?: $u->name]),
                'reasons' => ConsultationReason::orderBy('name')->get(['id', 'name']),
            ],
        ]);
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
                ->whereRaw('DATEDIFF(admissions.admit_date, prev.discharge_date) BETWEEN 0 AND 3')
                ->whereIn('prev.transfer_type', ['discharge from ward', 'discharge from ICU'])))
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

    private function consultationResults(Request $request)
    {
        $reasons = ConsultationReason::pluck('name', 'id');
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
            ->orderByDesc('consultation_date')->orderByDesc('id')
            ->paginate(20)->withQueryString()->through(fn (Consultation $c) => [
                'id' => $c->id, 'name' => $c->patient_name ?? 'Unknown', 'mrn' => $c->mrn, 'age' => $c->age,
                'location' => $c->current_location, 'from' => $c->consultation_from, 'to' => $c->to_service,
                'consultant' => $c->consultant?->full_name ?? $c->consultant?->name ?? '—',
                'date' => optional($c->consultation_date)->toDateString(), 'signoff' => optional($c->signoff_date)->toDateString(),
                'reasons' => collect($c->indication ?? [])->map(fn ($id) => $reasons[$id] ?? null)->filter()->values(),
            ]);
    }

    /* ---------- Free-text diagnosis ---------- */

    private function diagnosisResults(Request $request)
    {
        $kw = trim((string) $request->input('keyword', ''));
        if (mb_strlen($kw) < 2) {
            return Admission::query()->whereRaw('1=0')->paginate(20)->withQueryString();
        }
        $codes = Icd10::where('name', 'like', "%{$kw}%")->limit(500)->pluck('code');

        return Admission::query()->with(['patient:id,mrn,name,gender,age', 'consultant:id,full_name,name'])
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('admission_diagnoses as adx')
                ->whereColumn('adx.admission_id', 'admissions.id')->whereIn('adx.icd10_code', $codes))
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate('admit_date', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate('admit_date', '<=', $d))
            ->orderByDesc('admit_date')->orderByDesc('id')
            ->paginate(20)->withQueryString()->through(fn (Admission $a) => [
                'id' => $a->id, 'name' => $a->patient?->name ?? 'Unknown', 'mrn' => $a->patient?->mrn,
                'age' => $a->patient?->age, 'gender' => $a->patient?->gender, 'location' => $a->current_location,
                'consultant' => $a->consultant?->full_name ?? $a->consultant?->name ?? '—',
                'admit_date' => optional($a->admit_date)->toDateString(), 'discharge_date' => optional($a->discharge_date)->toDateString(),
                'outcome' => $a->outcome, 'los' => $a->lengthOfStay(), 'status' => $a->discharge_date ? 'Discharged' : 'Active',
            ]);
    }

    /* ---------- ICD-10 typeahead for the diagnosis filter ---------- */

    public function icd10(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        return response()->json(Icd10::where('code', 'like', "{$q}%")->orWhere('name', 'like', "%{$q}%")
            ->limit(20)->get(['code', 'name'])->map(fn ($r) => ['code' => $r->code, 'name' => $r->name]));
    }

    /* ---------- Exports (admissions mode) ---------- */

    public function export(Request $request): StreamedResponse
    {
        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['MRN', 'Name', 'Age', 'Gender', 'Nationality', 'Location', 'Consultant', 'Admitted', 'Discharged', 'LOS (d)', 'Outcome', 'Status']);
            $this->admissionQuery($request)->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $a) {
                    fputcsv($out, [$a->patient?->mrn, $a->patient?->name, $a->patient?->age, $a->patient?->gender, $a->patient?->nationality,
                        $a->current_location, $a->consultant?->full_name ?? $a->consultant?->name,
                        optional($a->admit_date)->toDateString(), optional($a->discharge_date)->toDateString(),
                        $a->lengthOfStay(), $a->outcome, $a->discharge_date ? 'Discharged' : 'Active']);
                }
            });
            fclose($out);
        }, 'dmc-registry-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportXlsx(Request $request): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'reg') . '.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues(['MRN', 'Name', 'Age', 'Gender', 'Nationality', 'Location', 'Consultant', 'Admitted', 'Discharged', 'LOS (d)', 'Outcome', 'Status']));
        $this->admissionQuery($request)->chunk(500, function ($chunk) use ($writer) {
            foreach ($chunk as $a) {
                $writer->addRow(Row::fromValues([(string) $a->patient?->mrn, $a->patient?->name, $a->patient?->age, $a->patient?->gender, $a->patient?->nationality,
                    $a->current_location, $a->consultant?->full_name ?? $a->consultant?->name,
                    optional($a->admit_date)->toDateString(), optional($a->discharge_date)->toDateString(),
                    $a->lengthOfStay(), $a->outcome, $a->discharge_date ? 'Discharged' : 'Active']));
            }
        });
        $writer->close();

        return response()->download($tmp, 'dmc-registry-' . now()->format('Ymd-His') . '.xlsx')->deleteFileAfterSend();
    }
}
