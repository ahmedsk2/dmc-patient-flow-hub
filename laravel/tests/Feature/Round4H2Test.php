<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Country;
use App\Models\Handover;
use App\Models\HandoverSignature;
use App\Models\Patient;
use App\Models\TbDiagnosis;
use App\Models\User;
use App\Services\ShuffleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Round-4 batch H2 — legacy definitions (B) + polish (C):
 *  B1/B2 admission required-ness (age/gender/nationality/bed + ≥1 diagnosis) and controlled
 *        selects (nationality ∈ countries; admitted_from ∈ the 9-value legacy enum), with
 *        Modify keeping validate-only-on-change for dirty legacy values
 *  B3    bulk reassign moves a SELECTED SUBSET (admission_ids[]), each validated to belong
 *        to the from-consultant; handover gate + signatures apply per selected patient
 *  B4    Recent registry window = yesterday+today; reverse discharge/sign-off = SAME-DAY only
 *  B5    shuffle load excludes medically-discharged + TB patients; an ICU queue row is only
 *        ever given to a hospitalist (legacy newpatients/dmc-patients-shuffle.php:76-102)
 *  B6    LOS counts to discharge or TODAY — a phase-1 medical discharge does NOT freeze it
 *  B7    New Admissions queue is FIFO (oldest first)
 *  B8    dashboard top-dx card = same calendar week-number across ALL years (legacy
 *        WEEK(ADMDATE) = WEEK(today)), top 5 — DELIBERATE definition change from last-7-days
 *  B9    zero-census on-service consultants appear (dashboard board + patients board);
 *        off-service consultants only when they hold patients
 *  B10   census-by-service donut excludes unassigned rows (legacy INNER JOIN members)
 *  B11   printable active-list is NOT D1-scoped and carries the per-consultant counts table
 *  C1    remember-me recaller cookie lives 30 days (43200 min)
 *  C2    NULL pass_exp_date = EXPIRED (legacy strtotime(false) → 1970); new users are stamped
 *  C3    MFA challenge: pending identity expires after 5 minutes, max 8 attempts
 *  C6    legacy export compat: Export-DD-MM-YYYY filename, UPPERCASED values, ' || ' dx join
 *  C7    legacy .php URLs 301-redirect to their replacements
 *  C8    delay_reason case normalization migration
 */
