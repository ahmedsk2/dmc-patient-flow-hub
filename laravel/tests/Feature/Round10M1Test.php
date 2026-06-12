<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\TbDiagnosis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Round-10 fix batch M1:
 *  1. ProfileController::updatePassword rejects a new password equal to the CURRENT one
 *     (legacy change-password.php:41-43 parity — keeps the 3-month expiry meaningful)
 *  2. ProfileController::updateProfile email gains app-level uniqueness ignoring self —
 *     mirrors RegisterController/ControlController (the DB unique index is deliberately absent)
 *  3. Registry CSV export neutralizes spreadsheet formula injection: cells starting with
 *     = + - @ get a leading apostrophe (legacy $safeCell, registry/search-results-diagnosis.php:91-94);
 *     the XLSX path is unchanged (OpenSpout writes typed strings — never evaluated)
 *  4. self-registration REQUIRES an email (the nullable column stays only for imported legacy users)
 *  5. dashboard census-donut headline (donutTotal/donutTb) counts the ASSIGNED NON-ICU population —
 *     the donut's own slices (legacy dashboard/1.php:151-154) — while the Active Census KPI tile
 *     (kpis.census) stays all-active
 */
class Round10M1Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'm1_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'M1 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'M1 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1. password change must actually CHANGE the password -------------------------------------

    public function test_password_change_rejects_reusing_the_current_password(): void
    {
        $u = $this->user(User::ROLE_CONSULTANT, ['pass_exp_date' => now()->subDays(10)->toDateString()]);

        $this->actingAs($u)->from('/profile')->put('/profile/password', [
            'current_password' => 'secret12345',
            'password' => 'secret12345', 'password_confirmation' => 'secret12345',
        ])->assertRedirect('/profile')
            ->assertSessionHasErrors(['password' => 'Choose a password different from your previous one.']);

        $u->refresh();
        $this->assertTrue(Hash::check('secret12345', $u->password), 'the stored password must be untouched');
        $this->assertSame(now()->subDays(10)->toDateString(), $u->pass_exp_date->toDateString(),
            'a rejected change must NOT restamp the 3-month expiry clock');
    }

    public function test_password_change_accepts_a_different_password_and_restamps_expiry(): void
    {
        $u = $this->user(User::ROLE_CONSULTANT, ['pass_exp_date' => now()->subDays(10)->toDateString()]);

        $this->actingAs($u)->put('/profile/password', [
            'current_password' => 'secret12345',
            'password' => 'fresh12345', 'password_confirmation' => 'fresh12345',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $u->refresh();
        $this->assertTrue(Hash::check('fresh12345', $u->password));
        $this->assertSame(now()->toDateString(), $u->pass_exp_date->toDateString(),
            'a successful change restamps the expiry clock');
    }

    // ---- 2. profile email uniqueness (app-level, ignore self) -------------------------------------

    public function test_profile_rejects_taking_another_users_email_but_keeps_own(): void
    {
        $this->user(User::ROLE_RESIDENT, ['email' => 'taken@example.test']);
        $u = $this->user(User::ROLE_RESIDENT, ['username' => 'mail_mine', 'email' => 'mine@example.test']);

        $this->actingAs($u)->from('/profile')->put('/profile', [
            'full_name' => 'Dr Mine', 'email' => 'taken@example.test', 'username' => 'mail_mine',
        ])->assertRedirect('/profile')->assertSessionHasErrors('email');
        $this->assertSame('mine@example.test', $u->fresh()->email, 'the takeover must not persist');

        // keeping one's OWN email is not a collision
        $this->actingAs($u)->put('/profile', [
            'full_name' => 'Dr Mine', 'email' => 'mine@example.test', 'username' => 'mail_mine',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    // ---- 3. CSV export formula-injection guard ----------------------------------------------------

    public function test_csv_export_prefixes_formula_leading_cells_with_apostrophe(): void
    {
        $p = Patient::create(['mrn' => '=2+2', 'name' => 'Formula Pt', 'nationality' => '@SUM(A1)']);
        $this->admission(['admitted_from' => '-ER'], $p);

        $csv = $this->actingAs($this->admin())->get('/registry/export')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString("'=2+2", $csv, 'leading = must be apostrophe-neutralized');
        $this->assertStringContainsString("'@SUM(A1)", $csv, 'leading @ must be apostrophe-neutralized');
        $this->assertStringContainsString("'-ER", $csv, 'leading - must be apostrophe-neutralized');
        // no cell may START with a live formula char (header row stays clean Title Case anyway)
        foreach (array_filter(explode("\n", trim($csv))) as $i => $line) {
            if ($i === 0) {
                continue;
            }
            foreach (str_getcsv($line) as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@]/', $cell,
                    "exported cell '{$cell}' still starts with a formula character");
            }
        }
    }

    // ---- 4. registration requires an email --------------------------------------------------------

    public function test_registration_requires_an_email(): void
    {
        $this->post('/register', [
            'username' => 'm1_newuser', 'full_name' => 'New User', 'role' => 4,
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertSessionHasErrors('email');
        $this->assertNull(User::where('username', 'm1_newuser')->first(), 'no account without an email');

        $this->post('/register', [
            'username' => 'm1_newuser', 'full_name' => 'New User', 'email' => 'new@example.test',
            'role' => 4, 'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertSame('new@example.test', User::where('username', 'm1_newuser')->first()?->email);
    }

    // ---- 5. dashboard census-donut headline = the donut's own population ---------------------------

    public function test_donut_headline_counts_assigned_non_icu_only(): void
    {
        \App\Models\Icd10::create(['code' => 'A15.0', 'name' => 'Tuberculosis of lung']);
        TbDiagnosis::create(['icd10_code' => 'A15.0']);
        $hosp = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1, 'specialty_id' => 1]);

        $tbWard = $this->admission(['consultant_id' => $hosp->id]);                                // both
        $tbWard->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);
        $this->admission(['consultant_id' => $hosp->id]);                                          // total only
        $this->admission(['consultant_id' => $hosp->id, 'is_longterm' => 1]);                      // total only (long-term slice)
        $tbIcu = $this->admission(['consultant_id' => $hosp->id, 'current_location' => 'ICU']);    // ICU — excluded
        $tbIcu->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);
        $tbUnassigned = $this->admission();                                                        // unassigned — excluded
        $tbUnassigned->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('donutTotal', 3)   // = the mix slices: 2 hospitalist + 0 subspecialty + 1 long-term
                ->where('donutTb', 1)      // ICU + unassigned TB rows do NOT count
                ->where('mix.hospitalist', 2)->where('mix.subspecialty', 0)->where('mix.longterm', 1)
                ->where('kpis.census', 5));   // the Active Census KPI tile stays ALL-ACTIVE
    }
}
