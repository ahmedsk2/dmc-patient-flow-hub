<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
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

    public function test_admission_deletion_nulls_the_fk_without_destroying_the_consultation(): void
    {
        $patient = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'FK Admission Pt']);
        $admission = Admission::create([
            'patient_id' => $patient->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);

        $c = Consultation::create([
            'mrn' => '77000003', 'patient_name' => 'Admission-linked Pt', 'consultation_date' => '2024-03-03',
            'to_service' => 'Cardiology', 'indication' => [], 'admission_id' => $admission->id,
        ]);

        DB::table('admissions')->where('id', $admission->id)->delete();

        // the consult row SURVIVES with a NULL admission link — deleting the stay must not
        // hard-delete the consultation (admissions are soft-deleted in app code; this proves the
        // DB-level FK action itself is SET NULL, not CASCADE).
        $this->assertNotNull(DB::table('consultations')->where('id', $c->id)->first());
        $this->assertNull(DB::table('consultations')->where('id', $c->id)->value('admission_id'));
    }

    public function test_signed_off_by_user_deletion_nulls_the_fk_without_destroying_the_consultation(): void
    {
        $signer = User::create([
            'username' => 'ledger_fk_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Ledger FK Signer', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT,
            'active' => 1, 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);

        $c = Consultation::create([
            'mrn' => '77000004', 'patient_name' => 'Signed-off-by-linked Pt', 'consultation_date' => '2024-03-04',
            'to_service' => 'Cardiology', 'indication' => [], 'signed_off_by' => $signer->id,
        ]);

        DB::table('users')->where('id', $signer->id)->delete();

        // the consult row SURVIVES with a NULL signed_off_by — deleting the signing user must not
        // hard-delete the consultation (users are soft-deleted in app code; this proves the
        // DB-level FK action itself is SET NULL, not CASCADE).
        $this->assertNotNull(DB::table('consultations')->where('id', $c->id)->first());
        $this->assertNull(DB::table('consultations')->where('id', $c->id)->value('signed_off_by'));
    }

    public function test_requested_at_and_signed_off_at_retain_the_time_component(): void
    {
        // requested_at/signed_off_at exist precisely because consultation_date/signoff_date are
        // DATE columns that cannot carry a clock time (the W4 time-to-response medians depend on
        // it). A round-trip through a plain string DATE (or VARCHAR) column would truncate the
        // time to midnight; this pins that the columns are real DATETIMEs.
        $id = DB::table('consultations')->insertGetId([
            'mrn' => '77000005', 'patient_name' => 'Timed Pt', 'age' => 40, 'bed' => 'W-2',
            'current_location' => 'Ward', 'consultation_date' => '2024-03-01',
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => '[]',
            'requested_at' => '2024-03-01 14:37:00',
            'signed_off_at' => '2024-03-02 09:05:30',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('consultations')->where('id', $id)->first();

        $this->assertSame(
            '2024-03-01 14:37:00',
            (string) $row->requested_at,
            'requested_at must be a real datetime — the time component must survive the round-trip'
        );
        $this->assertSame(
            '2024-03-02 09:05:30',
            (string) $row->signed_off_at,
            'signed_off_at must be a real datetime — the time component must survive the round-trip'
        );
    }

    // ---- Task 5: consultation_followups (append-only, one tick per consult per day) -------------

    public function test_followups_table_is_append_only(): void
    {
        $this->assertTrue(Schema::hasTable('consultation_followups'));

        foreach (['id', 'consultation_id', 'followup_date', 'note', 'author_id', 'created_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('consultation_followups', $column),
                "consultation_followups is missing {$column}"
            );
        }

        // append-only: a follow-up tick is a fact that happened, it is never edited
        $this->assertFalse(
            Schema::hasColumn('consultation_followups', 'updated_at'),
            'consultation_followups is append-only and must have no updated_at'
        );
    }

    public function test_a_consult_cannot_be_ticked_twice_on_the_same_day(): void
    {
        $c = Consultation::create([
            'mrn' => '77000003', 'patient_name' => 'Tick Pt', 'consultation_date' => '2024-03-03',
            'to_service' => 'Cardiology', 'indication' => [],
        ]);

        DB::table('consultation_followups')->insert([
            'consultation_id' => $c->id, 'followup_date' => '2026-08-21', 'note' => 'seen',
        ]);

        // the unique index IS the correctness guarantee behind "seen 8 of 12 today"
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('consultation_followups')->insert([
            'consultation_id' => $c->id, 'followup_date' => '2026-08-21', 'note' => 'seen again',
        ]);
    }

    public function test_followups_are_removed_with_their_consultation_row(): void
    {
        $c = Consultation::create([
            'mrn' => '77000004', 'patient_name' => 'Cascade Pt', 'consultation_date' => '2024-03-04',
            'to_service' => 'Cardiology', 'indication' => [],
        ]);
        DB::table('consultation_followups')->insert([
            'consultation_id' => $c->id, 'followup_date' => '2026-08-20',
        ]);

        // NOTE: Consultation soft-deletes, so $c->delete() only stamps deleted_at and the follow-ups
        // correctly stay. This asserts the FK itself, on a HARD delete of the underlying row.
        DB::table('consultations')->where('id', $c->id)->delete();

        $this->assertSame(0, DB::table('consultation_followups')->where('consultation_id', $c->id)->count());
    }
}
