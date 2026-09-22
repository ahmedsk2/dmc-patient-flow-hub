<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CMP-02 scaffold — records:retention-report. READ-ONLY: no delete/anonymise mode exists at all.
 * Prints counts only (admissions closed more than --years ago; patients whose EVERY episode is
 * that old), refuses to run without --years, and never prints a name, MRN or any other identifier.
 */
class RecordsRetentionReportTest extends TestCase
{
    use RefreshDatabase;

    private function patient(array $overrides = []): Patient
    {
        return Patient::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999), 'name' => 'Retention Patient',
        ], $overrides));
    }

    private function admission(Patient $patient, array $overrides = []): Admission
    {
        return Admission::create(array_merge([
            'patient_id' => $patient->id, 'admit_date' => now()->subYears(20)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---------------------------------------------------------------- refuses without --years

    public function test_refuses_without_years(): void
    {
        $this->artisan('records:retention-report')
            ->expectsOutputToContain('--years=N is required')
            ->assertExitCode(1);
    }

    public function test_refuses_a_non_positive_years_value(): void
    {
        $this->artisan('records:retention-report', ['--years' => '0'])->assertExitCode(1);
        $this->artisan('records:retention-report', ['--years' => 'abc'])->assertExitCode(1);
        $this->artisan('records:retention-report', ['--years' => '-3'])->assertExitCode(1);
    }

    // ---------------------------------------------------------------- counts

    public function test_counts_only_admissions_closed_more_than_n_years_ago(): void
    {
        $p = $this->patient();
        $this->admission($p, ['discharge_date' => now()->subYears(11)->toDateString()]);           // old close -> counted
        $this->admission($p, ['discharge_date' => now()->subYears(2)->toDateString()]);             // recent close -> not counted
        $this->admission($p, ['discharge_date' => null]);                                           // still open -> not counted

        $this->artisan('records:retention-report', ['--years' => '10'])
            ->expectsOutputToContain('admissions: 1')
            ->assertExitCode(0);
    }

    public function test_a_patient_counts_only_when_every_episode_is_old(): void
    {
        $allOld = $this->patient(['mrn' => '11110000', 'name' => 'All Old']);
        $this->admission($allOld, ['discharge_date' => now()->subYears(15)->toDateString()]);
        $this->admission($allOld, ['discharge_date' => now()->subYears(12)->toDateString()]);

        $oneRecent = $this->patient(['mrn' => '22220000', 'name' => 'One Recent']);
        $this->admission($oneRecent, ['discharge_date' => now()->subYears(15)->toDateString()]);
        $this->admission($oneRecent, ['discharge_date' => now()->subYears(1)->toDateString()]); // recent -> disqualifies

        $stillOpen = $this->patient(['mrn' => '33330000', 'name' => 'Still Open']);
        $this->admission($stillOpen, ['discharge_date' => now()->subYears(15)->toDateString()]);
        $this->admission($stillOpen, ['discharge_date' => null]); // open -> disqualifies

        $this->artisan('records:retention-report', ['--years' => '10'])
            ->expectsOutputToContain('patients whose every episode is that old: 1')
            ->assertExitCode(0);
    }

    public function test_output_contains_no_identifiers(): void
    {
        $p = $this->patient(['mrn' => '99998888', 'name' => 'Should Not Appear']);
        $this->admission($p, ['discharge_date' => now()->subYears(11)->toDateString()]);

        $this->artisan('records:retention-report', ['--years' => '10'])
            ->doesntExpectOutputToContain('99998888')
            ->doesntExpectOutputToContain('Should Not Appear')
            ->assertExitCode(0);
    }
}
