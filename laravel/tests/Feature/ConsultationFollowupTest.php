<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 2b — the daily follow-up log.
 *
 * Rules pinned here:
 *  - Observers are refused BEFORE any capability flag (the global read-only guarantee).
 *  - Only someone who can SEE the consult (own specialty / coordinator / admin) may tick it.
 *  - A signed-off consult is closed: no follow-up may be appended to it.
 *  - One tick per consult per day — a second tick is a friendly 422, never a silent overwrite.
 *  - The FIRST tick on a `new` consult promotes it to `active`. That is the ONLY automatic
 *    status transition in the design.
 */
class ConsultationFollowupTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cfu_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Followup User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function specialty(string $name): Specialty
    {
        return Specialty::firstOrCreate(['name' => $name], ['is_subspecialty' => true, 'is_external' => false]);
    }

    private function consult(Specialty $spec, array $extra = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999), 'patient_name' => 'Followup Pt', 'age' => 55,
            'bed' => 'W-3', 'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => $spec->name, 'indication' => [1],
            'owning_specialty_id' => $spec->id, 'status' => Consultation::STATUS_NEW,
        ], $extra));
    }

    public function test_first_followup_on_a_new_consult_records_a_row_and_promotes_it_to_active(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id]);

        $res = $this->actingAs($doc)
            ->postJson("/consultations/{$c->id}/followup", ['note' => 'Seen, stable, continue plan'])
            ->assertOk();

        $this->assertTrue($res->json('ok'));
        $this->assertTrue($res->json('promoted'));
        $this->assertSame(Consultation::STATUS_ACTIVE, $res->json('status'));
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);

        $f = ConsultationFollowup::where('consultation_id', $c->id)->firstOrFail();
        $this->assertSame(now()->toDateString(), $f->followup_date->toDateString());
        $this->assertSame('Seen, stable, continue plan', $f->note);
        $this->assertSame($doc->id, (int) $f->author_id);
        $this->assertDatabaseHas('audit_log', ['action' => 'consultation.followup', 'entity_id' => (string) $c->id]);
        $this->assertDatabaseHas('audit_log', ['action' => 'consultation.status_change', 'entity_id' => (string) $c->id]);
    }

    public function test_a_followup_on_an_active_consult_stores_the_note_without_changing_status(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id, 'status' => Consultation::STATUS_ACTIVE]);

        $res = $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", [])->assertOk();

        $this->assertFalse($res->json('promoted'));
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
        $this->assertNull(ConsultationFollowup::where('consultation_id', $c->id)->firstOrFail()->note);
    }

    public function test_a_second_followup_the_same_day_is_rejected_and_leaves_exactly_one_row(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id, 'status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => 'first'])->assertOk();
        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => 'second'])
            ->assertStatus(422)
            ->assertJsonPath('errors.note.0', 'A follow-up is already recorded for this consultation today.');

        $this->assertSame(1, ConsultationFollowup::where('consultation_id', $c->id)->count());
        $this->assertSame('first', ConsultationFollowup::where('consultation_id', $c->id)->firstOrFail()->note);
    }

    public function test_a_followup_on_a_signed_off_consult_is_refused(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, [
            'consultant_id' => $doc->id, 'status' => Consultation::STATUS_SIGNED_OFF,
            'signoff_date' => now()->toDateString(),
        ]);

        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => 'late note'])
            ->assertStatus(422);

        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }

    public function test_an_observer_can_never_record_a_followup(): void
    {
        $cardio = $this->specialty('Cardiology');
        // capability flags set ON PURPOSE: read-only must win over every one of them
        $obs = $this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id, 'can_manage' => 1, 'can_modify' => 1]);
        $c = $this->consult($cardio, ['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($obs)->postJson("/consultations/{$c->id}/followup", [])->assertForbidden();
        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }

    public function test_a_user_from_another_specialty_cannot_record_a_followup(): void
    {
        $cardio = $this->specialty('Cardiology');
        $nephro = $this->specialty('Nephrology');
        $outsider = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $c = $this->consult($cardio, ['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($outsider)->postJson("/consultations/{$c->id}/followup", [])->assertForbidden();
        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }

    public function test_an_admin_may_record_a_followup_on_any_specialtys_consult(): void
    {
        $cardio = $this->specialty('Cardiology');
        $admin = $this->user(User::ROLE_ADMIN);
        $c = $this->consult($cardio, ['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($admin)->postJson("/consultations/{$c->id}/followup", ['note' => 'admin tick'])->assertOk();
        $this->assertSame(1, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }

    public function test_a_note_longer_than_500_characters_is_rejected(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id, 'status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => str_repeat('x', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }
}
