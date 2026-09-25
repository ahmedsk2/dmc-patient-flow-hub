<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role walkthrough 2026-09-25, E1 + E7: the shared branded error shell
 * (resources/views/errors/dmc.blade.php) renders an inline <style> block, and since style-src
 * became nonce-only (2026-09-22, SPC-WEB-002) an unstamped one is silently dropped by the browser —
 * every 403/419/429/500/503 raised inside a web-group route rendered unstyled. Also covers the
 * previously-missing branded 405 page (GET /logout etc.).
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    private function nonAdmin(): User
    {
        return User::create([
            'username' => 'ep_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'EP Registrar', 'password' => 'secret12345', 'role' => User::ROLE_REGISTRAR,
            'active' => 1, 'can_add' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email_verified_at' => now(), 'pass_exp_date' => now()->addMonths(2),
        ]);
    }

    public function test_a_403_from_a_web_route_carries_a_styled_page_whose_nonce_matches_the_csp_header(): void
    {
        $response = $this->actingAs($this->nonAdmin())->get('/control');

        $response->assertForbidden();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $headerMatch);
        $this->assertNotEmpty($headerMatch, 'CSP header must carry a nonce');

        $html = $response->getContent();
        preg_match('/<style nonce="([^"]+)">/', $html, $styleMatch);
        $this->assertNotEmpty($styleMatch, 'the error shell\'s <style> must carry the nonce attribute');
        $this->assertSame($headerMatch[1], $styleMatch[1], 'the header nonce and the rendered style nonce must be identical');

        $response->assertSee('Access denied');
    }

    /**
     * A completely unmatched route never enters the `web` middleware group (bootstrap/app.php's own
     * comment: "Router-level 404s never enter the group at all"), so $cspNonce is never shared to
     * Blade. The view must default safely instead of throwing an undefined-variable error.
     */
    public function test_a_404_for_an_unmatched_route_still_renders(): void
    {
        $response = $this->get('/this-route-does-not-exist-anywhere');

        $response->assertNotFound();
        $response->assertSee('Page not found');
        $this->assertStringContainsString('<style nonce="">', $response->getContent());
    }

    public function test_get_logout_shows_the_branded_405_page_not_the_stock_laravel_one(): void
    {
        $response = $this->actingAs($this->nonAdmin())->get('/logout');

        $response->assertStatus(405);
        $response->assertSee('opened directly');
        $response->assertDontSee('MethodNotAllowedHttpException');
    }
}
