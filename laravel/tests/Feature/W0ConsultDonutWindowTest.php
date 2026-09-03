<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Consultation ledger W0 — the dashboard consultation donut told a half-truth: the slice was
 * labelled "Signed off (24h)" while the query counted `signoff_date >= yesterday`, a window that is
 * 24 to 48 hours wide depending on the hour of the day. `signoff_date` is a DATE column, so a real
 * rolling 24h window is not computable; the metric is therefore RELABELLED to what it measures
 * (today or yesterday) and the payload key renamed so nothing can keep reading the old claim.
 */
class W0ConsultDonutWindowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'w0d_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'W0 Admin', 'password' => 'secret12345',
            'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function consult(string $mrn, ?string $signoff): Consultation
    {
        return Consultation::create([
            'mrn' => $mrn, 'patient_name' => 'Donut Pt '.$mrn,
            'consultation_date' => now()->subDays(6)->toDateString(),
            'signoff_date' => $signoff, 'indication' => [],
        ]);
    }

    public function test_consult_donut_counts_sign_offs_from_today_and_yesterday_only(): void
    {
        $this->consult('94000001', now()->toDateString());               // counted
        $this->consult('94000002', now()->subDay()->toDateString());     // counted
        $this->consult('94000003', now()->subDays(2)->toDateString());   // outside the window
        $this->consult('94000004', now()->addDay()->toDateString());     // bad legacy data: future date
        $this->consult('94000005', null);                                // still open -> the "active" half

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultDonut.signedTodayOrYesterday', 2)
                ->where('consultDonut.active', 1)
                ->missing('consultDonut.signed24h'));
    }

    public function test_consult_donut_is_zero_when_nothing_was_signed_recently(): void
    {
        $this->consult('94000010', now()->subDays(30)->toDateString());
        $this->consult('94000011', null);

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultDonut.signedTodayOrYesterday', 0)
                ->where('consultDonut.active', 1));
    }
}
