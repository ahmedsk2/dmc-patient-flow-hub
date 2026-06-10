<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk import of HISTORICAL admission episodes from pasted CSV (admin). Two-step: PREVIEW parses +
 * validates every row (no writes) and shows valid/invalid with reasons; CONFIRM commits only the
 * valid rows in one transaction. Columns: MRN, Name, Age, Gender, Nationality, AdmitDate,
 * DischargeDate, Outcome, Location.
 */
class ImportController extends Controller
{
    private array $columns = ['MRN', 'Name', 'Age', 'Gender', 'Nationality', 'AdmitDate', 'DischargeDate', 'Outcome', 'Location'];

    public function index(): Response
    {
        return Inertia::render('Import/Index', ['columns' => $this->columns, 'preview' => null, 'rows' => '']);
    }

    /** Step 1 — parse + validate, return a preview WITHOUT writing anything. */
    public function preview(Request $request): Response
    {
        $data = $request->validate(['rows' => ['required', 'string', 'max:500000']]);
        $parsed = $this->parse($data['rows']);

        return Inertia::render('Import/Index', [
            'columns' => $this->columns,
            'rows' => $data['rows'],
            'preview' => [
                'valid' => collect($parsed)->where('ok', true)->count(),
                'invalid' => collect($parsed)->where('ok', false)->count(),
                'sample' => array_slice($parsed, 0, 200),   // cap the table; counts above are full
                'truncated' => count($parsed) > 200,
            ],
        ]);
    }

    /** Step 2 — commit only the valid rows. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['rows' => ['required', 'string', 'max:500000']]);
        $parsed = $this->parse($data['rows']);
        $valid = array_filter($parsed, fn ($r) => $r['ok']);

        DB::transaction(function () use ($valid) {
            foreach ($valid as $r) {
                $patient = Patient::firstOrCreate(['mrn' => $r['mrn']], [
                    'name' => $r['name'], 'gender' => $r['gender'], 'age' => $r['age'], 'nationality' => $r['nationality'],
                ]);
                DB::table('admissions')->insert([
                    'patient_id' => $patient->id,
                    'admit_date' => $r['admit_date'],
                    'discharge_date' => $r['discharge_date'],
                    'outcome' => $r['outcome'],
                    'current_location' => $r['location'],
                    // derive the discharge type from location so ICU imports classify correctly
                    // (feeds the readmission filter + recent-discharge views, which key on these strings)
                    'transfer_type' => $r['discharge_date']
                        ? ($r['location'] === 'ICU' ? 'discharge from ICU' : 'discharge from ward')
                        : null,
                    'admitted_by' => Auth::id(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $n = count($valid);
        $skipped = count($parsed) - $n;
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'import.bulk',
            'entity_type' => 'admission', 'entity_id' => null, 'details' => ['imported' => $n, 'skipped' => $skipped], 'ip' => $request->ip()]);

        return redirect()->route('import.index')->with('flash', ['type' => $n > 0 ? 'success' : 'error',
            'message' => "Imported {$n} admission(s)" . ($skipped ? ", skipped {$skipped} invalid." : '.')]);
    }

    /** @return array<int,array{line:int,ok:bool,error:?string,mrn:?string,name:?string,...}> */
    private function parse(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $out = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') { continue; }
            $c = str_getcsv($line);
            if ($i === 0 && strtolower(trim($c[0] ?? '')) === 'mrn') { continue; } // header

            $mrn = preg_replace('/\D/', '', trim($c[0] ?? ''));
            $name = trim($c[1] ?? '') ?: null;
            $row = [
                'line' => $i + 1,
                'mrn' => $mrn ?: null,
                'name' => $name,
                'age' => is_numeric($c[2] ?? null) ? (int) $c[2] : null,
                'gender' => $this->gender($c[3] ?? null),
                'nationality' => trim($c[4] ?? '') ?: null,
                'admit_date' => $this->date($c[5] ?? null),
                'discharge_date' => $this->date($c[6] ?? null),
                'outcome' => trim($c[7] ?? '') ?: null,
                'location' => trim($c[8] ?? '') ?: 'Ward',
                'ok' => true,
                'error' => null,
            ];
            if ($mrn === '' || mb_strlen($mrn) > 11) {
                $row['ok'] = false; $row['error'] = 'MRN must be 1–11 digits';
            } elseif (! $row['admit_date']) {
                $row['ok'] = false; $row['error'] = 'Missing/invalid admit date';
            }
            $out[] = $row;
        }
        return $out;
    }

    private function date($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        try { return Carbon::parse($v)->toDateString(); } catch (\Throwable $e) { return null; }
    }

    private function gender($v): ?string
    {
        $v = strtolower(trim((string) $v));
        return match (true) {
            in_array($v, ['m', 'male'], true) => 'Male',
            in_array($v, ['f', 'female'], true) => 'Female',
            default => null,
        };
    }
}
