<?php

namespace Tests\Feature;

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

    public function test_import_preview_flags_invalid_rows(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->post('/import/preview', ['rows' => "ABC,bad row\n3009999,Good One,40,M,Saudi,2024-01-01,,Alive,Ward"])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Import/Index')
                ->where('preview.valid', 1)
                ->where('preview.invalid', 1));
    }
}
