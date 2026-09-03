<?php

namespace Tests\Feature;

use App\Http\Controllers\ImportController;
use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
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
 *
 * GROUPED 'slow-import' because every test here rebuilds EVERY table end-to-end: ~18 full imports
 * costing roughly 230s, i.e. over half the whole suite's runtime. A plain `artisan test` (and CI)
 * still runs them — the group only lets a tight edit/verify loop opt out with
 * `--exclude-group slow-import`. Never exclude them in a run that gates a release: this class is
 * what proves a data reload cannot destroy or misattribute the consultation ledger.
 */
#[Group('slow-import')]
class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_DB = 'dmc_test_legacy';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE DATABASE IF NOT EXISTS `'.self::LEGACY_DB.'` CHARACTER SET utf8mb4');
        config(['database.connections.legacy.database' => self::LEGACY_DB]);
        DB::purge('legacy');
        $this->createLegacySchema();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['handover_signatures', 'handover_revisions', 'handovers', 'notifications',
            'admission_diagnoses', 'admissions', 'consultation_followups', 'consultations',
            'patients', 'icd10', 'specialties', 'consultation_reasons', 'tb_diagnoses',
            'countries', 'settings'] as $t) {
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
        $schema->create('speciality', function ($t) {
            $t->integer('id');
            $t->text('specilaity')->nullable();
        });
        $schema->create('other_specialities', function ($t) {
            $t->integer('id');
            $t->text('specilaity')->nullable();
        });
        $schema->create('icd10', function ($t) {
            $t->increments('autoid');
            $t->string('id', 32);
            $t->text('name')->nullable();
        });
        $schema->create('consultation_reason', function ($t) {
            $t->integer('id');
            $t->text('consultation_reason')->nullable();
        });
        $schema->create('tb_list', function ($t) {
            $t->increments('id');
            $t->text('dx_id')->nullable();
        });
        $schema->create('countries', function ($t) {
            $t->integer('id')->nullable();
            $t->string('code', 8)->nullable();
            $t->string('name');
        });
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
        $this->assertSame(now()->toDateString().' 00:00:00', $row->assigned_at);
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
        $this->assertSame(now()->toDateString().' 00:00:00',
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
        $mk = fn (array $extra) => User::create(array_merge([
            'username' => 'w0_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W0 User', 'password' => 'secret12345',
            'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));

        $previouslyImported = $mk(['legacy_id' => 7]);      // will be deleted + re-inserted by the import
        $appCreated = $mk([]);                               // no legacy_id -> the import never touches it

        Patient::create(['mrn' => '99999', 'name' => 'Decoy Pt']);   // shifts the id the real patient gets
        $patient = Patient::create(['mrn' => '10001', 'name' => 'Pt One']);

        $c = Consultation::create([
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
        Setting::current()->update(['consultations_source_of_truth' => true]);

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

    /**
     * consultation_followups is a CHILD of consultations. When the flag is ON, consultations keep
     * their existing ids untouched — so a follow-up tick recorded before the reload must still point
     * at the SAME row afterwards, or every daily tick ever recorded would be silently erased on the
     * next legacy data reload even though the consultations themselves survive.
     */
    public function test_followups_survive_when_consultations_are_preserved(): void
    {
        $this->seedLegacy();
        [$cxId] = $this->seedNewSystemConsultation();
        Setting::current()->update(['consultations_source_of_truth' => true]);

        $followupId = DB::table('consultation_followups')->insertGetId([
            'consultation_id' => $cxId, 'followup_date' => now()->toDateString(), 'note' => 'ticked pre-cutover',
        ]);

        $this->artisan('legacy:import')->assertSuccessful();

        $this->assertSame(1, DB::table('consultation_followups')->where('id', $followupId)->count(),
            'a follow-up tied to a preserved consultation must survive the import untouched');
        $this->assertSame($cxId, (int) DB::table('consultation_followups')->where('id', $followupId)->value('consultation_id'));
    }

    /**
     * `owning_specialty_id` is the ledger's ownership key, and `specialties` is TRUNCATED and
     * rebuilt on every import — so it must be re-resolved by NAME, exactly the way patients
     * re-resolve on `mrn` and users on `legacy_id`. Internal legacy specialties are re-inserted at
     * their ids verbatim, which hides the problem; an ADMIN-created specialty is not in the legacy
     * dump at all, so the id it held is handed to whatever external service auto-increment reaches
     * next. Carrying the raw id across would silently move a preserved consult to a different team
     * — the exact harm the backfill migration refuses to risk ("a wrong owner would hide a patient
     * from the team that is actually seeing them").
     */
    public function test_preserved_consultations_re_resolve_their_owning_specialty_by_name(): void
    {
        $this->seedLegacy();                                                   // legacy speciality id 1 = Hospitalist
        DB::connection('legacy')->table('other_specialities')->insert([['id' => 1, 'specilaity' => 'Dietary']]);

        $mkSpecialty = fn (string $name) => Specialty::create(
            ['name' => $name, 'is_subspecialty' => true, 'is_external' => false]
        );
        $mkSpecialty('Extra Clinic');                     // id 1 pre-import — shifts the ids below
        $hospitalist = $mkSpecialty('Hospitalist');       // id 2 pre-import; the rebuild puts it back at id 1
        $ghost = $mkSpecialty('Ghost Clinic');            // admin-created; no legacy counterpart at all

        [$cxId] = $this->seedNewSystemConsultation();
        $orphan = Consultation::create([
            'mrn' => '10001', 'patient_name' => 'Ghost Owned Cx',
            'consultation_date' => now()->toDateString(), 'indication' => [], 'to_service' => 'Ghost Clinic',
        ]);
        DB::table('consultations')->where('id', $cxId)->update(['owning_specialty_id' => $hospitalist->id]);
        DB::table('consultations')->where('id', $orphan->id)->update(['owning_specialty_id' => $ghost->id]);
        Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import')->assertSuccessful();

        $rebuiltHospitalist = (int) DB::table('specialties')->where('name', 'Hospitalist')->value('id');
        $this->assertNotSame($hospitalist->id, $rebuiltHospitalist,
            'precondition: the rebuild must actually move Hospitalist to a different id');
        $this->assertSame($hospitalist->id, (int) DB::table('specialties')->where('name', 'Dietary')->value('id'),
            'precondition: the id the app-side Hospitalist held is now an unrelated EXTERNAL service');

        $this->assertSame($rebuiltHospitalist,
            (int) DB::table('consultations')->where('id', $cxId)->value('owning_specialty_id'),
            'the preserved consult follows its team by NAME, it does not inherit a stale id belonging to another service');

        $this->assertNull(DB::table('consultations')->where('id', $orphan->id)->value('owning_specialty_id'),
            'a specialty that no longer exists after the rebuild becomes NULL (Unassigned), never a different team');
    }

    /**
     * The ledger added three more id-bearing columns that a rebuild invalidates just as thoroughly
     * as patient_id/consultant_id/entered_by:
     *   - owning_specialty_id — external services are re-inserted at FRESH auto-increment ids, so a
     *     stored id can point at a different service afterwards. Re-resolve by NAME.
     *   - admission_id — `admissions` is rebuilt wholesale; only rows carrying a legacy_id can be
     *     found again. An app-created admission does not survive, so NULL is the honest answer.
     *   - signed_off_by — a user id, needing exactly the same users.legacy_id remap as consultant_id.
     * Missing any of these is worse than deletion: the row still looks correct while pointing at the
     * wrong specialty, the wrong stay, or the wrong signing clinician.
     */
    public function test_preserved_consultations_relink_specialty_admission_and_signer(): void
    {
        $this->seedLegacy();
        $L = DB::connection('legacy');
        // The natural keys this test re-resolves against must EXIST in the rebuilt data, so seed the
        // legacy rows the rebuild will recreate them from.
        $L->table('other_specialities')->insert([['id' => 1, 'specilaity' => 'Dietary']]);
        $L->table('members')->insert([
            'member_id' => 4242, 'member_name' => 'doc4242', 'full_name' => 'Dr Signer',
            'member_password' => '$2y$04$abcdefghijklmnopqrstuv', 'position' => 3,
            'specialty_id' => 1, 'active' => 1, 'on_service' => 1,
        ]);
        $L->table('picupatients')->insert([
            'ID' => 990001, 'MRN' => '77001122', 'PNAME' => 'Relink Pt', 'ADMDATE' => '2024-03-01',
            'current_location' => 'Ward',
        ]);

        // An EXTERNAL specialty: re-inserted with a FRESH auto-increment id by importReference(),
        // so its id before and after the import differ — the case a naive id-carry would corrupt.
        $extId = DB::table('specialties')->insertGetId([
            'name' => 'Dietary', 'is_subspecialty' => true, 'is_external' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $signer = User::create([
            'username' => 'w1a_signer', 'name' => 'W1a Signer', 'email' => 'w1a.signer@test.local',
            'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'legacy_id' => 4242,                          // a LEGACY user — its id WILL be re-seeded
        ]);
        $patient = Patient::create(['mrn' => '77001122', 'name' => 'Relink Pt']);
        $legacyAdm = Admission::create([
            'patient_id' => $patient->id, 'admit_date' => '2024-03-01', 'legacy_id' => 990001,
        ]);
        $appAdm = Admission::create([
            'patient_id' => $patient->id, 'admit_date' => '2024-03-02',   // no legacy_id — cannot survive
        ]);
        // A legacy-derived admission whose episode is GONE from the new dump (990002 is deliberately
        // NOT seeded into legacy picupatients above). The legacy app hard-deletes patient rows and
        // partial/filtered dumps happen, so this is the realistic hazard: the legacy_id is present,
        // resolves to nothing, and a "keep the current id" fallback would leave the consult attached
        // to whatever stay now holds that id — A DIFFERENT PATIENT'S ADMISSION.
        $ghostAdm = Admission::create([
            'patient_id' => $patient->id, 'admit_date' => '2024-03-03', 'legacy_id' => 990002,
        ]);

        $withLegacyAdm = Consultation::create([
            'mrn' => '77001122', 'patient_id' => $patient->id, 'patient_name' => 'Relink Pt',
            'indication' => [], 'to_service' => 'Dietary', 'owning_specialty_id' => $extId,
            'admission_id' => $legacyAdm->id, 'signed_off_by' => $signer->id,
            'signoff_date' => '2024-03-05', 'status' => Consultation::STATUS_SIGNED_OFF,
        ]);
        $withAppAdm = Consultation::create([
            'mrn' => '77001122', 'patient_id' => $patient->id, 'patient_name' => 'Relink Pt',
            'indication' => [], 'to_service' => 'Dietary', 'owning_specialty_id' => $extId,
            'admission_id' => $appAdm->id, 'status' => Consultation::STATUS_ONGOING,
        ]);
        $withGhostAdm = Consultation::create([
            'mrn' => '77001122', 'patient_id' => $patient->id, 'patient_name' => 'Relink Pt',
            'indication' => [], 'to_service' => 'Dietary', 'owning_specialty_id' => $extId,
            'admission_id' => $ghostAdm->id, 'status' => Consultation::STATUS_ONGOING,
        ]);
        Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import')->assertExitCode(0);

        $a = DB::table('consultations')->where('id', $withLegacyAdm->id)->first();
        $b = DB::table('consultations')->where('id', $withAppAdm->id)->first();

        // specialty re-resolved BY NAME onto whatever id the rebuild assigned
        $newExtId = DB::table('specialties')->whereRaw('LOWER(name) = ?', ['dietary'])->value('id');
        $this->assertNotNull($newExtId);
        $this->assertNotSame($extId, (int) $newExtId,
            'precondition: the rebuild must actually move the external service to a different id');
        $this->assertSame((int) $newExtId, (int) $a->owning_specialty_id,
            'owning_specialty_id must follow the specialty NAME across a rebuild, not its old id');

        // admission re-resolved via admissions.legacy_id
        $newAdmId = DB::table('admissions')->where('legacy_id', 990001)->value('id');
        $this->assertNotNull($newAdmId);
        $this->assertSame((int) $newAdmId, (int) $a->admission_id);

        // an app-created admission does not survive the rebuild — NULL, never a stale/wrong id
        $this->assertNull($b->admission_id,
            'a consult pointing at an app-created admission must be NULLed, not left pointing at a rebuilt row');

        // a LEGACY-derived admission whose episode is absent from the new dump is equally unresolvable:
        // its legacy_id finds nothing, so the answer is NULL — never the id it happened to hold, which
        // now belongs to some other patient's stay.
        $c = DB::table('consultations')->where('id', $withGhostAdm->id)->first();
        $this->assertNull($c->admission_id,
            'an admission whose legacy episode is gone after the rebuild becomes NULL, never a different stay');

        // signer remapped by users.legacy_id, exactly like consultant_id
        $newSignerId = DB::table('users')->where('legacy_id', 4242)->value('id');
        $this->assertNotNull($newSignerId);
        $this->assertNotSame($signer->id, (int) $newSignerId,
            'precondition: the rebuild must actually re-seed the signing user id');
        $this->assertSame((int) $newSignerId, (int) $a->signed_off_by);
    }

    /**
     * `consultation_followups.author_id` is a user id living on rows the flag DELIBERATELY preserves,
     * so the same rebuild that re-seeds `users` invalidates it. The FK's declared nullOnDelete cannot
     * save it: the legacy-user delete runs with `Schema::disableForeignKeyConstraints()` active, so no
     * cascade fires and the tick keeps an id that either belongs to nobody or — once auto-increment
     * reaches it again — to a DIFFERENT clinician. That silently rewrites "who ticked this follow-up"
     * on an append-only clinical log, and persists a row that violates its own FK (written with checks
     * off, it survives until some later ALTER/dump-restore revalidates and aborts).
     */
    public function test_preserved_followup_authors_are_relinked_after_a_rebuild(): void
    {
        $this->seedLegacy();                                  // legacy member_id 7 gets re-imported
        [$cxId, $appUserId] = $this->seedNewSystemConsultation();
        $legacyAuthorId = (int) DB::table('users')->where('legacy_id', 7)->value('id');
        Setting::current()->update(['consultations_source_of_truth' => true]);

        $byLegacyAuthor = DB::table('consultation_followups')->insertGetId([
            'consultation_id' => $cxId, 'followup_date' => '2024-01-01',
            'note' => 'ticked by a legacy-sourced user', 'author_id' => $legacyAuthorId,
        ]);
        $byAppAuthor = DB::table('consultation_followups')->insertGetId([
            'consultation_id' => $cxId, 'followup_date' => '2024-01-02',
            'note' => 'ticked by an app-created user', 'author_id' => $appUserId,
        ]);

        $this->artisan('legacy:import')->assertSuccessful();

        $newAuthorId = (int) DB::table('users')->where('legacy_id', 7)->value('id');
        $this->assertNotSame($legacyAuthorId, $newAuthorId,
            'precondition: the rebuild must actually re-seed the legacy author id');
        $this->assertSame($newAuthorId,
            (int) DB::table('consultation_followups')->where('id', $byLegacyAuthor)->value('author_id'),
            'a preserved follow-up must follow its author across the rebuild, not keep a re-seeded id');
        $this->assertSame($appUserId,
            (int) DB::table('consultation_followups')->where('id', $byAppAuthor)->value('author_id'),
            'an app-created author is never deleted, so its attribution must be left untouched');

        // and nothing may be left violating the FK the truncate/delete ran with checks disabled
        $dangling = DB::table('consultation_followups as f')
            ->whereNotNull('f.author_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('users')
                ->whereColumn('users.id', 'f.author_id'))
            ->count();
        $this->assertSame(0, $dangling,
            'no preserved follow-up may keep an author_id that no longer exists in users');
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

    /**
     * Reproduces the exact hazard: with FK checks disabled during the truncate loop, TRUNCATE
     * consultations does NOT cascade to consultation_followups and AUTO_INCREMENT resets — so a
     * surviving follow-up row would silently re-attach to whatever re-imported legacy consultation
     * reclaims its old id, corrupting a DIFFERENT patient's "seen today" completeness count. The
     * child table must be truncated in lockstep with its parent whenever the flag is off.
     */
    public function test_followups_do_not_survive_as_orphans_when_consultations_are_rebuilt(): void
    {
        $this->seedLegacy();
        $this->seedLegacyConsultation();
        [$cxId] = $this->seedNewSystemConsultation();   // flag left at its default (false)

        DB::table('consultation_followups')->insert([
            'consultation_id' => $cxId, 'followup_date' => now()->toDateString(), 'note' => 'ticked before reload',
        ]);

        $this->artisan('legacy:import')->assertSuccessful();

        $this->assertSame(0, DB::table('consultation_followups')->count(),
            'follow-up ticks must not survive as orphans when consultations are rebuilt from legacy');
    }

    public function test_import_refuses_a_forced_consultation_wipe_while_the_app_owns_them(): void
    {
        $this->seedLegacy();
        [$cxId] = $this->seedNewSystemConsultation();
        Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import', ['--wipe-consultations' => true])
            ->expectsOutputToContain('Refusing to wipe consultations')
            ->assertFailed();

        // Fail loudly and change NOTHING — not even the rest of the import. The refusal has to come
        // BEFORE the truncate loop and the legacy-user delete, or the command would destroy the whole
        // database and then abort into an unrecoverable half-state. Pin every table the truncate loop
        // would have emptied, not just `consultations` (which is excluded from that loop anyway
        // whenever the flag is on, so it can never detect the guard moving).
        $this->assertSame(1, DB::table('consultations')->where('id', $cxId)->count());
        $this->assertSame(2, DB::table('patients')->count(), 'patients were not truncated');
        $this->assertSame(0, DB::table('admissions')->count(), 'nothing was imported');
        $this->assertSame(0, DB::table('specialties')->count(), 'the reference import never ran');
        $this->assertNotNull(DB::table('users')->where('legacy_id', 7)->value('id'),
            'legacy-sourced users were not deleted');
    }

    public function test_import_nulls_unresolvable_links_and_reports_them_loudly(): void
    {
        $this->seedLegacy();
        [$cxId, $appUserId] = $this->seedNewSystemConsultation();

        // (a) a consultation for a patient that exists ONLY in this app — its MRN is absent from the
        //     legacy dump, so rebuilding `patients` destroys the row it points at. Post-cutover this
        //     is the normal case (the app creates patients itself), and it must be reported, never
        //     silently reported as a successful re-link.
        $appOnlyPatient = Patient::create(['mrn' => '77777', 'name' => 'App Only Pt']);
        $appCx = Consultation::create([
            'mrn' => '77777', 'patient_id' => $appOnlyPatient->id, 'patient_name' => 'App Only Cx',
            'consultation_date' => now()->toDateString(), 'indication' => [],
            'to_service' => 'Hospitalist', 'entered_by' => $appUserId,
        ]);

        // (b) a DANGLING consultant_id: no `users` row carries that id (the state a half-finished
        //     earlier import leaves behind). A missing join row must not be mistaken for
        //     "app-created user, keep the id" — carrying it forward would attach the consult to
        //     whoever inherits that id later, or blow up the FK.
        Schema::disableForeignKeyConstraints();
        DB::table('consultations')->where('id', $cxId)->update(['consultant_id' => 999999]);
        Schema::enableForeignKeyConstraints();

        Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import')
            ->expectsOutputToContain('lost their patient_id')
            ->expectsOutputToContain('lost their consultant_id')
            ->assertSuccessful();

        $this->assertNull(DB::table('consultations')->where('id', $appCx->id)->value('patient_id'),
            'a patient that only ever existed in this app is gone after the rebuild');
        $this->assertSame('77777', DB::table('consultations')->where('id', $appCx->id)->value('mrn'),
            'mrn/patient_name survive, so the link is recoverable once the patient exists again');
        $this->assertNull(DB::table('consultations')->where('id', $cxId)->value('consultant_id'),
            'a dangling user id is nulled, never carried forward verbatim');
    }

    public function test_import_carries_through_every_app_owned_settings_column(): void
    {
        $this->seedLegacy();
        Setting::current()->update([
            'consultations_source_of_truth' => true,
            'app_timezone' => 'Asia/Riyadh',
            'mail_host' => 'smtp.example.test',
            'mail_password' => 'super-secret',
            'audit_retention_years' => 9,
        ]);
        $encrypted = DB::table('settings')->orderBy('id')->value('mail_password');

        $this->artisan('legacy:import')->assertSuccessful();

        // `settings` is truncated and rebuilt from the legacy row, which has none of these columns.
        // Every app-owned column must be carried across the rebuild — wiping mail_password would
        // silently break password-reset email on the next data reload.
        $row = DB::table('settings')->orderBy('id')->first();
        $this->assertSame(1, (int) $row->consultations_source_of_truth);
        $this->assertSame('Asia/Riyadh', $row->app_timezone);
        $this->assertSame('smtp.example.test', $row->mail_host);
        $this->assertSame($encrypted, $row->mail_password, 'the encrypted SMTP password survives verbatim');
        $this->assertSame(9, (int) $row->audit_retention_years);
        // legacy-sourced operational thresholds still come from the legacy row
        $this->assertSame(6, (int) $row->min_hospitalist);
    }

    public function test_import_still_imports_everything_else_while_consultations_are_preserved(): void
    {
        $this->seedLegacy();
        $this->seedNewSystemConsultation();
        Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import')->assertSuccessful();

        // admissions / patients / reference tables import exactly as they do today
        $this->assertSame('Saudi Arabia', DB::table('patients')->where('mrn', '10001')->value('nationality'));
        $this->assertSame(4, DB::table('admissions')->count());
        $this->assertSame(1, DB::table('specialties')->where('name', 'Hospitalist')->count());
        $this->assertNotNull(DB::table('users')->where('legacy_id', 7)->value('id'));
    }

    public function test_import_preserves_coordinator_grants_across_a_reimport(): void
    {
        $this->seedLegacy();
        $this->artisan('legacy:import')->assertSuccessful();

        // an admin grants coordinator access in Control -> Users, AFTER this import already ran
        $userId = DB::table('users')->where('legacy_id', 7)->value('id');
        DB::table('users')->where('id', $userId)->update(['can_coordinate_consultations' => 1]);

        // a later data reload rebuilds `users` from legacy `members`, which has no source column for
        // this flag (see importUsers()) -- the grant must survive by legacy_id, not get silently
        // reset to 0 the way it would if nothing carried it across the rebuild.
        $this->artisan('legacy:import')->assertSuccessful();

        $this->assertSame(
            1,
            (int) DB::table('users')->where('legacy_id', 7)->value('can_coordinate_consultations'),
            'a coordinator grant made in Control -> Users must survive a legacy data reload'
        );
    }

    // ============================================================================================
    // Phase 4 — Item 8: expanded ImportController validator coverage. These exercise the bulk-import
    // CSV parser/committer (NOT the legacy:import command) — they call ImportController::parse via
    // reflection or POST the preview/store endpoints as an admin.
    // ============================================================================================

    private function parse(string $csv): array
    {
        $m = new \ReflectionMethod(ImportController::class, 'parse');
        $m->setAccessible(true);

        return $m->invoke(app(ImportController::class), $csv);
    }

    private function importAdmin(): User
    {
        return User::create([
            'username' => 'imp_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Import Admin', 'role' => User::ROLE_ADMIN, 'active' => 1, 'password' => 'secret12345',
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
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
            ."50751,P2,41,F,Saudi Arabia,2024-02-01,2024-02-03,Alive,Ward\n"
            .'50752,P3,42,M,Saudi Arabia,2024-03-10,2024-03-01,Alive,Ward';

        $this->actingAs($admin)->post('/import/preview', ['rows' => $csv])
            ->assertInertia(fn ($page) => $page->where('preview.valid', 2)->where('preview.invalid', 1));

        $this->actingAs($admin)->post('/import', ['rows' => $csv])->assertRedirect();
        $audit = AuditLog::where('action', 'import.bulk')->latest('id')->first();
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
