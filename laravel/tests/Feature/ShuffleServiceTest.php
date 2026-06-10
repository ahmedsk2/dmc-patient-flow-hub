<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use App\Services\ShuffleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ShuffleService (owner-approved 2026-06-09): load = active NON-ICU patients (long-term included,
 * ICU excluded); rounds fill to min_hospitalist/min_subs before max; overflow never stalls the queue.
 */
class ShuffleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function consultant(string $name, int $specialty = 1, bool $onService = true): User
    {
        return User::create([
            'username' => 'sh_' . substr(md5($name . uniqid('', true)), 0, 8),
            'name' => $name, 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT,
            'active' => 1, 'on_service' => $onService ? 1 : 0, 'specialty_id' => $specialty,
        ]);
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'Sh Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDay()->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    private function settings(array $vals): void
    {
        Setting::current()->update($vals);
    }

    public function test_no_on_service_consultant_skips_all(): void
    {
        $this->consultant('Off Service', 1, false);
        $this->admission();
        $this->admission();
        $r = (new ShuffleService)->run();
        $this->assertSame(0, $r['assigned']);
        $this->assertSame(2, $r['skipped']);
    }

    public function test_min_round_balances_across_hospitalists(): void
    {
        $this->settings(['min_hospitalist' => 1, 'max_hospitalist' => 5, 'min_subs' => 0, 'max_subs' => 0]);
        $a = $this->consultant('Hosp A', 1);
        $b = $this->consultant('Hosp B', 1);
        $this->admission();
        $this->admission();

        $r = (new ShuffleService)->run();
        $this->assertSame(2, $r['assigned']);
        // min-fill round gives each hospitalist exactly one before either reaches max
        $this->assertSame(1, Admission::where('consultant_id', $a->id)->count());
        $this->assertSame(1, Admission::where('consultant_id', $b->id)->count());
    }

    public function test_icu_patients_do_not_count_toward_load(): void
    {
        $this->settings(['min_hospitalist' => 1, 'max_hospitalist' => 5, 'min_subs' => 0, 'max_subs' => 0]);
        $a = $this->consultant('Has ICU', 1);   // lower id → wins ties
        $b = $this->consultant('No ICU', 1);
        // A already carries an ICU patient — must NOT make A look loaded for the ward shuffle
        $this->admission(['consultant_id' => $a->id, 'current_location' => 'ICU']);
        $this->admission(); // one to place

        (new ShuffleService)->run();
        // A's ward load was 0 (ICU excluded), so the tie-break places the new patient on A
        $this->assertSame(1, Admission::where('consultant_id', $a->id)->where('current_location', 'Ward')->count());
        $this->assertSame(0, Admission::where('consultant_id', $b->id)->count());
    }

    public function test_overflow_assigns_even_when_all_at_cap(): void
    {
        $this->settings(['min_hospitalist' => 0, 'max_hospitalist' => 1, 'min_subs' => 0, 'max_subs' => 0]);
        $a = $this->consultant('Cap A', 1);
        $this->admission(['consultant_id' => $a->id]); // A already at max (1)
        $this->admission(); // queue: 1 — everyone at cap, overflow must still place it

        $r = (new ShuffleService)->run();
        $this->assertSame(1, $r['assigned'], 'overflow round must place the patient even when all are at cap');
        $this->assertSame(2, Admission::where('consultant_id', $a->id)->count());
    }
}
