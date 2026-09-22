<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RES-06: the admit form double-submitted (double-click, a retried POST) must not create a second
 * active episode for the same MRN.
 *
 * StoreAdmissionRequest::withValidator() already rejects a second active admission (discharge_date
 * IS NULL) for the MRN being submitted — but that check runs during FormRequest validation, BEFORE
 * AdmissionsController::store()'s transaction even opens. Two requests racing for the same MRN can
 * both pass that check (neither has inserted yet) and both go on to create an admission. The fix
 * moves a second, race-safe copy of the same check inside store()'s transaction, behind a
 * `lockForUpdate()` on the patient row: a concurrent second request blocks on that lock until the
 * first commits, then re-reads with the first's new admission visible (proven empirically against
 * MySQL 8.4 while building this fix — see the AdmissionsController::store() comment for why the
 * re-check itself also needs FOR UPDATE, not just the patient row: under InnoDB's default
 * REPEATABLE READ, a plain SELECT after the lock unblocks can still miss the row a sibling
 * transaction just committed).
 *
 * test_transaction_level_lock_blocks_an_episode_created_mid_request is the test that actually
 * exercises the new code path: it lets StoreAdmissionRequest's own guard pass (zero active episodes
 * at validation time — same as a genuine race, where the competing request hasn't inserted yet
 * either) and only creates the competing admission once the controller's transaction reaches the
 * exact statement (the demographics-refresh save()) that immediately precedes the new lock +
 * re-check, via a one-shot Patient::saved() listener. That is what makes this a genuine test of the
 * new guard rather than a re-test of the pre-existing FormRequest-level one.
 */
class AdmissionDoubleSubmitTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'res06_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'RES-06 Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    /** Full legacy-required admission payload (mirrors Round4H2Test::admitPayload). */
    private function admitPayload(array $overrides = []): array
    {
        Country::firstOrCreate(['name' => 'Saudi Arabia'], ['code' => 'SA']);
        DB::table('icd10')->updateOrInsert(['code' => 'J18.9'], ['name' => 'Pneumonia']);

        return array_merge([
            'mrn' => (string) random_int(10000000, 99999999), 'name' => 'Double Submit', 'age' => 47,
            'gender' => 'Male', 'nationality' => 'Saudi Arabia', 'bed' => 'W-12',
            'admit_date' => now()->toDateString(), 'admitted_from' => 'ER',
            'current_location' => 'Ward', 'diagnoses' => ['J18.9'],
        ], $overrides);
    }

    public function test_sequential_duplicate_post_creates_no_second_row_or_audit_row(): void
    {
        $admin = $this->admin();
        $payload = $this->admitPayload();

        $this->actingAs($admin)->post('/admissions', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Admission::count());
        $this->assertSame(1, AuditLog::where('action', 'admission.create')->count());

        // the double-click / retried POST: same payload, same MRN, still active
        $this->actingAs($admin)->post('/admissions', $payload)
            ->assertSessionHasErrors(['mrn' => 'This MRN already has an active admission.']);

        $this->assertSame(1, Admission::count(), 'no second admissions row');
        $this->assertSame(1, AuditLog::where('action', 'admission.create')->count(), 'no second audit row');
    }

    public function test_transaction_level_lock_blocks_an_episode_created_mid_request(): void
    {
        $mrn = (string) random_int(10000000, 99999999);
        $patient = Patient::create(['mrn' => $mrn, 'name' => 'Mid-Race Patient']);
        // no active admission yet — StoreAdmissionRequest::withValidator() will pass, exactly as it
        // would for the loser of a real race (the winner hasn't inserted at validation time either)

        $fired = false;
        Patient::saved(function (Patient $p) use ($patient, &$fired) {
            // fires on every Patient::save() in this test process (torn down with the app after
            // this test — see Laravel TestCase::refreshApplication()); only act once, and only for
            // the patient under test, so it stands in for "a second request's insert lands here" —
            // strictly AFTER StoreAdmissionRequest::withValidator() has already run and passed
            // (FormRequest validation happens before the controller method is even invoked), so any
            // "already active" error the request comes back with cannot be that check firing late —
            // it can only be the new lock + re-check inside store()'s transaction.
            if ($fired || $p->id !== $patient->id) {
                return;
            }
            $fired = true;
            Admission::create([
                'patient_id' => $patient->id, 'admit_date' => now()->toDateString(),
                'current_location' => 'Ward',
            ]);
        });

        $this->actingAs($this->admin())->post('/admissions', $this->admitPayload(['mrn' => $mrn, 'name' => 'Mid-Race Patient']))
            ->assertSessionHasErrors(['mrn' => 'This MRN already has an active admission.']);

        $this->assertTrue($fired, 'the simulated concurrent insert must have run for this to be a real test of the race');
        // RefreshDatabase wraps this whole test in one outer transaction, so store()'s own
        // DB::transaction() runs as a SAVEPOINT nested inside it; rolling back on the thrown
        // ValidationException rolls back to that savepoint — taking the simulated insert above
        // down with it too, since it ran inside the same nested scope (via the save() it hooked).
        // That is a test-harness artifact, not real behaviour: two genuinely separate requests
        // never share a savepoint, so a real winner's row survives its own, already-committed
        // transaction. What this test proves is the thing that DOES generalize — the guard fires
        // and aborts before a second row or audit entry is added — not the post-rollback count.
        $this->assertSame(0, Admission::where('patient_id', $patient->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'admission.create')->count(),
            'the raced request must never reach Audit::log()');
    }

    public function test_readmission_after_discharge_still_succeeds(): void
    {
        $mrn = (string) random_int(10000000, 99999999);
        $patient = Patient::create(['mrn' => $mrn, 'name' => 'Returning Patient']);
        Admission::create([
            'patient_id' => $patient->id, 'admit_date' => now()->subDays(10)->toDateString(),
            'discharge_date' => now()->subDays(5)->toDateString(), 'current_location' => 'Ward',
        ]);

        $this->actingAs($this->admin())
            ->post('/admissions', $this->admitPayload(['mrn' => $mrn, 'name' => 'Returning Patient']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Admission::where('patient_id', $patient->id)->count());
        $this->assertSame(1, Admission::where('patient_id', $patient->id)->whereNull('discharge_date')->count());
        $this->assertSame(1, AuditLog::where('action', 'admission.create')->count());
    }

    /**
     * The race the patient-row lock cannot reach: the FIRST admission of a brand-new MRN, double-
     * submitted. Neither request finds a patient, both insert one, and the loser hits UNIQUE(mrn).
     * A second, independent connection plays the winner: it inserts and COMMITS the patient at the
     * exact moment this request is about to insert its own — so this request's firstOrCreate() meets
     * a real duplicate key it cannot re-read inside its own snapshot, as in production. Before the fix
     * that surfaced as a 500; now it is the ordinary "already admitted" validation error.
     */
    public function test_first_admission_race_on_a_brand_new_mrn_is_a_validation_error_not_a_500(): void
    {
        $admin = $this->admin();
        $payload = $this->admitPayload();

        config(['database.connections.race' => config('database.connections.'.config('database.default'))]);
        $winnerId = null;
        Patient::creating(function (Patient $p) use (&$winnerId, $payload) {
            if ($winnerId !== null || $p->mrn !== $payload['mrn']) {
                return;
            }
            $winnerId = DB::connection('race')->table('patients')->insertGetId([
                'mrn' => $payload['mrn'], 'name' => 'Winner', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        // The winner's committed row outlives this test's rolled-back transaction, so remove it once
        // that transaction has ended (this callback runs after RefreshDatabase's own rollback) — the
        // loser's failed INSERT holds a lock on the row until then.
        $this->beforeApplicationDestroyed(function () use (&$winnerId) {
            if ($winnerId !== null) {
                DB::connection('race')->table('patients')->where('id', $winnerId)->delete();
            }
            DB::purge('race');
        });

        $this->actingAs($admin)->post('/admissions', $payload)
            ->assertSessionHasErrors(['mrn' => 'This MRN already has an active admission.']);

        $this->assertNotNull($winnerId, 'the competing insert never ran — the race was not exercised');
        $this->assertSame(0, AuditLog::where('action', 'admission.create')->count());
    }
}
