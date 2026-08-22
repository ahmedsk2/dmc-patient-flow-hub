<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * W4 — Task 26. Today's follow-up completeness.
 *
 * Denominator Y = consults in scope with status = 'active' (the ONLY status that asserts a daily
 * follow-up obligation — 'ongoing' is explicitly "on the books, no daily commitment", design §4.4).
 * Numerator X = those with a consultation_followups row whose followup_date = CURDATE().
 * The unique([consultation_id, followup_date]) constraint from W2 makes X exact by construction.
 *
 * Follow-ups are inserted with DB::table() so the append-only created_at can be set explicitly
 * regardless of how the ConsultationFollowup model configures timestamps.
 */
class ConsultationDashboardFollowupTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cdf_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'CDF User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function consult(array $attrs = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'CDF Patient',
            'consultation_date' => now()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $attrs));
    }

    private function followup(Consultation $c, string $date, ?int $authorId = null): void
    {
        DB::table('consultation_followups')->insert([
            'consultation_id' => $c->id,
            'followup_date' => $date,
            'note' => 'seen on rounds',
            'author_id' => $authorId,
            'created_at' => now(),
        ]);
    }

    public function test_today_completeness_counts_only_active_consults_ticked_today(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $base = ['owning_specialty_id' => $cardio->id, 'requested_at' => now()];

        $seen1 = $this->consult([...$base, 'status' => Consultation::STATUS_ACTIVE]);
        $seen2 = $this->consult([...$base, 'status' => Consultation::STATUS_ACTIVE]);
        $notSeen = $this->consult([...$base, 'status' => Consultation::STATUS_ACTIVE]);
        $staleTick = $this->consult([...$base, 'status' => Consultation::STATUS_ACTIVE]);
        // 'ongoing' carries NO daily obligation, so it is in neither X nor Y
        $ongoing = $this->consult([...$base, 'status' => Consultation::STATUS_ONGOING]);
        // 'new' has not been picked up yet — also outside the daily worklist
        $this->consult([...$base, 'status' => Consultation::STATUS_NEW]);

        $this->followup($seen1, now()->toDateString(), $doc->id);
        $this->followup($seen2, now()->toDateString(), $doc->id);
        $this->followup($staleTick, now()->subDay()->toDateString(), $doc->id);   // yesterday ≠ today
        $this->followup($ongoing, now()->toDateString(), $doc->id);               // not in the denominator

        $this->assertNotNull($notSeen->id);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('today.due', 4)
                ->where('today.seen', 2));
    }

    public function test_today_completeness_respects_the_specialty_scope(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $mine = $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE]);
        $theirs = $this->consult(['owning_specialty_id' => $nephro->id, 'status' => Consultation::STATUS_ACTIVE]);
        $this->followup($mine, now()->toDateString());
        $this->followup($theirs, now()->toDateString());

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('today.due', 1)
                ->where('today.seen', 1));
    }

    public function test_soft_deleted_active_consults_drop_out_of_the_denominator(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $live = $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE]);
        $gone = $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE]);
        $this->followup($live, now()->toDateString());
        $this->followup($gone, now()->toDateString());
        $gone->delete();

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('today.due', 1)
                ->where('today.seen', 1));
    }

    public function test_an_empty_worklist_reports_zero_of_zero_not_a_division(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('today.due', 0)
                ->where('today.seen', 0));
    }
}