class Round4H2Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'h2_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'H2 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?: Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'H2 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    /** Full legacy-required admission payload (B1) — overrides knock fields out per test. */
    private function admitPayload(array $overrides = []): array
    {
        Country::firstOrCreate(['name' => 'Saudi Arabia'], ['code' => 'SA']);
        \Illuminate\Support\Facades\DB::table('icd10')->updateOrInsert(['code' => 'J18.9'], ['name' => 'Pneumonia']);   // Phase 4 — Item 5

        return array_merge([
            'mrn' => (string) random_int(10000000, 99999999), 'name' => 'Full Payload', 'age' => 47,
            'gender' => 'Male', 'nationality' => 'Saudi Arabia', 'bed' => 'W-12',
            'admit_date' => now()->toDateString(), 'admitted_from' => 'ER',
            'current_location' => 'Ward', 'diagnoses' => ['J18.9'],
        ], $overrides);
    }

    // ---- B1: admission required-ness ----------------------------------------------------------

    public function test_admission_requires_age_gender_nationality_bed_and_a_diagnosis(): void
    {
        $this->actingAs($this->admin())->post('/admissions', [
            'mrn' => '12312399', 'name' => 'Sparse Payload',
            'admit_date' => now()->toDateString(), 'current_location' => 'Ward',
        ])->assertSessionHasErrors(['age', 'gender', 'nationality', 'bed', 'diagnoses']);

        $this->assertDatabaseCount('admissions', 0);

        // an empty diagnoses array is as bad as a missing one (legacy Fill-All)
        $this->actingAs($this->admin())->post('/admissions', $this->admitPayload(['diagnoses' => []]))
            ->assertSessionHasErrors('diagnoses');
    }

    public function test_admission_with_full_payload_persists_including_nationality(): void
    {
        $this->actingAs($this->admin())->post('/admissions', $this->admitPayload(['mrn' => '77001122']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('patients', ['mrn' => '77001122', 'nationality' => 'Saudi Arabia']);
        $this->assertDatabaseCount('admission_diagnoses', 1);
    }

    // ---- B2: controlled selects ----------------------------------------------------------------

    public function test_admission_rejects_unknown_nationality_and_admitted_from(): void
    {
        $this->actingAs($this->admin())->post('/admissions', $this->admitPayload(['nationality' => 'Atlantis']))
            ->assertSessionHasErrors('nationality');

        $this->actingAs($this->admin())->post('/admissions', $this->admitPayload(['admitted_from' => 'The Moon']))
            ->assertSessionHasErrors('admitted_from');
    }

    public function test_modify_validates_nationality_and_admitted_from_only_on_change(): void
    {
        Country::firstOrCreate(['name' => 'Saudi Arabia'], ['code' => 'SA']);
        $p = Patient::create(['mrn' => '88112233', 'name' => 'Dirty Nat', 'nationality' => 'SAUDIA!!']);   // dirty legacy value
        $a = $this->admission(['admitted_from' => 'old referral letter'], $p);                              // dirty legacy source

        // untouched dirty values must NOT block an unrelated bed edit
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => '88112233', 'name' => 'Dirty Nat', 'nationality' => 'SAUDIA!!',
            'admitted_from' => 'old referral letter', 'bed' => 'W-3',
            'admit_date' => $a->admit_date->toDateString(), 'current_location' => 'Ward', 'diagnoses' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('W-3', $a->fresh()->bed);

        // CHANGED values must pass the controlled vocabularies
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => '88112233', 'name' => 'Dirty Nat', 'nationality' => 'Atlantis',
            'admitted_from' => 'old referral letter', 'bed' => 'W-3',
            'admit_date' => $a->admit_date->toDateString(), 'current_location' => 'Ward', 'diagnoses' => [],
        ])->assertSessionHasErrors('nationality');

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => '88112233', 'name' => 'Dirty Nat', 'nationality' => 'SAUDIA!!',
            'admitted_from' => 'Carrier Pigeon', 'bed' => 'W-3',
            'admit_date' => $a->admit_date->toDateString(), 'current_location' => 'Ward', 'diagnoses' => [],
        ])->assertSessionHasErrors('admitted_from');

        // a changed-to-VALID pair is accepted
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => '88112233', 'name' => 'Dirty Nat', 'nationality' => 'Saudi Arabia',
            'admitted_from' => 'Referral', 'bed' => 'W-3',
            'admit_date' => $a->admit_date->toDateString(), 'current_location' => 'Ward', 'diagnoses' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Saudi Arabia', $p->fresh()->nationality);
        $this->assertSame('Referral', $a->fresh()->admitted_from);
    }

    // ---- B3: bulk reassign subset ---------------------------------------------------------------

    public function test_bulk_reassign_moves_only_the_selected_subset(): void
    {
        $from = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1]);
        $to = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1]);
        $a1 = $this->admission(['consultant_id' => $from->id]);
        $a2 = $this->admission(['consultant_id' => $from->id]);
        $a3 = $this->admission(['consultant_id' => $from->id]);   // NOT selected — stale handover must not block

        foreach ([$a1, $a2] as $a) {
            Handover::create(['admission_id' => $a->id, 'body' => 'fresh today', 'updated_by' => $from->id, 'updated_at' => now()]);
        }

        $this->actingAs($this->admin())->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id,
            'admission_ids' => [$a1->id, $a2->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame((int) $to->id, (int) $a1->fresh()->consultant_id);
        $this->assertSame((int) $to->id, (int) $a2->fresh()->consultant_id);
        $this->assertSame((int) $from->id, (int) $a3->fresh()->consultant_id, 'unselected patients must stay put');

        // one pending signature per MOVED admission only
        $this->assertSame(2, HandoverSignature::whereIn('admission_id', [$a1->id, $a2->id])->whereNull('voided_at')->count());
        $this->assertSame(0, HandoverSignature::where('admission_id', $a3->id)->count());
    }

    public function test_bulk_reassign_rejects_ids_not_belonging_to_the_from_consultant(): void
    {
        $from = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1]);
        $to = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1]);
        $other = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1]);
        $foreign = $this->admission(['consultant_id' => $other->id]);
        Handover::create(['admission_id' => $foreign->id, 'body' => 'x', 'updated_by' => $other->id, 'updated_at' => now()]);

        $this->actingAs($this->admin())->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id,
            'admission_ids' => [$foreign->id],
        ])->assertSessionHasErrors('admission_ids');

        $this->assertSame((int) $other->id, (int) $foreign->fresh()->consultant_id, 'a foreign admission must never move');
    }

    // ---- B4: recent windows --------------------------------------------------------------------

    public function test_recent_registry_lists_yesterday_and_today_with_same_day_reversibility(): void
    {
        $mk = fn (string $date, string $mrn) => $this->admission([
            'discharge_date' => $date, 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward',
        ], Patient::create(['mrn' => $mrn, 'name' => "Recent {$mrn}"]));
        $mk(now()->toDateString(), '91000001');
        $mk(now()->subDay()->toDateString(), '91000002');
        $mk(now()->subDays(2)->toDateString(), '91000003');   // outside the yesterday+today window

        $this->actingAs($this->admin())->get('/recent')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('discharges', 2)
                ->where('discharges.0.mrn', '91000001')
                ->where('discharges.0.reversible', true)
                ->where('discharges.1.mrn', '91000002')
                ->where('discharges.1.reversible', false));
    }

    public function test_reverse_discharge_is_same_day_only(): void
    {
        // Phase 4 — Item 4: reverse-discharge is step-up gated
        $stepup = ['stepup.verified_at' => now()->getTimestamp()];
        $yesterday = $this->admission(['discharge_date' => now()->subDay()->toDateString(),
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        $this->actingAs($this->admin())->withSession($stepup)->post("/admissions/{$yesterday->id}/reverse-discharge")->assertRedirect();
        $this->assertNotNull($yesterday->fresh()->discharge_date, 'yesterday\'s discharge must NOT be reversible (legacy same-day undo)');

        $today = $this->admission(['discharge_date' => now()->toDateString(),
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        $this->actingAs($this->admin())->withSession($stepup)->post("/admissions/{$today->id}/reverse-discharge")->assertRedirect();
        $this->assertNull($today->fresh()->discharge_date, 'same-day discharges remain reversible');
    }

    public function test_reverse_signoff_is_same_day_only(): void
    {
        $yesterday = \App\Models\Consultation::create(['mrn' => '91000010', 'patient_name' => 'Y Signoff',
            'consultation_date' => now()->subDays(2)->toDateString(), 'signoff_date' => now()->subDay()->toDateString()]);
        $this->actingAs($this->admin())->post("/consultations/{$yesterday->id}/reverse-signoff")->assertRedirect();
        $this->assertNotNull($yesterday->fresh()->signoff_date, 'yesterday\'s sign-off must NOT be reversible (legacy same-day undo)');

        $today = \App\Models\Consultation::create(['mrn' => '91000011', 'patient_name' => 'T Signoff',
            'consultation_date' => now()->subDay()->toDateString(), 'signoff_date' => now()->toDateString()]);
        $this->actingAs($this->admin())->post("/consultations/{$today->id}/reverse-signoff")->assertRedirect();
        $this->assertNull($today->fresh()->signoff_date, 'same-day sign-offs remain reversible');
    }

    // ---- B5: shuffle load + ICU routing ----------------------------------------------------------

    public function test_shuffle_load_excludes_medically_discharged_and_tb_patients(): void
    {
        \App\Models\Setting::current()->update(['min_hospitalist' => 5, 'max_hospitalist' => 10, 'min_subs' => 0, 'max_subs' => 0]);
        TbDiagnosis::create(['icd10_code' => 'A15.0']);
        $a = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1, 'specialty_id' => 1]);   // lower id wins ties
        $b = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1, 'specialty_id' => 1]);

        // A carries one med-discharged and one TB patient — neither counts toward load (legacy)
        $this->admission(['consultant_id' => $a->id, 'medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive']);
        $this->admission(['consultant_id' => $a->id])->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);
        // B carries one plain active patient — load 1
        $this->admission(['consultant_id' => $b->id]);

        $queued = $this->admission();   // unassigned ward patient
        (new ShuffleService)->run();

        $this->assertSame((int) $a->id, (int) $queued->fresh()->consultant_id,
            'A (effective load 0: med-discharged + TB excluded) must receive the patient before B (load 1)');
    }

    public function test_icu_queue_row_goes_to_a_hospitalist_even_when_a_subspecialist_is_less_loaded(): void
    {
        // hospitalist already AT CAP (max 1) — a ward row overflows to the sub, but an ICU row must not
        \App\Models\Setting::current()->update(['min_hospitalist' => 0, 'max_hospitalist' => 1, 'min_subs' => 5, 'max_subs' => 10]);
        $hosp = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1, 'specialty_id' => 1]);
        $sub = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1, 'specialty_id' => 2]);
        $this->admission(['consultant_id' => $hosp->id]);   // hospitalist ward load 1 (= max); sub 0

        $wardRow = $this->admission();                                 // unassigned ward patient
        $icuRow = $this->admission(['current_location' => 'ICU']);     // unassigned ICU patient
        (new ShuffleService)->run();

        $this->assertSame((int) $sub->id, (int) $wardRow->fresh()->consultant_id,
            'the WARD row overflows to the less-loaded subspecialist as usual');
        $this->assertSame((int) $hosp->id, (int) $icuRow->fresh()->consultant_id,
            'legacy never gives an ICU queue row to a subspecialist — even at cap it lands on a hospitalist');
    }

    // ---- B6: LOS keeps counting for "discharged still in" ----------------------------------------

    public function test_los_ignores_medical_discharge_and_counts_to_today(): void
    {
        $a = $this->admission([
            'admit_date' => now()->subDays(10)->toDateString(),
            'medical_discharge_date' => now()->subDays(5)->toDateString(),
            'outcome' => 'Alive',
        ]);
        $this->assertSame(10, $a->lengthOfStay(), 'phase-1 medical discharge must NOT freeze the LOS (legacy counted to today)');

        $closed = $this->admission([
            'admit_date' => now()->subDays(10)->toDateString(),
            'medical_discharge_date' => now()->subDays(5)->toDateString(),
            'discharge_date' => now()->subDays(2)->toDateString(),
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward',
        ]);
        $this->assertSame(8, $closed->lengthOfStay(), 'a CLOSED file pins LOS at the physical discharge date');
    }

    // ---- B7: FIFO queue ---------------------------------------------------------------------------

    public function test_admissions_queue_is_oldest_first(): void
    {
        $old = $this->admission(['admit_date' => now()->subDays(6)->toDateString()]);
        $newer = $this->admission(['admit_date' => now()->subDay()->toDateString()]);
        $oldest = $this->admission(['admit_date' => now()->subDays(9)->toDateString()]);

        $this->actingAs($this->admin())->get('/admissions')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('queue.0.id', $oldest->id)
                ->where('queue.1.id', $old->id)
                ->where('queue.2.id', $newer->id));
    }

    // ---- B8: top-dx = same calendar week across ALL years ----------------------------------------

    /** A date in the prior year whose MySQL WEEK() equals this week's number. */
    private function priorYearSameWeekDate(): string
    {
        $wk = (int) DB::selectOne('SELECT WEEK(CURDATE()) wk')->wk;
        for ($i = 0; $i <= 21; $i++) {
            foreach ([now()->subYear()->addDays($i), now()->subYear()->subDays($i)] as $cand) {
                if ((int) DB::selectOne('SELECT WEEK(?) wk', [$cand->toDateString()])->wk === $wk) {
                    return $cand->toDateString();
                }
            }
        }
        $this->fail('no prior-year date shares the current WEEK() number');
    }

    public function test_top_dx_card_aggregates_the_same_week_number_across_years(): void
    {
        $this->admission(['admit_date' => now()->toDateString()])
            ->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);
        $this->admission(['admit_date' => $this->priorYearSameWeekDate(),
            'discharge_date' => $this->priorYearSameWeekDate(), 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward'])
            ->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);
        // a different week this year must NOT count
        $this->admission(['admit_date' => now()->subWeeks(10)->toDateString()])
            ->diagnoses()->create(['seq' => 1, 'icd10_code' => 'E11.9']);

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('topDxWeek', 1)
                ->where('topDxWeek.0.name', 'J18.9')   // falls back to the code (icd10 not seeded)
                ->where('topDxWeek.0.count', 2)
                ->has('topDxWeekNum'));
    }

    // ---- B9: zero-census consultants --------------------------------------------------------------

    public function test_zero_census_on_service_consultant_appears_on_dashboard_and_board(): void
    {
        $zero = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Zero Census', 'on_service' => 1, 'specialty_id' => 1]);
        $offZero = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Off Empty', 'on_service' => 0]);
        $loaded = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Loaded', 'on_service' => 1, 'specialty_id' => 1]);
        $this->admission(['consultant_id' => $loaded->id]);

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('consultantBoard', function ($cb) {
                    $cb = collect($cb);
                    $zeroRow = $cb->firstWhere('name', 'Dr Zero Census');

                    return $zeroRow && $zeroRow['total'] === 0 && $zeroRow['on_service'] === true
                        && $cb->firstWhere('name', 'Dr Loaded')
                        && ! $cb->firstWhere('name', 'Dr Off Empty');   // off-service only WITH patients
                }));

        $this->actingAs($this->admin())->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups', function ($groups) {
                    $groups = collect($groups);

                    return $groups->firstWhere('name', 'Dr Zero Census')
                        && ($groups->firstWhere('name', 'Dr Zero Census')['counts']['total'] ?? null) === 0
                        && ! $groups->firstWhere('name', 'Dr Off Empty');
                }));
    }

    // ---- B10: census donut excludes unassigned -----------------------------------------------------

    public function test_census_by_service_excludes_unassigned_patients(): void
    {
        $hosp = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1, 'specialty_id' => 1]);
        $this->admission(['consultant_id' => $hosp->id]);
        $this->admission();   // unassigned active ward patient — legacy INNER JOIN drops it

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mix.hospitalist', 1)
                ->where('mix.subspecialty', 0)
                ->where('mix.longterm', 0));
    }

    // ---- B11: printable active list -----------------------------------------------------------------

    public function test_active_list_is_not_consultant_scoped(): void
    {
        $me = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Me', 'on_service' => 1, 'specialty_id' => 1]);
        $colleague = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Colleague', 'on_service' => 1, 'specialty_id' => 1]);
        $this->admission(['consultant_id' => $me->id]);
        $this->admission(['consultant_id' => $colleague->id]);

        // the interactive board stays D1-scoped for a consultant…
        $this->actingAs($me)->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups', fn ($g) => collect($g)->pluck('name')->doesntContain('Dr Colleague')));

        // …but the printable census shows EVERY consultant (legacy active-list.php)
        $this->actingAs($me)->get('/active-list')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups', fn ($g) => collect($g)->pluck('name')->contains('Dr Colleague')
                    && collect($g)->pluck('name')->contains('Dr Me')));
    }

    // ---- C1: remember-me 30 days ----------------------------------------------------------------------

    public function test_remember_me_cookie_lives_thirty_days(): void
    {
        $this->assertSame(43200, config('auth.guards.web.remember'), '30-day recaller TTL must be configured');

        $u = $this->user(User::ROLE_CONSULTANT);
        $res = $this->post('/login', ['username' => $u->username, 'password' => 'secret12345', 'remember' => true]);
        $res->assertRedirect();

        $cookie = collect($res->headers->getCookies())
            ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie, 'remember login must queue the recaller cookie');
        $this->assertEqualsWithDelta(now()->addMinutes(43200)->getTimestamp(), $cookie->getExpiresTime(), 300,
            'recaller cookie must expire in ~30 days (legacy parity), not the framework 400-day default');
    }

    // ---- C2: NULL pass_exp_date = expired ----------------------------------------------------------------

    public function test_null_pass_exp_date_forces_a_password_change(): void
    {
        $u = $this->user(User::ROLE_CONSULTANT);
        $this->assertNotNull($u->fresh()->pass_exp_date, 'new accounts must be stamped on create');

        $u->forceFill(['pass_exp_date' => null])->save();   // legacy-imported account with no date
        $this->actingAs($u)->get('/patients')->assertRedirect(route('profile.edit'));

        $u->forceFill(['pass_exp_date' => now()->toDateString()])->save();
        $this->actingAs($u)->get('/patients')->assertOk();
    }

    // ---- C3: MFA challenge hardening ------------------------------------------------------------------------

    private function mfaUser(): User
    {
        return User::create([
            'username' => 'h2m_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'H2 Mfa', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => \App\Support\Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    public function test_stale_mfa_pending_identity_is_rejected(): void
    {
        $u = $this->mfaUser();
        $stale = ['mfa.pending.id' => $u->id, 'mfa.pending.at' => now()->subMinutes(6)->getTimestamp()];

        $this->withSession($stale)->get('/mfa/challenge')->assertRedirect(route('login'));
        $this->withSession($stale)->post('/mfa/challenge', ['code' => '000000'])->assertRedirect(route('login'));
        $this->assertGuest();

        // a fresh pending identity still renders the challenge
        $this->withSession(['mfa.pending.id' => $u->id, 'mfa.pending.at' => now()->getTimestamp()])
            ->get('/mfa/challenge')->assertOk();
    }

    public function test_mfa_challenge_attempts_are_capped_at_eight(): void
    {
        $u = $this->mfaUser();

        // attempt 3 of 8 with a wrong code: rejected but the challenge stays open
        $this->withSession(['mfa.pending.id' => $u->id, 'mfa.pending.at' => now()->getTimestamp(), 'mfa.pending.attempts' => 2])
            ->post('/mfa/challenge', ['code' => 'XXXXXX'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        // the 9th attempt kills the pending login entirely
        $this->withSession(['mfa.pending.id' => $u->id, 'mfa.pending.at' => now()->getTimestamp(), 'mfa.pending.attempts' => 8])
            ->post('/mfa/challenge', ['code' => 'XXXXXX'])
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ---- C6: legacy export compat -------------------------------------------------------------------------------

    public function test_export_uses_legacy_filename_uppercase_values_and_double_pipe_dx_join(): void
    {
        \App\Models\Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        \App\Models\Icd10::create(['code' => 'E11.9', 'name' => 'Type 2 diabetes mellitus without complications']);
        $p = Patient::create(['mrn' => '92000001', 'name' => 'Export Pt', 'gender' => 'Male', 'nationality' => 'Saudi Arabia']);
        $a = $this->admission(['discharge_to' => 'Home', 'outcome' => 'Alive'], $p);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);
        $a->diagnoses()->create(['seq' => 2, 'icd10_code' => 'E11.9']);

        $res = $this->actingAs($this->admin())->get('/registry/export');
        $res->assertOk();
        $res->assertDownload('Export-' . now()->format('d-m-Y') . '.csv');

        $csv = $res->streamedContent();
        $this->assertStringContainsString('PNEUMONIA, UNSPECIFIED ORGANISM || TYPE 2 DIABETES MELLITUS WITHOUT COMPLICATIONS', $csv,
            'diagnoses must be UPPERCASED and joined with the legacy " || " separator');
        $this->assertStringContainsString('SAUDI ARABIA', $csv);
        $this->assertStringContainsString('HOME', $csv);
        // the header row keeps its clinical Title Case (only VALUES are uppercased)
        $this->assertStringContainsString('Clinical Discharge Date', $csv);

        $xlsx = $this->actingAs($this->admin())->get('/registry/export-xlsx');
        $xlsx->assertOk();
        $xlsx->assertDownload('Export-' . now()->format('d-m-Y') . '.xlsx');
    }

    // ---- C7: legacy URL redirects ----------------------------------------------------------------------------------

    public function test_legacy_php_urls_redirect_permanently(): void
    {
        foreach ([
            '/dashboard.php' => '/',
            '/dmc-patients.php' => '/patients',
            '/dmc-new-admissions.php' => '/admissions',
            '/active-list.php' => '/active-list',
            '/dmc-new-consultation.php' => '/consultations',
            '/48discharge.php' => '/recent',
            '/48consultation.php' => '/recent',
            '/search.php' => '/registry',
            '/statistics.php' => '/statistics',
            '/allstat.php' => '/statistics',
            '/control.php' => '/control',
            '/profile.php' => '/profile',
        ] as $old => $new) {
            $res = $this->get($old);
            $res->assertRedirect($new);
            $this->assertSame(301, $res->getStatusCode(), "{$old} must redirect permanently");
        }
    }

    // ---- C8: delay_reason case normalization -----------------------------------------------------------------------

    public function test_delay_reason_case_normalization_migration(): void
    {
        $lower = $this->admission(['medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive']);
        DB::table('admissions')->where('id', $lower->id)->update(['delay_reason' => 'physical']);
        $lowerSys = $this->admission(['medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive']);
        DB::table('admissions')->where('id', $lowerSys->id)->update(['delay_reason' => 'system']);

        $migration = include database_path('migrations/2026_06_11_000003_normalize_delay_reason_case.php');
        $migration->up();

        $this->assertSame('Physical', $lower->fresh()->delay_reason);
        $this->assertSame('System', $lowerSys->fresh()->delay_reason);
    }
}
