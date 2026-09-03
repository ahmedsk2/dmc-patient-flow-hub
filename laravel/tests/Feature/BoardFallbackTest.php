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
 * Wave 2, Item 1: discharged/unassigned fall-through on the board zero-state. When a board search
 * matches no active+assigned patient, the controller exposes a `fallback` prop with two COUNTs so
 * the empty board can say "Found N discharged / M awaiting assignment". The discharged count keeps
 * the D1 consultant scope (own unit only); the unassigned queue count is global.
 */
class BoardFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'bf_'.$role.'_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'BF User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function patient(string $name, ?string $mrn = null): Patient
    {
        return Patient::create(['mrn' => $mrn ?? (string) random_int(10000000, 99999999), 'name' => $name]);
    }

    private function admission(Patient $p, array $extra = []): Admission
    {
        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(5)->toDateString(), 'current_location' => 'Ward',
        ], $extra));
    }

    public function test_fallback_counts_discharged_and_unassigned_when_board_search_is_empty(): void
    {
        $consultant = $this->user(User::ROLE_CONSULTANT);

        // 2 discharged "Ali" under THIS consultant + 1 unassigned "Ali" in the queue — none active+assigned
        $this->admission($this->patient('Ali Hassan'), ['consultant_id' => $consultant->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);
        $this->admission($this->patient('Ali Saleh'), ['consultant_id' => $consultant->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);
        $this->admission($this->patient('Ali Unassigned'), ['consultant_id' => null]);

        $this->actingAs($consultant)->post('/patients', ['search' => 'Ali'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups', [])
                ->where('fallback.discharged', 2)
                ->where('fallback.unassigned', 1)
                ->where('fallback.search', 'Ali'));
    }

    public function test_fallback_is_null_when_the_board_has_active_results(): void
    {
        $consultant = $this->user(User::ROLE_CONSULTANT);
        $this->admission($this->patient('Active Match'), ['consultant_id' => $consultant->id]);

        $this->actingAs($consultant)->post('/patients', ['search' => 'Active'])
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fallback', null));
    }

    public function test_fallback_is_null_when_no_search_was_entered(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->admission($this->patient('Anyone'), ['discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);

        $this->actingAs($admin)->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fallback', null));
    }

    public function test_d1_scope_hides_another_consultants_discharged_match_from_a_consultant(): void
    {
        $consultantA = $this->user(User::ROLE_CONSULTANT);
        $consultantB = $this->user(User::ROLE_CONSULTANT);

        // a discharged "Zahra" belongs ONLY to consultant B
        $this->admission($this->patient('Zahra Noor'), ['consultant_id' => $consultantB->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);

        // consultant A searches "Zahra" — D1 scope means they see 0 discharged (it's B's patient)
        $this->actingAs($consultantA)->post('/patients', ['search' => 'Zahra'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups', [])
                ->where('fallback.discharged', 0)
                ->where('fallback.unassigned', 0));
    }

    public function test_admin_sees_all_discharged_in_the_fallback_count(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $someConsultant = $this->user(User::ROLE_CONSULTANT);

        $this->admission($this->patient('Yusuf One'), ['consultant_id' => $someConsultant->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);
        $this->admission($this->patient('Yusuf Two'), ['consultant_id' => null, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);

        $this->actingAs($admin)->post('/patients', ['search' => 'Yusuf'])
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fallback.discharged', 2));
    }
}
