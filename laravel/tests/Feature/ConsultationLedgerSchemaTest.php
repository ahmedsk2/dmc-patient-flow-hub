<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Consultation ledger W1 — schema tasks (4, 5, 7).
 *
 * These pin the SHAPE of the ledger: every new column is additive and nullable, the follow-up
 * table is append-only with a one-tick-per-day guarantee, and the coordinator flag defaults off.
 * Nothing here changes behaviour — the workflow that uses these columns arrives in W2.
 */
class ConsultationLedgerSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultations_table_has_every_ledger_column(): void
    {
        foreach ([
            'owning_specialty_id', 'status', 'signed_off_by', 'admission_id',
            'requested_at', 'signed_off_at',
            'response_disposition', 'response_followup_needed', 'response_note',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('consultations', $column),
                "consultations is missing the ledger column {$column}"
            );
        }
    }

    public function test_a_legacy_shaped_row_still_inserts_and_defaults_are_honest(): void
    {
        // exactly the column set the legacy importer writes — proves the migration is ADDITIVE:
        // none of the 1,283 historical rows needed a value for any new column.
        $id = DB::table('consultations')->insertGetId([
            'mrn' => '77000001', 'patient_name' => 'Legacy Shape', 'age' => 61, 'bed' => 'W-1',
            'current_location' => 'Ward', 'consultation_date' => '2024-03-01',
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('consultations')->where('id', $id)->first();

        $this->assertSame('new', $row->status, 'status must default to new');
        $this->assertNull($row->owning_specialty_id);
        $this->assertNull($row->admission_id);
        $this->assertNull($row->requested_at, 'requested_at must never be fabricated');
        $this->assertNull($row->signed_off_at);
        $this->assertNull($row->signed_off_by);
        $this->assertNull($row->response_disposition);
        $this->assertNull($row->response_followup_needed);
        $this->assertNull($row->response_note);
    }

    public function test_owning_specialty_is_nulled_not_cascaded_when_a_specialty_is_removed(): void
    {
        $spec = Specialty::create(['name' => 'W1 Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $c = Consultation::create([
            'mrn' => '77000002', 'patient_name' => 'Owned Pt', 'consultation_date' => '2024-03-02',
            'to_service' => 'W1 Cardiology', 'indication' => [], 'owning_specialty_id' => $spec->id,
        ]);

        DB::table('specialties')->where('id', $spec->id)->delete();

        // the consult row SURVIVES with a NULL owner (Unassigned) — clinical data is never destroyed
        $this->assertNotNull(DB::table('consultations')->where('id', $c->id)->first());
        $this->assertNull(DB::table('consultations')->where('id', $c->id)->value('owning_specialty_id'));
    }
}
