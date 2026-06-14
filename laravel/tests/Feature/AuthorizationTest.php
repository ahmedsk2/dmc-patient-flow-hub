<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Security-critical authorization checks. RefreshDatabase migrates the isolated test database
 * (sqlite :memory: per phpunit.xml) — never touches the live dev/prod data.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'test_' . $role . '_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Test User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    public function test_observer_cannot_admit_a_patient(): void
    {
        $this->actingAs($this->user(User::ROLE_OBSERVER))
            ->post('/admissions', [])
            ->assertForbidden();
    }

    public function test_non_admin_cannot_open_control_panel(): void
    {
        $this->actingAs($this->user(User::ROLE_CONSULTANT))
            ->get('/control')
            ->assertForbidden();
    }

    public function test_admin_can_open_control_panel(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get('/control')
            ->assertOk();
    }

    public function test_consultant_without_assign_cannot_shuffle(): void
    {
        $this->actingAs($this->user(User::ROLE_CONSULTANT, ['can_assign' => false]))
            ->post('/admissions/shuffle')
            ->assertForbidden();
    }

    public function test_admin_dashboard_renders(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get('/')
            ->assertOk();
    }

    /**
     * #1 access policy: Statistics / Registry / Reports + all exports are ADMIN-ONLY.
     * Non-admins' only analytics is the dashboard. Drives the PHI-export tightening.
     */
    public function test_non_admin_cannot_reach_analytics_or_export(): void
    {
        $routes = ['/statistics', '/registry', '/registry/export', '/registry/export-xlsx',
            '/reports', '/reports/pdf', '/reports/monthly', '/reports/monthly/pdf'];
        $consultant = $this->user(User::ROLE_CONSULTANT);
        $observer = $this->user(User::ROLE_OBSERVER);
        foreach ($routes as $route) {
            $this->actingAs($consultant)->get($route)->assertForbidden();
            $this->actingAs($observer)->get($route)->assertForbidden();
        }
    }

    public function test_admin_can_reach_analytics(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get('/statistics')->assertOk();
        $this->actingAs($admin)->get('/registry')->assertOk();
        $this->actingAs($admin)->get('/reports')->assertOk();
    }

    public function test_non_admin_still_sees_dashboard(): void
    {
        $this->actingAs($this->user(User::ROLE_CONSULTANT))->get('/')->assertOk();
    }

    public function test_import_preview_flags_invalid_rows(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->post('/import/preview', ['rows' => "ABC,bad row\n3009999,Good One,40,M,Saudi,2024-01-01,,Alive,Ward"])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Import/Index', false) // assert the name; don't require the .vue on disk (CI has no front-end build)
                ->where('preview.valid', 1)
                ->where('preview.invalid', 1));
    }

    // ---- Phase-0 Item 6: User::canManageAdmission single source of truth ----------------------

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'CM Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDay()->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    /** Observers are read-only globally — a stray can_manage=1 must NOT grant manage rights (J1-9). */
    public function test_observer_canManageAdmission_returns_false_regardless_of_can_manage(): void
    {
        $observer = $this->user(User::ROLE_OBSERVER, ['can_manage' => true]);
        $admission = $this->admission(['consultant_id' => $observer->id]);

        $this->assertFalse($observer->canManageAdmission($admission));
    }

    /** The primary consultant of an admission may manage it without admin or can_manage. */
    public function test_primary_consultant_canManageAdmission_returns_true(): void
    {
        $consultant = $this->user(User::ROLE_CONSULTANT, ['can_manage' => false]);
        $admission = $this->admission(['consultant_id' => $consultant->id]);

        $this->assertTrue($consultant->canManageAdmission($admission));
    }

    /** A different consultant, without the can_manage flag, may NOT manage someone else's patient. */
    public function test_other_consultant_without_flag_returns_false(): void
    {
        $owner = $this->user(User::ROLE_CONSULTANT);
        $other = $this->user(User::ROLE_CONSULTANT, ['can_manage' => false]);
        $admission = $this->admission(['consultant_id' => $owner->id]);

        $this->assertFalse($other->canManageAdmission($admission));
    }

    /** Admin and the can_manage capability each grant manage rights regardless of ownership. */
    public function test_admin_and_can_manage_each_grant_canManageAdmission(): void
    {
        $owner = $this->user(User::ROLE_CONSULTANT);
        $admission = $this->admission(['consultant_id' => $owner->id]);

        $this->assertTrue($this->user(User::ROLE_ADMIN)->canManageAdmission($admission));
        $this->assertTrue($this->user(User::ROLE_CONSULTANT, ['can_manage' => true])->canManageAdmission($admission));
    }
}
