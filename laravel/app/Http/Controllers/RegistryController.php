<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class RegistryController extends Controller
{
    /** Build the filtered admissions query shared by the page and the CSV export. */
    private function query(Request $request)
    {
        $f = $request->only('search', 'from', 'to', 'outcome', 'location');

        return Admission::query()
            ->with(['patient:id,mrn,name,gender,age', 'consultant:id,full_name,name'])
            ->when($f['search'] ?? null, fn ($q, $s) => $q->whereHas('patient',
                fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%")))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('admit_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('admit_date', '<=', $d))
            ->when($f['outcome'] ?? null, fn ($q, $o) => $q->where('outcome', $o))
            ->when($f['location'] ?? null, fn ($q, $l) => $q->where('current_location', $l))
            ->orderByDesc('admit_date')->orderByDesc('id');
    }

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'from', 'to', 'outcome', 'location');

        $results = $this->query($request)->paginate(20)->withQueryString()->through(fn (Admission $a) => [
            'id' => $a->id,
            'name' => $a->patient?->name ?? 'Unknown',
            'mrn' => $a->patient?->mrn,
            'age' => $a->patient?->age,
            'gender' => $a->patient?->gender,
            'location' => $a->current_location,
            'consultant' => $a->consultant?->full_name ?? $a->consultant?->name ?? '—',
            'admit_date' => optional($a->admit_date)->toDateString(),
            'discharge_date' => optional($a->discharge_date)->toDateString(),
            'outcome' => $a->outcome,
            'los' => $a->lengthOfStay(),
            'status' => $a->discharge_date ? 'Discharged' : 'Active',
        ]);

        return Inertia::render('Registry/Index', [
            'results' => $results,
            'filters' => $filters,
            'outcomes' => ['Alive', 'Dead', 'LAMA', 'DAMA', 'Transferred'],
            'total' => $results->total(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'dmc-registry-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['MRN', 'Name', 'Age', 'Gender', 'Location', 'Consultant', 'Admitted', 'Discharged', 'LOS (d)', 'Outcome', 'Status']);
            $this->query($request)->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $a) {
                    fputcsv($out, [
                        $a->patient?->mrn, $a->patient?->name, $a->patient?->age, $a->patient?->gender,
                        $a->current_location, $a->consultant?->full_name ?? $a->consultant?->name,
                        optional($a->admit_date)->toDateString(), optional($a->discharge_date)->toDateString(),
                        $a->lengthOfStay(), $a->outcome, $a->discharge_date ? 'Discharged' : 'Active',
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
