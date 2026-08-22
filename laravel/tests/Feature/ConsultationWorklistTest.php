<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Wave 2b — the `worklist` prop behind "Today's follow-up".
 * It carries ONLY the `active` set the viewer can see, each row flagged with whether it has
 * already been ticked today, plus the exact seen/total pair the completeness indicator renders.
 */
class ConsultationWorklistTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cwl_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Worklist User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function specialty(string $name): Specialty
    {
        return Specialty::firstOrCreate(['name' => $name], ['is_subspecialty' => true, 'is_external' => false]);
    }

    private function consult(Specialty $spec, string $status, array $extra = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999), 'patient_name' => 'Worklist Pt', 'age' => 44,
            'bed' => 'W-1', 'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => $spec->name, 'indication' => [1],
            'owning_specialty_id' => $spec->id, 'status' => $status,
        ], $extra));
    }

    public function test_worklist_holds_only_the_active_set_with_an_exact_seen_of_total(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $ticked = $this->consult($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => 'Aaa Ticked', 'consultant_id' => $doc->id]);
        $this->consult($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => 'Bbb Pending', 'consultant_id' => $doc->id]);
        $this->consult($cardio, Consultation::STATUS_ONGOING, ['patient_name' => 'Ccc Ongoing', 'consultant_id' => $doc->id]);
        $this->consult($cardio, Consultation::STATUS_NEW, ['patient_name' => 'Ddd New', 'consultant_id' => $doc->id]);

        $this->actingAs($doc)->postJson("/consultations/{$ticked->id}/followup", ['note' => 'seen'])->assertOk();

        $this->actingAs($doc)->get('/consultations')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('worklist.total', 2)
                ->where('worklist.seen', 1)
                ->where('worklist.date', now()->toDateString())
                ->where('worklist.items.0.name', 'Aaa Ticked')
                ->where('worklist.items.0.seen_today', true)
                ->where('worklist.items.1.name', 'Bbb Pending')
                ->where('worklist.items.1.seen_today', false)
        );
    }

    public function test_worklist_never_leaks_another_specialtys_consults(): void
    {
        $cardio = $this->specialty('Cardiology');
        $nephro = $this->specialty('Nephrology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => 'Mine', 'consultant_id' => $doc->id]);
        $this->consult($nephro, Consultation::STATUS_ACTIVE, ['patient_name' => 'Theirs']);

        $this->actingAs($doc)->get('/consultations')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('worklist.total', 1)
                ->where('worklist.items.0.name', 'Mine')
        );
    }

    public function test_yesterdays_tick_does_not_count_as_seen_today(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, Consultation::STATUS_ACTIVE, ['consultant_id' => $doc->id]);
        $c->followups()->create([
            'followup_date' => now()->subDay()->toDateString(),
            'note' => 'yesterday', 'author_id' => $doc->id,
        ]);

        $this->actingAs($doc)->get('/consultations')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('worklist.total', 1)->where('worklist.seen', 0)
        );
    }
}
