<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionDiagnosis;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\TestCase;

/**
 * Phase 3 — §3.8: the registry export "can't drift" contract — CSV and XLSX (both fed by the ONE
 * writeExport) must emit byte-identical headers and row values for the same query.
 */
class ExportContractTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'ec_admin_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'EC Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1]);
    }

    private function seedAdmission(): void
    {
        Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        $c = User::create(['username' => 'ec_cons_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Dr X', 'full_name' => 'Dr X Consultant', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1]);
        $p = Patient::create(['mrn' => '15000001', 'name' => 'Export Pt', 'age' => 60, 'gender' => 'Male', 'nationality' => 'Saudi Arabia']);
        $a = Admission::create(['patient_id' => $p->id, 'consultant_id' => $c->id,
            'admit_date' => '2024-04-01', 'discharge_date' => '2024-04-06', 'current_location' => 'Ward',
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);
        AdmissionDiagnosis::create(['admission_id' => $a->id, 'seq' => 1, 'icd10_code' => 'J18.9']);
    }

    /** Read the first two rows (header + first data row) of an XLSX BinaryFileResponse. */
    private function readXlsxRows(string $path): array
    {
        $reader = new XlsxReader();
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $i => $row) {
                $rows[] = array_map(fn ($v) => (string) $v, $row->toArray());
                if ($i >= 2) { break; }
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    public function test_csv_and_xlsx_emit_identical_headers_for_admissions_mode(): void
    {
        $this->seedAdmission();
        $admin = $this->admin();

        $csv = $this->actingAs($admin)->get('/registry/export?mode=admissions')->streamedContent();
        $csvHeader = str_getcsv(strtok($csv, "\n"));

        $xlsx = $this->actingAs($admin)->get('/registry/export-xlsx?mode=admissions');
        $rows = $this->readXlsxRows($xlsx->getFile()->getPathname());
        $xlsxHeader = $rows[0];

        $this->assertSame($csvHeader, $xlsxHeader);
    }

    public function test_csv_and_xlsx_emit_identical_row_values_for_admissions_mode(): void
    {
        $this->seedAdmission();
        $admin = $this->admin();

        $csv = $this->actingAs($admin)->get('/registry/export?mode=admissions')->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $csvRow = str_getcsv($lines[1]); // first data row

        $xlsx = $this->actingAs($admin)->get('/registry/export-xlsx?mode=admissions');
        $rows = $this->readXlsxRows($xlsx->getFile()->getPathname());
        $xlsxRow = $rows[1];

        // MRN (col 0), LOS (col 15), Consultant (col 16) — load-bearing columns must match exactly
        $this->assertSame($csvRow[0], $xlsxRow[0], 'MRN');
        $this->assertSame((string) $csvRow[15], (string) $xlsxRow[15], 'LOS');
        $this->assertSame($csvRow[16], $xlsxRow[16], 'Consultant');
        // and the whole row must be identical (the writeExport single-source guarantee)
        $this->assertSame(array_map('strval', $csvRow), array_map('strval', $xlsxRow));
    }
}
