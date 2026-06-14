<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\TbDiagnosis;
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

    /** Attach a TB-listed ICD-10 diagnosis to an admission (registers the code in tb_diagnoses once). */
    private function makeTb(Admission $a): Admission
    {
        TbDiagnosis::firstOrCreate(['icd10_code' => 'A15.0']);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);

        return $a;
    }

    // ---- Phase-0 Item 8: TB asymmetry, ICU-only, round-5 overflow fall-through ------------------

    /**
     * N1-3 legacy asymmetry: a TB patient counts toward a SUBSPECIALIST's ward load but NOT a
     * hospitalist's. With hospitalist A at ward-load 0 and subspecialist B carrying one TB patient,
     * round 1 (hospitalists → min) places the new ward patient on A — the subspecialist's TB-raised
     * load is real (B already holds the TB row) and keeps the queue off B.
     */
    public function test_tb_patient_raises_subspecialist_load_not_hospitalist(): void
    {
        $this->settings(['min_hospitalist' => 2, 'max_hospitalist' => 5, 'min_subs' => 2, 'max_subs' => 5]);
        $a = $this->consultant('Hosp A', 1);   // hospitalist
        $b = $this->consultant('Sub B', 2);    // subspecialist

        // B already carries one active TB ward patient → B's subspecialist load = 1 (TB included)
        $this->makeTb($this->admission(['consultant_id' => $b->id]));

        // one new unassigned ward patient to place
        $this->admission();

        $r = (new ShuffleService)->run();

        $this->assertSame(1, $r['assigned']);
        // the new patient lands on the hospitalist (ward load 0), not the TB-loaded subspecialist
        $this->assertSame(1, Admission::whereNull('discharge_date')->where('consultant_id', $a->id)->count());
        // B keeps only its pre-existing TB patient — it did not receive the new one
        $this->assertSame(1, Admission::whereNull('discharge_date')->where('consultant_id', $b->id)->count());
    }

    /**
     * ICU patients are managed by ICU staff and never count toward a consultant's WARD shuffle load.
     * Two hospitalists, B carrying 2 ICU patients, A carrying none: both have ward load 0, so the
     * new ward patient is placed (on one of them) and B's ICU rows do not block or inflate it.
     */
    public function test_icu_only_consultant_load_is_zero_for_ward_shuffle(): void
    {
        $this->settings(['min_hospitalist' => 1, 'max_hospitalist' => 3, 'min_subs' => 0, 'max_subs' => 0]);
        $a = $this->consultant('Hosp A', 1);
        $b = $this->consultant('Hosp B', 1);

        // B carries 2 ICU patients — these are excluded from the ward load entirely
        $this->admission(['consultant_id' => $b->id, 'current_location' => 'ICU']);
        $this->admission(['consultant_id' => $b->id, 'current_location' => 'ICU']);

        // one new unassigned WARD patient to place
        $this->admission();

        $r = (new ShuffleService)->run();

        $this->assertSame(1, $r['assigned'], 'the new ward patient must be placed (ICU load is irrelevant)');
        $this->assertSame(0, Admission::whereNull('discharge_date')->whereNull('consultant_id')->count(),
            'no unassigned ward patients remain');
        // exactly one of the hospitalists gained the new WARD assignment (both started at ward load 0)
        $wardA = Admission::whereNull('discharge_date')->where('consultant_id', $a->id)
            ->where(fn ($q) => $q->where('current_location', '<>', 'ICU')->orWhereNull('current_location'))->count();
        $wardB = Admission::whereNull('discharge_date')->where('consultant_id', $b->id)
            ->where(fn ($q) => $q->where('current_location', '<>', 'ICU')->orWhereNull('current_location'))->count();
        $this->assertSame(1, $wardA + $wardB, 'the single new ward patient landed on one hospitalist');
        // B's ICU patients are untouched by the ward shuffle
        $this->assertSame(2, Admission::whereNull('discharge_date')->where('consultant_id', $b->id)
            ->where('current_location', 'ICU')->count());
    }

    /**
     * Round 5 (overflow) fall-through: when every consultant is already at max across rounds 1–4,
     * the final least-loaded-overall round still places the patient so the queue never stalls.
     */
    public function test_round5_overflow_assigns_to_least_loaded_when_all_at_max(): void
    {
        $this->settings(['min_hospitalist' => 1, 'max_hospitalist' => 2, 'min_subs' => 0, 'max_subs' => 0]);
        $a = $this->consultant('Full A', 1);
        $b = $this->consultant('Full B', 1);

        // both hospitalists already carry max_hospitalist (2) active ward patients
        $this->admission(['consultant_id' => $a->id]);
        $this->admission(['consultant_id' => $a->id]);
        $this->admission(['consultant_id' => $b->id]);
        $this->admission(['consultant_id' => $b->id]);

        // one new unassigned patient — rounds 1-4 find no one under cap; round 5 must catch it
        $this->admission();

        $r = (new ShuffleService)->run();

        $this->assertSame(1, $r['assigned'], 'overflow round assigns even when all are at max');
        $this->assertSame(0, $r['skipped']);
        $this->assertSame(0, Admission::whereNull('discharge_date')->whereNull('consultant_id')->count());
    }
}
