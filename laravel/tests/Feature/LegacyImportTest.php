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

    public function test_import_deduplicates_member_emails_to_satisfy_the_unique_index(): void
    {
        // two legacy members share an email (case-insensitively) — real prod data has 4 such pairs.
        // The restored users.email unique index would otherwise make the re-import fail on insert.
        $L = DB::connection('legacy');
        $L->table('members')->insert([
            ['member_id' => 10, 'member_name' => 'alpha', 'full_name' => 'Alpha',
             'member_email' => 'Shared@Example.test', 'member_password' => '$2y$04$abcdefghijklmnopqrstuv',
             'position' => 3, 'active' => 1],
            // case-only duplicate
            ['member_id' => 11, 'member_name' => 'beta', 'full_name' => 'Beta',
             'member_email' => 'shared@example.test', 'member_password' => '$2y$04$abcdefghijklmnopqrstuv',
             'position' => 3, 'active' => 1],
            // accent-only duplicate — utf8mb4_unicode_ci treats 'á' == 'a', so the DB index would
            // reject this too; the importer's ASCII-fold dedup must catch it (a plain lower/trim wouldn't)
            ['member_id' => 12, 'member_name' => 'gamma', 'full_name' => 'Gamma',
             'member_email' => 'sháred@example.test', 'member_password' => '$2y$04$abcdefghijklmnopqrstuv',
             'position' => 3, 'active' => 1],
        ]);

        $this->artisan('legacy:import')->assertSuccessful();

        // exactly one survivor keeps the shared address; the lowest member_id wins, the others nulled
        $this->assertSame(1, DB::table('users')->whereRaw('LOWER(email) = ?', ['shared@example.test'])->count());
        $this->assertSame('Shared@Example.test', DB::table('users')->where('legacy_id', 10)->value('email'));
        $this->assertNull(DB::table('users')->where('legacy_id', 11)->value('email'));
        $this->assertNull(DB::table('users')->where('legacy_id', 12)->value('email'), 'accent-variant duplicate nulled');
        // the duplicate-email members are still imported — they just log in by username only
        $this->assertNotNull(DB::table('users')->where('legacy_id', 11)->value('id'));
        $this->assertNotNull(DB::table('users')->where('legacy_id', 12)->value('id'));
    }

    public function test_import_sanitizes_out_of_range_age_and_inverted_dates(): void
    {
        // The importer must satisfy the schema's CHECK constraints (migration 2026_06_14_010006):
        // patients.age in [0,150] and admission dates non-inverted. Real dumps carry MRNs typed into
        // the age field (huge numbers) and discharge-before-admit rows — the import truncates+reinserts
        // so it can't rely on the migration's one-time self-heal; it must sanitize on insert.
        $L = DB::connection('legacy');
        $episode = fn (array $row) => array_merge([
            'MORTALITY' => null, 'trans_discharge' => null, 'DISDATE' => null, 'med_DISDATE' => null,
            'current_location' => 'Ward', 'newassign' => null, 'assigned_on' => null, 'consultant_id' => null,
        ], $row);
        $L->table('picupatients')->insert([
            // garbage age (MRN in the age field) + discharge BEFORE admit
            $episode(['ID' => 20, 'MRN' => '20001', 'PNAME' => 'Bad Age', 'age' => '403306273',
                'ADMDATE' => '2024-05-10', 'DISDATE' => '2024-05-01', 'MORTALITY' => 'Alive',
                'trans_discharge' => 'discharge from ward']),
            // medical-discharge BEFORE admit
            $episode(['ID' => 21, 'MRN' => '20002', 'PNAME' => 'Bad Med', 'age' => '55',
                'ADMDATE' => '2024-06-10', 'med_DISDATE' => '2024-06-01']),
            // discharge AFTER admit but BEFORE medical-discharge -> clamp up to medical-discharge
            $episode(['ID' => 22, 'MRN' => '20003', 'PNAME' => 'Dis Before Med', 'age' => '40',
                'ADMDATE' => '2024-07-01', 'med_DISDATE' => '2024-07-06', 'DISDATE' => '2024-07-03',
                'MORTALITY' => 'Alive', 'trans_discharge' => 'discharge from ward']),
        ]);

        $this->artisan('legacy:import')->assertSuccessful();

        // out-of-range age → NULL (row preserved)
        $this->assertNull(DB::table('patients')->where('mrn', '20001')->value('age'));
        // inverted discharge date is CLAMPED to admit (NOT nulled) — the patient stays DISCHARGED so it
        // can't inflate the active census; LOS becomes 0. This is the census-fidelity fix.
        $adm20 = DB::table('admissions')->where('legacy_id', 20)->first();
        $this->assertNotNull($adm20);
        $this->assertSame('2024-05-10', $adm20->discharge_date, 'inverted discharge clamped to admit, still discharged');
        $this->assertSame('2024-05-10', $adm20->admit_date);
        // impossible medical-discharge date → NULL; a valid age is untouched
        $this->assertNull(DB::table('admissions')->where('legacy_id', 21)->value('medical_discharge_date'));
        $this->assertSame(55, (int) DB::table('patients')->where('mrn', '20002')->value('age'));
        // discharge < medical-discharge → clamped up to the medical-discharge date (still discharged)
        $this->assertSame('2024-07-06', DB::table('admissions')->where('legacy_id', 22)->value('discharge_date'));
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

    // ============================================================================================
    // W0 — cutover safety gate: settings.consultations_source_of_truth. Once the Laravel app owns
    // consultations, legacy:import must neither truncate nor re-import them — and must re-point the
    // surviving rows at the REBUILT patient/user ids (patients restart at id 1; legacy-sourced users
    // are deleted and re-inserted), or a preserved consult would silently attach to another patient.
    // ============================================================================================

    /** One legacy consultation row, so we can prove it is (or is not) re-imported. */
    private function seedLegacyConsultation(): void
    {
        DB::connection('legacy')->table('consultations')->insert([
            'id' => 501, 'MRN' => '10001', 'PNAME' => 'Legacy Cx', 'age' => '44', 'BED' => 'W-1',
            'consultation_date' => '2024-01-02', 'consultation_from' => 'Ward', 'current_location' => 'Ward',
            'indication' => '[]', 'consultant_id' => 7, 'entered_by_id' => 7, 'signoff_date' => null,
            'other_ind' => null, 'consultation_to_service' => '1',
        ]);
    }

    /**
     * A consultation as the Laravel app would create it: linked to a patient by MRN, to a
     * PREVIOUSLY-IMPORTED consultant (legacy_id 7 — the import will delete and re-insert that user
     * with a new id), and entered by an APP-CREATED user (no legacy_id — its id must survive).
     * Returns [consultation id, entered-by user id].
     */
    private function seedNewSystemConsultation(): array
    {
        $mk = fn (array $extra) => \App\Models\User::create(array_merge([
            'username' => 'w0_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W0 User', 'password' => 'secret12345',
            'role' => \App\Models\User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => \App\Support\Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));

        $previouslyImported = $mk(['legacy_id' => 7]);      // will be deleted + re-inserted by the import
        $appCreated = $mk([]);                               // no legacy_id -> the import never touches it

        \App\Models\Patient::create(['mrn' => '99999', 'name' => 'Decoy Pt']);   // shifts the id the real patient gets
        $patient = \App\Models\Patient::create(['mrn' => '10001', 'name' => 'Pt One']);

        $c = \App\Models\Consultation::create([
            'mrn' => '10001', 'patient_id' => $patient->id, 'patient_name' => 'New System Cx',
            'consultation_date' => now()->toDateString(), 'indication' => [],
            'to_service' => 'Hospitalist', 'consultant_id' => $previouslyImported->id,
            'entered_by' => $appCreated->id,
        ]);

        return [$c->id, $appCreated->id];
    }

    public function test_import_preserves_and_relinks_consultations_when_the_flag_is_on(): void
    {
        $this->seedLegacy();
        $this->seedLegacyConsultation();
        [$cxId, $appUserId] = $this->seedNewSystemConsultation();
        \App\Models\Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import')
            ->expectsOutputToContain('consultations preserved')
            ->assertSuccessful();

        // the app's consultation survived, and the legacy table was NOT replayed over it
        $this->assertSame(1, DB::table('consultations')->count(), 'exactly the one app-owned row remains');
        $this->assertSame(0, DB::table('consultations')->whereNotNull('legacy_id')->count(),
            'legacy consultations are not re-imported while the app owns them');
        $row = DB::table('consultations')->where('id', $cxId)->first();
        $this->assertNotNull($row);
        $this->assertSame('New System Cx', $row->patient_name);

        // re-linked by natural key: still the SAME patient (patients were rebuilt with new ids)
        $linkedMrn = DB::table('consultations as c')->join('patients as p', 'p.id', '=', 'c.patient_id')
            ->where('c.id', $cxId)->value('p.mrn');
        $this->assertSame('10001', $linkedMrn, 'preserved consult still points at ITS patient after the rebuild');

        // consultant re-pointed at the re-imported legacy user (member_id 7)
        $this->assertSame(
            (int) DB::table('users')->where('legacy_id', 7)->value('id'),
            (int) $row->consultant_id,
            'consultant re-pointed at the rebuilt user row, not at whoever inherited the old id'
        );
        // entered_by is app-created -> its id never moved, so attribution is untouched
        $this->assertSame($appUserId, (int) $row->entered_by, 'entered_by attribution survives the import');

        // and the flag itself survives the settings rebuild (or the NEXT import would wipe everything)
        $this->assertSame(1, (int) DB::table('settings')->orderBy('id')->value('consultations_source_of_truth'));
    }

    public function test_import_still_rebuilds_consultations_when_the_flag_is_off(): void
    {
        $this->seedLegacy();
        $this->seedLegacyConsultation();
        $this->seedNewSystemConsultation();   // flag left at its default (false)

        $this->artisan('legacy:import')->assertSuccessful();

        $this->assertSame(0, DB::table('consultations')->where('patient_name', 'New System Cx')->count(),
            'unchanged behaviour: with the flag off the table is still rebuilt from legacy');
        $this->assertSame(1, DB::table('consultations')->where('legacy_id', 501)->count());
        $this->assertSame(0, (int) DB::table('settings')->orderBy('id')->value('consultations_source_of_truth'));
    }

    public function test_import_refuses_a_forced_consultation_wipe_while_the_app_owns_them(): void
    {
        $this->seedLegacy();
        [$cxId] = $this->seedNewSystemConsultation();
        \App\Models\Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import', ['--wipe-consultations' => true])
            ->expectsOutputToContain('Refusing to wipe consultations')
            ->assertFailed();

        // fail loudly and change NOTHING — not even the rest of the import
        $this->assertSame(1, DB::table('consultations')->where('id', $cxId)->count());
    }

    public function test_import_still_imports_everything_else_while_consultations_are_preserved(): void
    {
        $this->seedLegacy();
        $this->seedNewSystemConsultation();
        \App\Models\Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import')->assertSuccessful();

        // admissions / patients / reference tables import exactly as they do today
        $this->assertSame('Saudi Arabia', DB::table('patients')->where('mrn', '10001')->value('nationality'));
        $this->assertSame(4, DB::table('admissions')->count());
        $this->assertSame(1, DB::table('specialties')->where('name', 'Hospitalist')->count());
        $this->assertNotNull(DB::table('users')->where('legacy_id', 7)->value('id'));
    }

    // ============================================================================================
    // Phase 4 — Item 8: expanded ImportController validator coverage. These exercise the bulk-import
    // CSV parser/committer (NOT the legacy:import command) — they call ImportController::parse via
    // reflection or POST the preview/store endpoints as an admin.
    // ============================================================================================

    private function parse(string $csv): array
    {
        $m = new \ReflectionMethod(\App\Http\Controllers\ImportController::class, 'parse');
        $m->setAccessible(true);

        return $m->invoke(app(\App\Http\Controllers\ImportController::class), $csv);
    }

    private function importAdmin(): \App\Models\User
    {
        return \App\Models\User::create([
            'username' => 'imp_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Import Admin', 'role' => \App\Models\User::ROLE_ADMIN, 'active' => 1, 'password' => 'secret12345',
            'mfa_secret' => \App\Support\Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    public function test_header_row_is_skipped_when_first_column_is_mrn(): void
    {
        $rows = $this->parse("MRN,Name,Age,Gender,Nationality,AdmitDate\n12345,Real Pt,40,M,Saudi Arabia,2024-01-01");
        $this->assertCount(1, $rows, 'the MRN header row is not counted');
        $this->assertSame('12345', $rows[0]['mrn']);
    }

    public function test_header_row_without_mrn_label_is_not_skipped(): void
    {
        // first row has a numeric MRN — it is a data row, not a header
        $rows = $this->parse("12340,First Pt,40,M,Saudi Arabia,2024-01-01\n12341,Second Pt,41,F,Saudi Arabia,2024-01-02");
        $this->assertCount(2, $rows);
        $this->assertSame('12340', $rows[0]['mrn']);
    }

    public function test_within_batch_duplicate_active_mrn_rejected(): void
    {
        // two active (no discharge) rows for the same MRN — the second is rejected
        $rows = $this->parse("50500,A,40,M,Saudi Arabia,2024-01-01\n50500,A,40,M,Saudi Arabia,2024-02-01");
        $this->assertTrue($rows[0]['ok']);
        $this->assertFalse($rows[1]['ok']);
        $this->assertStringContainsString('already has an active', (string) $rows[1]['error']);
    }

    public function test_within_batch_duplicate_active_mrn_second_discharged_allowed(): void
    {
        // first active, second discharged — both valid (no double-open)
        $rows = $this->parse("50600,A,40,M,Saudi Arabia,2024-01-01\n50600,A,40,M,Saudi Arabia,2024-02-01,2024-02-05,Alive,Ward");
        $this->assertTrue($rows[0]['ok']);
        $this->assertTrue($rows[1]['ok']);
    }

    public function test_transfertype_without_discharge_date_rejected(): void
    {
        // TransferType is column 18; provide it with no DischargeDate (col 7)
        $csv = '50700,A,40,M,Saudi Arabia,2024-01-01,,Alive,Ward,,,,,,,,,other transfer';
        $rows = $this->parse($csv);
        $this->assertFalse($rows[0]['ok']);
        $this->assertStringContainsString('requires a discharge date', (string) $rows[0]['error']);
    }

    public function test_invalid_transfer_type_literal_rejected(): void
    {
        $csv = '50710,A,40,M,Saudi Arabia,2024-01-01,2024-01-05,Alive,Ward,,,,,,,,,random string';
        $rows = $this->parse($csv);
        $this->assertFalse($rows[0]['ok']);
        $this->assertStringContainsString('TransferType must be one of', (string) $rows[0]['error']);
    }

    public function test_transfertype_with_discharge_date_overrides_location_derived(): void
    {
        // Location=Ward + DischargeDate set + TransferType='discharge from ICU' => committed type is the explicit one
        $admin = $this->importAdmin();
        $csv = '50720,Over Pt,40,M,Saudi Arabia,2024-01-01,2024-01-05,Alive,Ward,,,,,,,,,Transfer from ICU';
        $this->actingAs($admin)->post('/import', ['rows' => $csv])->assertRedirect();

        $row = DB::table('admissions as a')->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->where('p.mrn', '50720')->first(['a.transfer_type']);
        $this->assertSame('Transfer from ICU', $row->transfer_type, 'explicit TransferType overrides the location-derived type');
    }

    public function test_consultant_name_not_matched_is_warning_not_rejection(): void
    {
        // column 11 (Consultant) holds an unrecognized name
        $csv = '50730,Cons Pt,40,M,Saudi Arabia,2024-01-01,2024-01-05,Alive,Ward,,Dr Nobody';
        $rows = $this->parse($csv);
        $this->assertTrue($rows[0]['ok']);
        $this->assertStringContainsString('not matched', (string) $rows[0]['warning']);
        $this->assertNull($rows[0]['consultant_id']);
    }

    public function test_diagnosis_deduplicated_on_import(): void
    {
        $admin = $this->importAdmin();
        DB::table('icd10')->insert([['code' => 'A00', 'name' => 'Cholera'], ['code' => 'B00', 'name' => 'Herpes']]);
        $csv = '50740,Dedup Pt,40,M,Saudi Arabia,2024-01-01,2024-01-05,Alive,Ward,A00|A00|B00';
        $this->actingAs($admin)->post('/import', ['rows' => $csv])->assertRedirect();

        $admissionId = DB::table('admissions as a')->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->where('p.mrn', '50740')->value('a.id');
        $this->assertSame(2, DB::table('admission_diagnoses')->where('admission_id', $admissionId)->count(),
            'A00 stored once, B00 once');
    }

    public function test_committed_rows_match_preview_valid_count(): void
    {
        $admin = $this->importAdmin();
        // 2 valid + 1 invalid (date inversion)
        $csv = "50750,P1,40,M,Saudi Arabia,2024-01-01,2024-01-05,Alive,Ward\n"
            . "50751,P2,41,F,Saudi Arabia,2024-02-01,2024-02-03,Alive,Ward\n"
            . "50752,P3,42,M,Saudi Arabia,2024-03-10,2024-03-01,Alive,Ward";

        $this->actingAs($admin)->post('/import/preview', ['rows' => $csv])
            ->assertInertia(fn ($page) => $page->where('preview.valid', 2)->where('preview.invalid', 1));

        $this->actingAs($admin)->post('/import', ['rows' => $csv])->assertRedirect();
        $audit = \App\Models\AuditLog::where('action', 'import.bulk')->latest('id')->first();
        $this->assertSame(2, (int) ($audit->details['imported'] ?? 0));
    }

    public function test_preview_does_not_write_to_db(): void
    {
        $admin = $this->importAdmin();
        $before = DB::table('admissions')->count();
        $csv = '50760,Preview Pt,40,M,Saudi Arabia,2024-01-01,2024-01-05,Alive,Ward';

        $this->actingAs($admin)->post('/import/preview', ['rows' => $csv])->assertOk();

        $this->assertSame($before, DB::table('admissions')->count(), 'preview must not write any rows');
    }
}
