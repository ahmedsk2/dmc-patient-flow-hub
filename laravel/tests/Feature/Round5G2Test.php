<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Round-5 fix batch G2 (board group) — role/UX review 2026-09-24, laravel/docs/ROLE-UX-REVIEW-2026-09-24.md §2/§5:
 *  #6  the manually-set "Long-term" flag is carried forward (not reset) on every transfer that
 *      reopens an episode — internal-specialty transfer, ward<->ICU transfer, the external-ICU
 *      receiving episode, and icu-pull.
 *  #13 an internal-specialty transfer's success message names the receiving consultant and says
 *      the episode was closed and a new one opened, instead of the vague "Patient transferred to X".
 *  #34 PatientsController now ships `needsHandoverOwnScope` alongside `needsHandoverCount` so the
 *      board banner can say "your patients" only when the count is genuinely scoped to the viewer.
 *  #40 assign / bulk-reassign / specialty-transfer name the real validation rule ("not an active
 *      consultant") instead of Laravel's generic "the selected … id is invalid".
 */
class Round5G2Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'g2_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'G2 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function consultant(array $extra = []): User
    {
        return $this->user(User::ROLE_CONSULTANT, array_merge(['active' => 1], $extra));
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'G2 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- #6: Long-term carried forward on every reopening transfer -------------------------------

    public function test_internal_specialty_transfer_carries_longterm_flag_forward(): void
    {
        $from = $this->consultant(['full_name' => 'Dr From']);
        $to = $this->consultant(['full_name' => 'Dr To']);
        $specialty = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $a = $this->admission(['consultant_id' => $from->id, 'is_longterm' => true]);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $specialty->id, 'consultant_id' => $to->id,
        ])->assertRedirect();

        $a->refresh();
        $this->assertNotNull($a->discharge_date, 'the old episode must close');
        $new = Admission::whereNull('discharge_date')->where('patient_id', $a->patient_id)->first();
        $this->assertNotNull($new);
        $this->assertTrue((bool) $new->is_longterm, 'is_longterm must carry to the new episode, not reset');
    }

    public function test_ward_icu_location_transfer_carries_longterm_flag_forward(): void
    {
        $a = $this->admission(['is_longterm' => true, 'current_location' => 'Ward']);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", ['target' => 'ICU'])->assertRedirect();

        $new = Admission::whereNull('discharge_date')->where('patient_id', $a->patient_id)->first();
        $this->assertSame('ICU', $new->current_location);
        $this->assertTrue((bool) $new->is_longterm);
    }

    public function test_external_icu_receiving_episode_carries_longterm_flag_forward(): void
    {
        Specialty::create(['name' => 'Intensive Care (ICU)', 'is_subspecialty' => false, 'is_external' => true]);
        $a = $this->admission(['is_longterm' => true]);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", [
            'mode' => 'external', 'service' => 'Intensive Care (ICU)',
        ])->assertRedirect();

        $new = Admission::whereNull('discharge_date')->where('patient_id', $a->patient_id)->first();
        $this->assertNotNull($new, 'the external-ICU service still opens a receiving episode (K1-1)');
        $this->assertSame('ICU', $new->current_location);
        $this->assertTrue((bool) $new->is_longterm);
    }

    public function test_icu_pull_carries_longterm_flag_forward(): void
    {
        $a = $this->admission(['current_location' => 'ICU', 'is_longterm' => true]);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/icu-pull")->assertRedirect();

        $new = Admission::whereNull('discharge_date')->where('patient_id', $a->patient_id)->first();
        $this->assertSame('Ward', $new->current_location);
        $this->assertTrue((bool) $new->is_longterm);
    }

    public function test_transfer_without_longterm_still_opens_a_plain_episode(): void
    {
        // regression guard the other way: a NON-long-term patient must not become long-term
        $a = $this->admission(['is_longterm' => false]);
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", ['target' => 'ICU'])->assertRedirect();
        $new = Admission::whereNull('discharge_date')->where('patient_id', $a->patient_id)->first();
        $this->assertFalse((bool) $new->is_longterm);
    }

    // ---- #13: accurate, named transfer message ----------------------------------------------------

    public function test_specialty_transfer_message_names_receiving_consultant_and_episode_change(): void
    {
        $to = $this->consultant(['full_name' => 'Dr Same Team']);
        $specialty = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $a = $this->admission();

        $resp = $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $specialty->id, 'consultant_id' => $to->id,
        ]);

        $msg = $resp->getSession()->get('flash')['message'] ?? '';
        $this->assertStringContainsString('Dr Same Team', $msg);
        $this->assertStringContainsString('closed', $msg);
        $this->assertStringContainsString('opened', $msg);
    }

    // ---- #40: named validation messages -------------------------------------------------------

    public function test_assign_to_a_non_consultant_gets_a_named_message(): void
    {
        $resident = $this->user(User::ROLE_RESIDENT);
        $a = $this->admission();

        $this->actingAs($this->admin())->from('/patients')->post("/admissions/{$a->id}/assign", [
            'consultant_id' => $resident->id,
        ])->assertRedirect('/patients')->assertSessionHasErrors(['consultant_id']);

        $errors = session('errors')->getBag('default');
        $this->assertSame(
            'Only an active consultant can be assigned this patient — the selected user is not eligible.',
            $errors->first('consultant_id')
        );
    }

    public function test_bulk_reassign_to_a_non_consultant_gets_a_named_message(): void
    {
        $from = $this->consultant();
        $resident = $this->user(User::ROLE_RESIDENT);
        $this->admission(['consultant_id' => $from->id]);

        $this->actingAs($this->admin())->from('/patients')->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $resident->id,
        ])->assertRedirect('/patients')->assertSessionHasErrors(['to_consultant_id']);

        $errors = session('errors')->getBag('default');
        $this->assertSame(
            'Only an active consultant can receive these patients — the selected user is not eligible.',
            $errors->first('to_consultant_id')
        );
    }

    public function test_specialty_transfer_to_a_non_consultant_gets_a_named_message(): void
    {
        $resident = $this->user(User::ROLE_RESIDENT);
        $specialty = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $a = $this->admission();

        $this->actingAs($this->admin())->from('/patients')->post("/admissions/{$a->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $specialty->id, 'consultant_id' => $resident->id,
        ])->assertRedirect('/patients')->assertSessionHasErrors(['consultant_id']);

        $errors = session('errors')->getBag('default');
        $this->assertSame(
            'Only an active consultant can receive this patient — the selected user is not eligible.',
            $errors->first('consultant_id')
        );
    }

    // ---- #34: needsHandoverOwnScope ships alongside needsHandoverCount --------------------------

    public function test_needs_handover_own_scope_true_for_a_plain_consultant(): void
    {
        $me = $this->consultant();
        $this->admission(['consultant_id' => $me->id]);   // no handover today → counted

        $this->actingAs($me)->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('needsHandoverOwnScope', true));
    }

    public function test_needs_handover_own_scope_false_for_admin_and_resident(): void
    {
        $this->actingAs($this->admin())->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('needsHandoverOwnScope', false));

        $this->actingAs($this->user(User::ROLE_RESIDENT))->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('needsHandoverOwnScope', false));
    }
}
