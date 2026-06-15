<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Wave 2, Item 10: first-login onboarding tour. POST /tour/complete stamps tour_completed_at for the
 * authenticated user (idempotent, no audit); a guest is blocked; and the shared auth.user prop
 * exposes tour_completed_at so the client can decide whether to auto-start.
 */
class TourTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_REGISTRAR, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'tour_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Tour User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    public function test_complete_sets_tour_completed_at_for_the_auth_user_and_redirects_back(): void
    {
        $u = $this->user();
        $this->assertNull($u->tour_completed_at, 'starts null (never seen)');

        $this->actingAs($u)->from('/patients')->post('/tour/complete')
            ->assertRedirect('/patients');

        $this->assertNotNull($u->fresh()->tour_completed_at, 'flag is stamped after completing');
    }

    public function test_complete_is_idempotent(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/tour/complete')->assertRedirect();
        $first = $u->fresh()->tour_completed_at;
        $this->assertNotNull($first);

        // a second POST just re-stamps now() — still set, no error
        $this->actingAs($u)->post('/tour/complete')->assertRedirect();
        $this->assertNotNull($u->fresh()->tour_completed_at);
    }

    public function test_guest_is_blocked(): void
    {
        $this->post('/tour/complete')->assertRedirect('/login');
        $this->assertDatabaseMissing('users', ['tour_completed_at' => now()->toDateTimeString()]);
    }

    public function test_auth_user_prop_exposes_tour_completed_at_null_when_unseen(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.tour_completed_at', null));
    }

    public function test_auth_user_prop_exposes_tour_completed_at_after_completion(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/tour/complete')->assertRedirect();

        $this->actingAs($u)->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->whereNot('auth.user.tour_completed_at', null));
    }
}
