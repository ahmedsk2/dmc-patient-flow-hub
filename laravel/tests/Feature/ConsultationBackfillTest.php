<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Consultation ledger W1, Task 6 — the backfill of the historical rows.
 *
 * The rule that matters clinically: an OPEN legacy consult becomes `ongoing`, never `active`.
 * `active` means "this team owes this patient a follow-up TODAY". Backfilling 1,283 open legacy
 * rows to `active` would fabricate that obligation for every one of them and hand every team a
 * huge false worklist on launch day — the exact failure this project already hit with the handover
 * backlog. `ongoing` is the honest state; teams promote to `active` by hand.
 *
 * The migration is re-runnable in isolation (same idiom as FinalSweepG1Test's numeric-to_service
 * test): build rows, require the migration, call up(), assert.
 */
class ConsultationBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_08_21_000300_backfill_consultation_ledger_state.php');
        $migration->up();
    }

    private function consult(array $overrides = []): Consultation
    {
        static $n = 0;
        $n++;

        return Consultation::create(array_merge([
            'mrn' => '7810000' . $n, 'patient_name' => 'Backfill Pt ' . $n,
            'consultation_date' => '2024-04-0' . min($n, 9), 'indication' => [],
        ], $overrides));
    }

    public function test_open_legacy_rows_become_ongoing_and_never_active(): void
    {
        $open = $this->consult(['to_service' => 'Cardiology', 'signoff_date' => null]);

        $this->runBackfill();

        $status = DB::table('consultations')->where('id', $open->id)->value('status');
        $this->assertSame('ongoing', $status);
        $this->assertNotSame('active', $status,
            'open legacy rows must NOT be backfilled to active — that fabricates a daily follow-up obligation');
    }

    public function test_signed_off_legacy_rows_become_signed_off(): void
    {
        $closed = $this->consult(['to_service' => 'Cardiology', 'signoff_date' => '2024-04-10']);

        $this->runBackfill();

        $this->assertSame('signed_off', DB::table('consultations')->where('id', $closed->id)->value('status'));
    }

    public function test_owning_specialty_resolves_case_insensitively_and_never_guesses(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        Specialty::create(['name' => 'Dietary', 'is_subspecialty' => true, 'is_external' => true]);

        $lower    = $this->consult(['to_service' => 'cardiology']);
        $padded   = $this->consult(['to_service' => '  Cardiology  ']);
        $external = $this->consult(['to_service' => 'Dietary']);
        $freeText = $this->consult(['to_service' => 'Some Outside Clinic']);
        $blank    = $this->consult(['to_service' => null]);

        $this->runBackfill();

        $owner = fn ($c) => DB::table('consultations')->where('id', $c->id)->value('owning_specialty_id');

        $this->assertSame($cardio->id, (int) $owner($lower));
        $this->assertSame($cardio->id, (int) $owner($padded));
        $this->assertNull($owner($external), 'external services have no IM team — they stay Unassigned');
        $this->assertNull($owner($freeText), 'an unmatched service must never be guessed into a team');
        $this->assertNull($owner($blank));

        // to_service itself is untouched — it stays the display/continuity value
        $this->assertSame('cardiology', DB::table('consultations')->where('id', $lower->id)->value('to_service'));
    }

    public function test_real_timestamps_are_never_fabricated_from_a_date(): void
    {
        $open   = $this->consult(['to_service' => 'Cardiology']);
        $closed = $this->consult(['to_service' => 'Cardiology', 'signoff_date' => '2024-04-11']);

        $this->runBackfill();

        foreach ([$open, $closed] as $c) {
            $row = DB::table('consultations')->where('id', $c->id)->first();
            $this->assertNull($row->requested_at, 'requested_at must stay NULL for historical rows');
            $this->assertNull($row->signed_off_at, 'signed_off_at must stay NULL for historical rows');
            $this->assertNull($row->signed_off_by);
        }
    }

    public function test_backfill_touches_no_clinical_field(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $c = $this->consult([
            'to_service' => 'Cardiology', 'signoff_date' => '2024-04-12',
            'consultation_from' => 'ER', 'bed' => 'W-12', 'age' => 71,
        ]);

        $this->runBackfill();

        $row = DB::table('consultations')->where('id', $c->id)->first();
        $this->assertSame('2024-04-12', (string) $row->signoff_date);
        $this->assertSame('ER', $row->consultation_from);
        $this->assertSame('W-12', $row->bed);
        $this->assertSame(71, (int) $row->age);
        $this->assertSame($cardio->id, (int) $row->owning_specialty_id);
    }
}
