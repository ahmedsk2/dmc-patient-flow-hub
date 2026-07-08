<?php
// laravel/tests/Feature/StyleGuideTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Wave 0 — the internal /style-guide design reference. It renders only design tokens and UI
 * primitives (no patient data, no queries), but it is internal tooling, so it lives inside the
 * admin group: the smallest gate that satisfies the spec's "auth-gated" requirement.
 */
class StyleGuideTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role): User
    {
        return User::create([
            'username' => 'sg_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'SG',
            'full_name' => 'SG User',
            'password' => 'secret12345',
            'role' => $role,
            'active' => 1,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/style-guide')->assertRedirect('/login');
    }

    public function test_a_non_admin_is_forbidden(): void
    {
        $this->actingAs($this->user(User::ROLE_CONSULTANT))
            ->get('/style-guide')
            ->assertForbidden();
    }

    public function test_an_admin_sees_the_style_guide(): void
    {
        // shouldExist is now left at its default, which resolves to
        // config('inertia.testing.ensure_pages_exist') — the vendor default `true`, with no app
        // override in this repo. So this asserts BOTH that the response names the component AND that
        // resources/js/Pages/StyleGuide.vue exists on disk (Task 7 ships it). That second half is a
        // real guard: a page renamed or moved out from under a still-passing route reddens here.
        //
        // The existence check is independent of TestCase::withoutVite(), which only bypasses the
        // manifest/asset lookup at render time.
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get('/style-guide')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('StyleGuide'));
    }
}
