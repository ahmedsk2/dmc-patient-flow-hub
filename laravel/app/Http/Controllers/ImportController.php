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
 * DischargeDate, Outcome, Location — plus OPTIONAL trailing clinical columns:
 * Diagnoses (pipe-separated ICD-10 codes), Consultant (matched by full name / display name /
 * username among consultant-role users; unmatched stays a valid row with a preview warning),
 * DischargedTo, ClinicalDischargeDate (Y-m-d) — Gap Wave 4b — and AdmittedFrom, Bed,
 * DelayReason, LongTerm (1/yes) — final sweep G1. Old shorter rows remain fully valid.
 */
class ImportController extends Controller
{
    private array $columns = ['MRN', 'Name', 'Age', 'Gender', 'Nationality', 'AdmitDate', 'DischargeDate', 'Outcome', 'Location',
        'Diagnoses', 'Consultant', 'DischargedTo', 'ClinicalDischargeDate', 'AdmittedFrom', 'Bed', 'DelayReason', 'LongTerm'];

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
                $admissionId = DB::table('admissions')->insertGetId([
                    'patient_id' => $patient->id,
                    'admit_date' => $r['admit_date'],
                    'discharge_date' => $r['discharge_date'],
                    'medical_discharge_date' => $r['medical_discharge_date'],
                    'discharge_to' => $r['discharged_to'],
                    'outcome' => $r['outcome'],
                    'current_location' => $r['location'],
                    'admitted_from' => $r['admitted_from'],
                    'bed' => $r['bed'],
                    'delay_reason' => $r['delay_reason'],
                    'is_longterm' => $r['is_longterm'],
                    // historical assignment: set the consultant only — no is_new_assignment/assigned_at
                    // (those drive the live "New" badge / 24h window, which must not fire for imports)
                    'consultant_id' => $r['consultant_id'],
                    // derive the discharge type from location so ICU imports classify correctly
                    // (feeds the readmission filter + recent-discharge views, which key on these strings)
                    'transfer_type' => $r['discharge_date']
                        ? ($r['location'] === 'ICU' ? 'discharge from ICU' : 'discharge from ward')
                        : null,
                    'admitted_by' => Auth::id(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $seq = 1;
                foreach ($r['diagnoses'] as $code) {
                    DB::table('admission_diagnoses')->insert([
                        'admission_id' => $admissionId, 'seq' => $seq++, 'icd10_code' => $code,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });

        $n = count($valid);
        $skipped = count($parsed) - $n;
        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'import.bulk',
            'entity_type' => 'admission', 'entity_id' => null, 'details' => ['imported' => $n, 'skipped' => $skipped], 'ip' => $request->ip()]);

        return redirect()->route('import.index')->with('flash', ['type' => $n > 0 ? 'success' : 'error',
            'message' => "Imported {$n} admission(s)" . ($skipped ? ", skipped {$skipped} invalid." : '.')]);
    }

    /** @return array<int,array{line:int,ok:bool,error:?string,warning:?string,mrn:?string,name:?string,...}> */
    private function parse(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $consultants = null;   // lazy — only loaded when a row actually carries column 11
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
                // optional trailing clinical columns (Gap Wave 4b) — absent in 9-column rows
                'diagnoses' => collect(explode('|', trim($c[9] ?? '')))->map(fn ($x) => trim($x))
                    ->filter()->unique()->values()->all(),
                'consultant_name' => trim($c[10] ?? '') ?: null,
                'consultant_id' => null,
                'discharged_to' => trim($c[11] ?? '') ?: null,
                'medical_discharge_date' => $this->date($c[12] ?? null),
                // optional trailing columns 14–17 (final sweep G1) — absent in shorter rows
                'admitted_from' => trim($c[13] ?? '') ?: null,
                'bed' => trim($c[14] ?? '') ?: null,
                'delay_reason' => trim($c[15] ?? '') ?: null,
                'is_longterm' => in_array(strtolower(trim($c[16] ?? '')), ['1', 'yes'], true),
                'ok' => true,
                'error' => null,
                'warning' => null,
            ];
            if ($row['consultant_name'] !== null) {
                $consultants ??= $this->consultantLookup();
                $row['consultant_id'] = $consultants[mb_strtolower($row['consultant_name'])] ?? null;
                if ($row['consultant_id'] === null) {
                    $row['warning'] = "Consultant \"{$row['consultant_name']}\" not matched — imported unassigned";
                }
            }
            if ($mrn === '' || mb_strlen($mrn) > 11) {
                $row['ok'] = false; $row['error'] = 'MRN must be 1–11 digits';
            } elseif (! $row['admit_date']) {
                $row['ok'] = false; $row['error'] = 'Missing/invalid admit date';
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * lowercase(full_name | name | username) => id for consultant-role users; one query per
     * preview/import run. First match wins on a (pathological) name collision.
     */
    private function consultantLookup(): array
    {
        $map = [];
        \App\Models\User::where('role', \App\Models\User::ROLE_CONSULTANT)
            ->get(['id', 'full_name', 'name', 'username'])
            ->each(function ($u) use (&$map) {
                foreach ([$u->full_name, $u->name, $u->username] as $key) {
                    $key = mb_strtolower(trim((string) $key));
                    if ($key !== '' && ! isset($map[$key])) { $map[$key] = $u->id; }
                }
            });

        return $map;
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
