<?php

namespace Tests\Feature;

use App\Jobs\GenerateMonthlyPdf;
use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Phase 3 — §3.6: reliability — registry match-count, async PDF dispatch, dompdf config. */
class ReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'rl_admin_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'RL Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1]);
    }

    private function nonAdmin(): User
    {
        return User::create(['username' => 'rl_cons_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'RL Cons', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1]);
    }

    public function test_registry_match_count_requires_admin(): void
    {
        $this->actingAs($this->nonAdmin())->get('/registry/count?mode=admissions')->assertForbidden();
    }

    public function test_registry_match_count_returns_json_count(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $p = Patient::create(['mrn' => '13000' . $i, 'name' => "P{$i}"]);
            Admission::create(['patient_id' => $p->id, 'admit_date' => '2024-05-01',
                'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);
        }
        $this->actingAs($this->admin())->get('/registry/count?mode=admissions')
            ->assertOk()->assertExactJson(['count' => 5]);
    }

    public function test_monthly_pdf_async_dispatches_job(): void
    {
        Queue::fake();
        $this->actingAs($this->admin())->get('/reports/monthly/pdf?year=2024&async=1')->assertRedirect();
        Queue::assertPushed(GenerateMonthlyPdf::class, fn ($job) => $job->year === 2024);
    }

    public function test_monthly_pdf_sync_still_returns_pdf(): void
    {
        $this->actingAs($this->admin())->get('/reports/monthly/pdf?year=2024')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_dompdf_config_published(): void
    {
        $this->assertFileExists(config_path('dompdf.php'));
    }

    public function test_async_pdf_job_stores_file_and_notifies(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $admin = $this->admin();
        (new GenerateMonthlyPdf(2024, $admin->id))->handle(app(\App\Http\Controllers\ReportsController::class));

        \Illuminate\Support\Facades\Storage::disk('local')->assertExists("reports/monthly-2024-{$admin->id}.pdf");
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'report.ready']);
    }
}
