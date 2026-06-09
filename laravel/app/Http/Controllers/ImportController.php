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
 * Bulk import of HISTORICAL admission episodes from pasted CSV (admin). Replaces the legacy
 * picupatients_temp staging flow with a parse → validate → transactional-insert in one step.
 * Columns: MRN, Name, Age, Gender, Nationality, AdmitDate, DischargeDate, Outcome, Location.
 */
class ImportController extends Controller
{
    private array $columns = ['MRN', 'Name', 'Age', 'Gender', 'Nationality', 'AdmitDate', 'DischargeDate', 'Outcome', 'Location'];

    public function index(): Response
    {
        return Inertia::render('Import/Index', ['columns' => $this->columns]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['rows' => ['required', 'string', 'max:500000']]);

        $lines = preg_split('/\r\n|\r|\n/', trim($data['rows']));
        $imported = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($lines, &$imported, &$skipped, &$errors) {
            foreach ($lines as $n => $line) {
                if (trim($line) === '') { continue; }
                $c = str_getcsv($line);
                // tolerate a header row
                if ($n === 0 && strtolower(trim($c[0] ?? '')) === 'mrn') { continue; }

                $mrn = preg_replace('/\D/', '', trim($c[0] ?? ''));
                if ($mrn === '' || mb_strlen($mrn) > 11) {
                    $skipped++; $errors[] = 'Line ' . ($n + 1) . ': missing/invalid MRN'; continue;
                }
                $admit = $this->date($c[5] ?? null);
                $patient = Patient::firstOrCreate(['mrn' => $mrn], [
                    'name' => trim($c[1] ?? '') ?: null,
                    'gender' => $this->gender($c[3] ?? null),
                    'age' => is_numeric($c[2] ?? null) ? (int) $c[2] : null,
                ]);
                DB::table('admissions')->insert([
                    'patient_id' => $patient->id,
                    'admit_date' => $admit,
                    'discharge_date' => $this->date($c[6] ?? null),
                    'outcome' => trim($c[7] ?? '') ?: null,
                    'current_location' => trim($c[8] ?? '') ?: 'Ward',
                    'transfer_type' => ($this->date($c[6] ?? null)) ? 'discharge from ward' : null,
                    'admitted_by' => Auth::id(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if (! empty($c[4]) && ! $patient->nationality) {
                    $patient->update(['nationality' => trim($c[4])]);
                }
                $imported++;
            }
        });

        AuditLog::create(['actor_id' => Auth::id(), 'actor_name' => Auth::user()->name, 'action' => 'import.bulk',
            'entity_type' => 'admission', 'entity_id' => null, 'details' => ['imported' => $imported, 'skipped' => $skipped], 'ip' => $request->ip()]);

        $msg = "Imported {$imported} admission(s)" . ($skipped ? ", skipped {$skipped}." : '.');

        return back()->with('flash', ['type' => $imported > 0 ? 'success' : 'error',
            'message' => $msg . ($errors ? ' ' . implode(' · ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? '…' : '') : '')]);
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
