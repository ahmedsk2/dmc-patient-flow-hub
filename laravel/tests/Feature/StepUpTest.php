<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 — Item 4: step-up re-authentication for the highest-risk actions (admission delete +
 * reverse-discharge, user delete, role->0 grant). A fresh (<5 min) step-up window in the session
 * lets the action through; otherwise the user is bounced to /stepup.
 */
class StepUpTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'su_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'SU Admin', 'role' => User::ROLE_ADMIN, 'active' => 1, 'password' => 'secret12345',
        ], $extra));
    }

    private function admission(array $a = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(100000, 999999), 'name' => 'Pt', 'age' => 40, 'gender' => 'Male']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->toDateString(), 'current_location' => 'Ward',
        ], $a));
    }

    public function test_delete_admission_without_stepup_redirects(): void
    {
        $a = $this->admission();
        $this->actingAs($this->admin())->delete("/admissions/{$a->id}")->assertRedirect('/stepup');
        $this->assertNull(Admission::find($a->id) ? Admission::find($a->id)->deleted_at : null);   // not deleted
    }

    public function test_delete_admission_with_valid_stepup_succeeds(): void
    {
        $a = $this->admission();
        $this->actingAs($this->admin())
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/admissions/{$a->id}")->assertRedirect();

        $this->assertNotNull(Admission::withTrashed()->find($a->id)->deleted_at);
    }

    public function test_delete_admission_with_expired_stepup_redirects(): void
    {
        $a = $this->admission();
        $this->actingAs($this->admin())
            ->withSession(['stepup.verified_at' => now()->subMinutes(6)->getTimestamp()])
            ->delete("/admissions/{$a->id}")->assertRedirect('/stepup');

        $this->assertNull(Admission::find($a->id)?->deleted_at);
    }

    public function test_stepup_verify_correct_password_stamps_session(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/stepup', ['password' => 'secret12345'])->assertRedirect();

        $this->assertNotNull(session('stepup.verified_at'));
        $this->assertTrue(now()->getTimestamp() - (int) session('stepup.verified_at') <= 5);
    }

    public function test_stepup_verify_wrong_password_fails(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/stepup', ['password' => 'wrongpass'])
            ->assertSessionHasErrors('password');
        $this->assertNull(session('stepup.verified_at'));
    }

    public function test_stepup_verify_mfa_admin_requires_totp(): void
    {
        $secret = \App\Support\Totp::secret();
        $admin = $this->admin(['mfa_secret' => $secret, 'mfa_enrolled_at' => now()]);

        // correct password but missing TOTP code
        $this->actingAs($admin)->post('/stepup', ['password' => 'secret12345'])
            ->assertSessionHasErrors('code');
        $this->assertNull(session('stepup.verified_at'));
    }

    public function test_role_escalation_to_admin_requires_stepup(): void
    {
        $admin = $this->admin();
        $victim = User::create([
            'username' => 'promo_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Promo', 'role' => User::ROLE_CONSULTANT, 'active' => 1, 'password' => 'secret12345',
        ]);

        $this->actingAs($admin)->put("/control/users/{$victim->id}", [
            'username' => $victim->username, 'full_name' => 'Promo', 'email' => null,
            'role' => 0, 'active' => true, 'on_service' => false, 'specialty_id' => null,
            'can_assign' => false, 'can_add' => false, 'can_manage' => false, 'can_modify' => false,
        ])->assertRedirect('/stepup');

        $this->assertSame(User::ROLE_CONSULTANT, (int) $victim->fresh()->role, 'escalation was blocked pending step-up');
    }

    public function test_audit_row_contains_step_up_flag(): void
    {
        $a = $this->admission();

        $this->actingAs($this->admin())
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/admissions/{$a->id}");

        $audit = AuditLog::where('action', 'admission.delete')->where('entity_id', (string) $a->id)->first();
        $this->assertNotNull($audit);
        $this->assertTrue(($audit->details['step_up'] ?? false) === true, 'audit details record the step-up');
    }
}
