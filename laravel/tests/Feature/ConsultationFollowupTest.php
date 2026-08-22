<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Wave 2b — the daily follow-up log.
 *
 * Rules pinned here:
 *  - Observers are refused BEFORE any capability flag (the global read-only guarantee).
 *  - Only the RECEIVING side may tick it: owning specialty / assigned consultant / coordinator /
 *    admin. Merely being able to see the consult is not enough — the clinician who BOOKED it keeps
 *    sight of it (User::canSeeConsultation) but never rounds on it, so they may not tick it.
 *  - A signed-off consult is closed: no follow-up may be appended to it — and "closed" is keyed on
 *    EITHER column, because legacy:import writes signoff_date without ever writing status.
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
            ->assertStatus(422)
            ->assertJsonPath('errors.note.0',
                'This consultation is signed off — no further follow-up can be recorded.');

        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }

    /**
     * The drifted-column case, and it is not hypothetical: `LegacyImport::importConsultations()`
     * writes `signoff_date` and NEVER writes `status`, so every re-imported signed-off consult lands
     * at the schema default `new`. The W1 backfill that would repair them is a one-time migration and
     * does not re-run after an import. Keying the freeze on `status` alone would therefore let a
     * CLOSED consult be ticked — and auto-promoted `new` -> `active`, dropping a signed-off row into
     * the team's daily must-be-seen worklist and poisoning the "seen X of Y" denominator.
     */
    public function test_a_followup_is_refused_when_only_the_signoff_date_says_the_consult_is_closed(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        // exactly what legacy:import produces: a sign-off date with the default `new` status
        $c = $this->consult($cardio, [
            'consultant_id' => $doc->id, 'status' => Consultation::STATUS_NEW,
            'signoff_date' => now()->toDateString(),
        ]);

        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => 'late note'])
            ->assertStatus(422)
            ->assertJsonPath('errors.note.0',
                'This consultation is signed off — no further follow-up can be recorded.');

        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
        // and above all: no silent promotion of a closed row into the daily worklist
        $this->assertSame(Consultation::STATUS_NEW, $c->fresh()->status);
    }

    /**
     * A tick is the RECEIVING team's assertion that they rounded on the patient today. The referring
     * clinician keeps the consult in sight (canSeeConsultation honours `entered_by`) but never makes
     * that round, so ticking it would both misattribute the clinical fact — `author_id` would name
     * the referrer — and inflate the owning team's completeness count with a round nobody made.
     */
    public function test_the_clinician_who_booked_the_consult_cannot_tick_another_teams_round(): void
    {
        $cardio = $this->specialty('Cardiology');
        $nephro = $this->specialty('Nephrology');
        $referrer = $this->user(User::ROLE_REGISTRAR, ['specialty_id' => $nephro->id]);
        $c = $this->consult($cardio, ['entered_by' => $referrer->id]);

        $this->actingAs($referrer)->postJson("/consultations/{$c->id}/followup", ['note' => 'seen by me'])
            ->assertForbidden();

        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
        $this->assertSame(Consultation::STATUS_NEW, $c->fresh()->status);
    }

    /**
     * The write gate is narrower than the READ gate, so the daily worklist — which asks "what must
     * my side round on today" — must be narrowed to match, or the referrer gets a row on a
     * must-be-seen list that nobody she can act as is allowed to clear: `seen X of Y` could never
     * reach Y, and a permanently unclearable row teaches clinicians to ignore the panel.
     * `Consultation::scopeRecordableBy` is the query mirror of `User::canRecordFollowup`.
     */
    public function test_the_referrers_worklist_never_offers_a_row_she_is_not_allowed_to_tick(): void
    {
        $cardio = $this->specialty('Cardiology');
        $nephro = $this->specialty('Nephrology');
        $referrer = $this->user(User::ROLE_REGISTRAR, ['specialty_id' => $nephro->id]);
        // she booked it and can still SEE it in the ledger — but it is Cardiology's round
        $c = $this->consult($cardio, ['entered_by' => $referrer->id, 'status' => Consultation::STATUS_ACTIVE]);
        // ...while her own team's active consult is exactly what the panel is for
        $mine = $this->consult($nephro, ['patient_name' => 'My Own Pt', 'status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($referrer)->get('/consultations')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('worklist.total', 1)
                ->where('worklist.seen', 0)
                ->where('worklist.items.0.id', $mine->id)
        );
        // the row is refused by the endpoint, so it must not have been offered
        $this->actingAs($referrer)->postJson("/consultations/{$c->id}/followup", [])->assertForbidden();
    }

    /**
     * The refusal must reach the clinician, not just the log. The worklist ticks rows off with
     * fetch() and renders `body.message` inline; non-`api/*` routes get no automatic
     * exception-to-JSON rendering, so an HTTP-kernel AccessDeniedHttpException would answer an HTML
     * error page, `r.json()` would yield `{}`, and the specific reason would be replaced by the
     * generic "Could not record the follow-up."
     */
    public function test_an_authorization_refusal_answers_json_so_the_reason_reaches_the_clinician(): void
    {
        $cardio = $this->specialty('Cardiology');
        $nephro = $this->specialty('Nephrology');
        $outsider = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $obs = $this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id, 'can_manage' => 1]);
        $c = $this->consult($cardio, ['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($outsider)->postJson("/consultations/{$c->id}/followup", [])
            ->assertForbidden()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'This consultation belongs to another service.');

        $this->actingAs($obs)->postJson("/consultations/{$c->id}/followup", [])
            ->assertForbidden()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'Observers have read-only access.');
    }

    /**
     * A double-click / two-devices race loses the pre-check and hits the DB unique instead. That is
     * the one path a single-threaded test cannot reach on its own, so the collision is forced from a
     * model event — proving the catch answers the same friendly 422 rather than a 500 on a live
     * clinical page, and that the transaction leaves nothing behind.
     */
    public function test_a_tick_that_loses_the_race_to_the_db_unique_answers_422_not_a_500(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id, 'status' => Consultation::STATUS_NEW]);

        // simulate the concurrent tick landing between our pre-check and our INSERT: a raw insert
        // fires no model events, so this runs exactly once and the Eloquent insert then collides
        ConsultationFollowup::creating(function () use ($c) {
            DB::table('consultation_followups')->insert([
                'consultation_id' => $c->id, 'followup_date' => now()->toDateString(),
                'note' => 'the other device', 'author_id' => null, 'created_at' => now(),
            ]);
        });

        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => 'mine'])
            ->assertStatus(422)
            ->assertJsonPath('errors.note.0', 'A follow-up is already recorded for this consultation today.');

        // the rolled-back transaction must not have promoted the consult either
        $this->assertSame(Consultation::STATUS_NEW, $c->fresh()->status);
    }

    /**
     * A note of literal "0" is falsy in PHP. Discarding it would be silent data loss in an
     * append-only log that can never be corrected afterwards.
     */
    public function test_a_note_of_literal_zero_is_stored_rather_than_silently_discarded(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id, 'status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => '0'])->assertOk();

        $this->assertSame('0', ConsultationFollowup::where('consultation_id', $c->id)->firstOrFail()->note);
    }

    /** The other side of that narrowing: the ASSIGNED consultant rounds on it, whatever their book. */
    public function test_the_assigned_consultant_may_tick_even_from_another_specialty(): void
    {
        $cardio = $this->specialty('Cardiology');
        $nephro = $this->specialty('Nephrology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id, 'status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => 'seen'])->assertOk();
        $this->assertSame(1, ConsultationFollowup::where('consultation_id', $c->id)->count());
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
