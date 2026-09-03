<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Public PDPL privacy notice — GET /privacy (LegalController::privacy → Pages/Legal/Privacy).
 * Reachable with no session (patients are not users of the hub) AND by a signed-in staff member,
 * ships both languages from resources/lang/{en,ar}/privacy.php, and the two languages must keep
 * the same shape (section ids, block types, list/table sizes) so the Arabic stays a faithful twin.
 */
class PrivacyNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_can_read_the_notice_in_both_languages(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Legal/Privacy')
                ->where('en.code', 'en')->where('en.dir', 'ltr')
                ->where('ar.code', 'ar')->where('ar.dir', 'rtl')
                ->has('en.sections')
                ->has('ar.sections'));
    }

    public function test_a_signed_in_user_is_not_bounced_away_from_the_notice(): void
    {
        $user = User::create([
            'username' => 'pn_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Privacy Reader', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(), 'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Legal/Privacy'));
    }

    public function test_the_two_languages_have_the_same_shape(): void
    {
        $en = trans('privacy', [], 'en');
        $ar = trans('privacy', [], 'ar');

        $this->assertIsArray($en);
        $this->assertIsArray($ar);
        $this->assertSame(array_keys($en), array_keys($ar));
        $this->assertSame(array_keys($en['labels']), array_keys($ar['labels']));
        $this->assertSame(array_keys($en['meta']), array_keys($ar['meta']));

        $shape = fn (array $notice) => array_map(
            fn (array $s) => [
                $s['id'],
                array_map(fn (array $b) => [
                    $b['type'],
                    match ($b['type']) {
                        'ul' => count($b['items']),
                        'table' => [count($b['head']), count($b['rows']), array_map('count', $b['rows'])],
                        default => null,
                    },
                ], $s['blocks']),
            ],
            $notice['sections'],
        );

        $this->assertSame($shape($en), $shape($ar));
        $this->assertGreaterThanOrEqual(10, count($en['sections']));
    }

    public function test_the_notice_is_visibly_marked_as_a_draft(): void
    {
        $this->assertStringStartsWith('DRAFT', trans('privacy.draft_banner', [], 'en'));
        $this->assertStringStartsWith('مسودة', trans('privacy.draft_banner', [], 'ar'));
    }
}
