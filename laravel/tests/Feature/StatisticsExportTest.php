<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\TestCase;

/** Phase 3 — §3.4 + §3.8: Statistics XLSX/PDF exports + the "export == index" KPI-grid contract. */
#[\PHPUnit\Framework\Attributes\Group('pdf')]
class StatisticsExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'se_admin_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'SE Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1]);
    }

    private function nonAdmin(): User
    {
        return User::create(['username' => 'se_cons_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'SE Cons', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1]);
    }

    private function seedJune(): void
    {
        $p1 = Patient::create(['mrn' => '11000001', 'name' => 'P1']);
        $p2 = Patient::create(['mrn' => '11000002', 'name' => 'P2']);
        $p3 = Patient::create(['mrn' => '11000003', 'name' => 'P3']);
        $base = ['is_longterm' => 0, 'is_new_assignment' => 0, 'current_location' => 'Ward'];
        Admission::create([...$base, 'patient_id' => $p1->id, 'admit_date' => '2024-06-02', 'discharge_date' => '2024-06-07', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        Admission::create([...$base, 'patient_id' => $p2->id, 'admit_date' => '2024-06-03']);
        Admission::create([...$base, 'patient_id' => $p3->id, 'admit_date' => '2024-06-05']);
    }

    public function test_statistics_xlsx_requires_admin(): void
    {
        $this->actingAs($this->nonAdmin())->get('/statistics/export')->assertForbidden();
    }

    public function test_statistics_pdf_requires_admin(): void
    {
        $this->actingAs($this->nonAdmin())->get('/statistics/export/pdf')->assertForbidden();
    }

    public function test_statistics_xlsx_returns_xlsx_content_type(): void
    {
        $this->actingAs($this->admin())->get('/statistics/export?from=2024-06-01&to=2024-06-30')
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_statistics_pdf_returns_pdf(): void
    {
        $this->actingAs($this->admin())->get('/statistics/export/pdf?from=2024-06-01&to=2024-06-30')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_statistics_export_invalid_interval_rejected(): void
    {
        $this->actingAs($this->admin())->get('/statistics/export?interval=week')->assertInvalid(['interval']);
    }

    public function test_statistics_xlsx_kpi_grid_matches_index_response(): void
    {
        $this->seedJune();
        $admin = $this->admin();

        // capture kpiGrid[0].admissions from the screen Inertia prop
        $expected = null;
        $this->actingAs($admin)->get('/statistics?from=2024-06-01&to=2024-06-30&interval=month')
            ->assertInertia(function (AssertableInertia $p) use (&$expected) {
                $expected = $p->toArray()['props']['kpiGrid'][0]['admissions'];
            });
        $this->assertSame(3, $expected); // 3 non-ICU admissions in June

        // parse the XLSX first data row of sheet 1 (KPI Grid) and compare the Adm column.
        // response()->download() returns a BinaryFileResponse — the temp file is still on disk
        // during the test (deleteFileAfterSend only fires on a real ::send()).
        $res = $this->actingAs($admin)->get('/statistics/export?from=2024-06-01&to=2024-06-30&interval=month');
        $res->assertOk();
        $path = $res->getFile()->getPathname();

        $reader = new XlsxReader();
        $reader->open($path);
        $header = null;
        $firstData = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $i => $row) {
                $cells = $row->toArray();
                if ($i === 1) { $header = $cells; }
                if ($i === 2) { $firstData = $cells; break; }
            }
            break; // sheet 1 only
        }
        $reader->close();

        $this->assertSame('Period', $header[0]);
        $this->assertSame('Adm', $header[1]);
        // the Adm column of the first bucket equals the screen's kpiGrid[0].admissions (can't drift)
        $this->assertSame($expected, (int) $firstData[1]);
    }
}
