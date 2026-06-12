<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Harness for `php artisan legacy:import` (round-9 L1 items 1+2): the `legacy` connection is
 * repointed at a scratch MySQL schema (dmc_test_legacy) built to the legacy table shapes, so the
 * transform runs end-to-end exactly as it does against dmc_prod.
 *
 *  1. legacy picupatients.nationality is selected + persisted into patients.nationality — on the
 *     canonical (latest-episode-per-MRN) path AND on the NOMRN- placeholder fallback
 *  2. assigned_at backfill: newassign=1 AND assigned_on set ⇒ assigned_at = assigned_on at
 *     MIDNIGHT (datetime) so cutover-day "New" badges survive the migration; everything else
 *     stays NULL
 *
 * NOTE: legacy:import TRUNCATEs the new tables (TRUNCATE = implicit COMMIT), which breaks out of
 * RefreshDatabase's wrapping transaction — tearDown() resets the affected tables by hand so the
 * next test starts from the empty post-migration state RefreshDatabase expects.
 */
class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_DB = 'dmc_test_legacy';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE DATABASE IF NOT EXISTS `' . self::LEGACY_DB . '` CHARACTER SET utf8mb4');
        config(['database.connections.legacy.database' => self::LEGACY_DB]);
        DB::purge('legacy');
        $this->createLegacySchema();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['handover_signatures', 'handover_revisions', 'handovers', 'notifications',
                  'admission_diagnoses', 'admissions', 'consultations', 'patients', 'icd10',
                  'specialties', 'consultation_reasons', 'tb_diagnoses', 'countries', 'settings'] as $t) {
            DB::table($t)->truncate();
        }
        DB::table('users')->whereNotNull('legacy_id')->delete();
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    /** Minimal legacy-shaped tables (the columns LegacyImport actually reads). */
    private function createLegacySchema(): void
    {
        $schema = Schema::connection('legacy');
        foreach (['picupatients', 'members', 'consultations', 'speciality', 'other_specialities',
                  'icd10', 'consultation_reason', 'tb_list', 'countries', 'settings'] as $t) {
            $schema->dropIfExists($t);
        }
        $schema->create('speciality', function ($t) { $t->integer('id'); $t->text('specilaity')->nullable(); });
        $schema->create('other_specialities', function ($t) { $t->integer('id'); $t->text('specilaity')->nullable(); });
        $schema->create('icd10', function ($t) { $t->increments('autoid'); $t->string('id', 32); $t->text('name')->nullable(); });
        $schema->create('consultation_reason', function ($t) { $t->integer('id'); $t->text('consultation_reason')->nullable(); });
        $schema->create('tb_list', function ($t) { $t->increments('id'); $t->text('dx_id')->nullable(); });
        $schema->create('countries', function ($t) { $t->integer('id')->nullable(); $t->string('code', 8)->nullable(); $t->string('name'); });
        $schema->create('settings', function ($t) {
            $t->integer('id');
            foreach (['min_hospitalist', 'max_hospitalist', 'min_subs', 'max_subs', 'short_los', 'long_los'] as $c) {
                $t->integer($c)->nullable();
            }
        });
        $schema->create('members', function ($t) {
            $t->integer('member_id');
            $t->string('member_name')->nullable();
            $t->string('full_name')->nullable();
            $t->string('member_email')->nullable();
            $t->string('member_password')->nullable();
            $t->integer('position')->nullable();
            $t->integer('specialty_id')->nullable();
            $t->integer('active')->nullable();
            $t->integer('on_service')->nullable();
            $t->integer('assign_access')->nullable();
            $t->integer('add_new_patient')->nullable();
            $t->integer('manage_patient')->nullable();
            $t->integer('modify_patient')->nullable();
            $t->date('pass_exp_date')->nullable();
        });
        $schema->create('picupatients', function ($t) {
            $t->integer('ID');
            $t->text('MRN')->nullable();
            $t->text('PNAME')->nullable();
            $t->date('ADMDATE')->nullable();
            $t->date('DISDATE')->nullable();
            $t->date('med_DISDATE')->nullable();
            $t->text('ADMFROM')->nullable();
            $t->text('DISTO')->nullable();
            $t->text('MORTALITY')->nullable();
            $t->text('admissiondiagnosis')->nullable();
            $t->text('BED')->nullable();
            $t->string('nationality')->nullable();
            $t->string('gender', 32)->nullable();
            $t->string('age', 32)->nullable();
            $t->integer('consultant_id')->nullable();
            $t->integer('admitted_by')->nullable();
            $t->text('trans_discharge')->nullable();
            $t->integer('trans_discharge_by')->nullable();
            $t->text('current_location')->nullable();
            $t->string('newassign', 8)->nullable();
            $t->date('assigned_on')->nullable();
            $t->text('delay')->nullable();
            $t->text('longterm')->nullable();
        });
        $schema->create('consultations', function ($t) {
            $t->integer('id');
            $t->string('MRN', 64)->nullable();
            $t->text('PNAME')->nullable();
            $t->string('age', 32)->nullable();
            $t->text('BED')->nullable();
            $t->date('consultation_date')->nullable();
            $t->text('consultation_from')->nullable();
            $t->text('current_location')->nullable();
            $t->text('indication')->nullable();
            $t->integer('consultant_id')->nullable();
            $t->integer('entered_by_id')->nullable();
            $t->date('signoff_date')->nullable();
            $t->text('other_ind')->nullable();
            $t->text('consultation_to_service')->nullable();
        });
    }

    /** Representative legacy rows covering both fixes. */
    private function seedLegacy(): void
    {
        $L = DB::connection('legacy');
        $L->table('speciality')->insert([['id' => 1, 'specilaity' => 'Hospitalist']]);
        $L->table('settings')->insert(['id' => 0, 'min_hospitalist' => 6, 'max_hospitalist' => 30,
            'min_subs' => 7, 'max_subs' => 7, 'short_los' => 5, 'long_los' => 11]);
        $L->table('members')->insert([
            'member_id' => 7, 'member_name' => 'doc7', 'full_name' => 'Dr Seven',
            'member_password' => '$2y$04$abcdefghijklmnopqrstuv', 'position' => 3,
            'specialty_id' => 1, 'active' => 1, 'on_service' => 1,
        ]);

        $episode = fn (array $row) => array_merge([
            'MORTALITY' => null, 'trans_discharge' => null, 'DISDATE' => null,
            'current_location' => 'Ward', 'newassign' => null, 'assigned_on' => null, 'consultant_id' => null,
        ], $row);
        $L->table('picupatients')->insert([
            // older episode of MRN 10001 — its nationality must LOSE to the latest episode's
            $episode(['ID' => 1, 'MRN' => '10001', 'PNAME' => 'Pt One', 'nationality' => 'Kuwait',
                'ADMDATE' => '2024-01-01', 'DISDATE' => '2024-01-05', 'MORTALITY' => 'Alive',
                'trans_discharge' => 'discharge from ward',
                'newassign' => '0', 'assigned_on' => '2024-01-01', 'consultant_id' => 7]),
            // latest episode of MRN 10001 — canonical demographics; ACTIVE + new-flagged today
            $episode(['ID' => 2, 'MRN' => '10001', 'PNAME' => 'Pt One', 'nationality' => 'Saudi Arabia',
                'ADMDATE' => now()->toDateString(),
                'newassign' => '1', 'assigned_on' => now()->toDateString(), 'consultant_id' => 7]),
            // blank MRN — preserved under a NOMRN- placeholder patient (must keep nationality)
            $episode(['ID' => 3, 'MRN' => '', 'PNAME' => 'No Mrn Pt', 'nationality' => 'Egypt',
                'ADMDATE' => '2024-02-01']),
            // new-flagged but assigned_on NULL — nothing to backfill from
            $episode(['ID' => 4, 'MRN' => '10002', 'PNAME' => 'Pt Two', 'nationality' => null,
                'ADMDATE' => '2024-03-01', 'newassign' => '1', 'consultant_id' => 7]),
        ]);
    }

    public function test_import_persists_nationality_on_both_patient_paths(): void
    {
        $this->seedLegacy();

        $this->artisan('legacy:import')->assertSuccessful();

        // canonical path: demographics (incl. nationality) come from the LATEST episode per MRN
        $this->assertSame('Saudi Arabia', DB::table('patients')->where('mrn', '10001')->value('nationality'));
        // NOMRN fallback path keeps the episode's nationality too
        $this->assertSame('Egypt', DB::table('patients')->where('mrn', 'NOMRN-3')->value('nationality'));
        // absent stays NULL (not '' noise)
        $this->assertNull(DB::table('patients')->where('mrn', '10002')->value('nationality'));
    }

    public function test_import_backfills_assigned_at_at_midnight_for_new_flagged_rows(): void
    {
        $this->seedLegacy();

        $this->artisan('legacy:import')->assertSuccessful();

        // newassign=1 + assigned_on => assigned_at = assigned_on 00:00 (cutover-day New badge survives)
        $row = DB::table('admissions')->where('legacy_id', 2)->first();
        $this->assertSame(now()->toDateString() . ' 00:00:00', $row->assigned_at);
        $this->assertSame(now()->toDateString(), $row->assigned_on);
        $this->assertSame(1, (int) $row->is_new_assignment);

        // newassign=0 keeps assigned_on but gets NO assigned_at
        $old = DB::table('admissions')->where('legacy_id', 1)->first();
        $this->assertNull($old->assigned_at);
        $this->assertSame('2024-01-01', $old->assigned_on);

        // newassign=1 with NO assigned_on — nothing to backfill from
        $this->assertNull(DB::table('admissions')->where('legacy_id', 4)->value('assigned_at'));
    }

    public function test_import_is_idempotent(): void
    {
        $this->seedLegacy();

        $this->artisan('legacy:import')->assertSuccessful();
        $first = [
            DB::table('patients')->count(), DB::table('admissions')->count(),
            DB::table('users')->whereNotNull('legacy_id')->count(),
        ];

        $this->artisan('legacy:import')->assertSuccessful();
        $second = [
            DB::table('patients')->count(), DB::table('admissions')->count(),
            DB::table('users')->whereNotNull('legacy_id')->count(),
        ];

        $this->assertSame($first, $second, 're-running the import must not duplicate rows');
        $this->assertSame('Saudi Arabia', DB::table('patients')->where('mrn', '10001')->value('nationality'));
        $this->assertSame(now()->toDateString() . ' 00:00:00',
            DB::table('admissions')->where('legacy_id', 2)->value('assigned_at'));
    }
}
