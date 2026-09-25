<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;
use Tests\TestCase;

/**
 * S1 (role walkthrough 2026-09-25) — a signed-out browser's Back button used to show the last
 * authenticated page (patient names, MRNs) with no server request at all, restored straight from
 * the browser's own history/bfcache. Two independent layers close this:
 *
 *  1. Inertia history encryption (config('inertia.history.encrypt'), defaulted true) — every
 *     Inertia page response is flagged `encryptHistory: true`, so the client encrypts the props it
 *     keeps in `history.state` with a key held only in sessionStorage (gone once the tab/session
 *     ends). This is what these tests exercise server-side: the flag on the page response.
 *  2. `Inertia::clearHistory()` on every sign-out path (manual logout, idle/absolute timeout,
 *     account eviction) plus unconditionally on the Login page itself — so the very next Inertia
 *     page rendered after any of those tells the client to drop whatever encrypted history entries
 *     it was holding, rotating the key. `Inertia::clearHistory()` only *stores* a session flag
 *     (`SessionKey::CLEAR_HISTORY`) that the *next* Inertia\Response to be constructed pulls and
 *     consumes — so a redirect response (logout/timeout/eviction all return one) carries nothing
 *     itself; these tests assert the flag landed in session for the page the redirect points at.
 *
 * A third layer (a `pageshow`/`event.persisted` listener in resources/js/app.js forcing a reload on
 * a bfcache restore of the whole document) is client-only and not exercised by PHPUnit.
 */
class InertiaHistoryEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ihe_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'History Enc User', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email_verified_at' => now(), 'pass_exp_date' => now()->addMonths(3),
        ], $extra));
    }

    // ---- encryptHistory: every page, authenticated or guest ------------------------------------

    public function test_authenticated_inertia_page_carries_encrypt_history_true(): void
    {
        $response = $this->actingAs($this->user())->get('/')->assertOk();

        $page = $response->viewData('page');
        $this->assertTrue($page['encryptHistory'] ?? false, 'authenticated page must set encryptHistory');
    }

    public function test_guest_login_page_also_carries_encrypt_history_true(): void
    {
        $response = $this->get('/login')->assertOk();

        $page = $response->viewData('page');
        $this->assertTrue($page['encryptHistory'] ?? false, 'guest pages default to encrypted history too');
    }

    // ---- clearHistory: rendering the Login page itself always clears -----------------------------

    public function test_rendering_the_login_page_itself_carries_clear_history_true(): void
    {
        // AuthController::show() calls Inertia::clearHistory() immediately before Inertia::render(),
        // so the flag it sets is pulled by that very same response — landing on /login by ANY route
        // (not just after a sign-out) rotates the key.
        $response = $this->get('/login')->assertOk();

        $page = $response->viewData('page');
        $this->assertTrue($page['clearHistory'] ?? false, 'the login page must always clear history itself');
    }

    // ---- clearHistory: every sign-out path sets the flag for the page it redirects to ------------

    public function test_manual_logout_sets_the_clear_history_flag_for_the_next_page(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/logout')
            ->assertRedirect(route('login'))
            ->assertSessionHas(SessionKey::CLEAR_HISTORY, true);
    }

    public function test_idle_timeout_logout_sets_the_clear_history_flag_for_the_next_page(): void
    {
        Setting::current()->update(['idle_timeout_minutes' => 30, 'abs_timeout_minutes' => 0]);
        $user = $this->user();

        $this->actingAs($user)
            ->withSession(['last_activity_at' => now()->subMinutes(31)->getTimestamp()])
            ->get('/patients')
            ->assertRedirect('/login')
            ->assertSessionHas(SessionKey::CLEAR_HISTORY, true);
    }

    public function test_absolute_timeout_logout_sets_the_clear_history_flag_for_the_next_page(): void
    {
        Setting::current()->update(['idle_timeout_minutes' => 30, 'abs_timeout_minutes' => 60]);
        $user = $this->user();

        $this->actingAs($user)
            ->withSession([
                'session_started_at' => now()->subMinutes(61)->getTimestamp(),
                'last_activity_at' => now()->subMinute()->getTimestamp(),
            ])
            ->get('/patients')
            ->assertRedirect('/login')
            ->assertSessionHas(SessionKey::CLEAR_HISTORY, true);
    }

    public function test_deactivated_user_eviction_sets_the_clear_history_flag_for_the_next_page(): void
    {
        $target = $this->user();
        $target->update(['active' => false]);

        $this->actingAs($target)->get('/patients')
            ->assertRedirect('/login')
            ->assertSessionHas(SessionKey::CLEAR_HISTORY, true);
    }

    public function test_deleted_user_eviction_sets_the_clear_history_flag_for_the_next_page(): void
    {
        $target = $this->user();
        $target->delete();   // soft delete

        $this->actingAs($target)->get('/patients')
            ->assertRedirect('/login')
            ->assertSessionHas(SessionKey::CLEAR_HISTORY, true);
    }

    // ---- existing logout/timeout behaviour must stay green (audit rows, redirects) ----------------

    public function test_logout_still_ends_the_session_and_redirects_to_login(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_idle_timeout_still_redirects_with_the_expected_flash_message(): void
    {
        Setting::current()->update(['idle_timeout_minutes' => 30, 'abs_timeout_minutes' => 0]);
        $user = $this->user();

        $this->actingAs($user)
            ->withSession(['last_activity_at' => now()->subMinutes(31)->getTimestamp()])
            ->get('/patients')
            ->assertRedirect('/login')
            ->assertSessionHas('flash.message', 'You were signed out after a period of inactivity.');
    }
}
