<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Role walkthrough 2026-09-25, U11: the "New Admissions" nav badge's shared prop
 * (HandleInertiaRequests::share, `unassignedAdmissionsCount`). Kept identical to
 * AdmissionsController::index's own queue filter — same discharge_date/consultant_id NULL
 * condition, same reliance on SoftDeletes' global scope to drop trashed rows.
 */
class UnassignedAdmissionsCountPropTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ua_'.$role.'_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'UA Test User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email_verified_at' => now(), 'pass_exp_date' => now()->addMonths(2),
        ], $extra));
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'UA Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDay()->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    public function test_registrar_sees_the_right_count_of_active_unassigned_admissions(): void
    {
        $this->admission();                                 // unassigned #1
        $this->admission();                                 // unassigned #2
        $this->admission(['consultant_id' => $this->user(User::ROLE_CONSULTANT)->id]); // assigned — excluded
        $this->admission(['discharge_date' => now()->toDateString()]); // discharged — excluded

        $registrar = $this->user(User::ROLE_REGISTRAR, ['can_add' => 1]);

        $this->actingAs($registrar)->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('unassignedAdmissionsCount', 2));
    }

    public function test_observer_never_sees_a_nonzero_count_even_when_admissions_are_unassigned(): void
    {
        $this->admission();
        $this->admission();

        $observer = $this->user(User::ROLE_OBSERVER);

        $this->actingAs($observer)->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('unassignedAdmissionsCount', 0));
    }

    public function test_guest_gets_zero(): void
    {
        $this->admission();

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('unassignedAdmissionsCount', 0));
    }

    public function test_a_soft_deleted_unassigned_admission_is_excluded(): void
    {
        $this->admission();                    // one real, counted
        $trashed = $this->admission();
        $trashed->delete();                    // soft delete

        $registrar = $this->user(User::ROLE_REGISTRAR, ['can_add' => 1]);

        $this->actingAs($registrar)->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('unassignedAdmissionsCount', 1));
    }
}
