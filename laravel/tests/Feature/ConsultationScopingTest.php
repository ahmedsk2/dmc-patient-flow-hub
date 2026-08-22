<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Consultation ledger W1, Task 10 — server-side specialty scoping.
 *
 * The list is scoped by Consultation::scopeVisibleTo(); creating into another team's book is
 * refused by ConsultationRequest unless the user is a coordinator or an admin. entered_by stays
 * immutable and session-sourced — it can never be set from the request payload.
 */
class ConsultationScopingTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'w1s_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W1 Scope User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    /** @return array{0: Specialty, 1: Specialty} */
    private function specialties(): array
    {
        return [
            Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]),
            Specialty::create(['name' => 'Nephrology', 'is_subspecialty' => true, 'is_external' => false]),
        ];
    }

    private function seedThreeBooks(Specialty $cardio, Specialty $nephro): void
    {
        $base = ['consultation_date' => '2026-08-10', 'indication' => [], 'current_location' => 'Ward'];
        Consultation::create([...$base, 'mrn' => '75000001', 'patient_name' => 'Cardio Pt',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $cardio->id]);
        Consultation::create([...$base, 'mrn' => '75000002', 'patient_name' => 'Nephro Pt',
            'to_service' => 'Nephrology', 'owning_specialty_id' => $nephro->id]);
        Consultation::create([...$base, 'mrn' => '75000003', 'patient_name' => 'Unassigned Pt',
            'to_service' => 'Some Outside Clinic', 'owning_specialty_id' => null]);
    }

    public function test_a_team_member_sees_only_their_own_book(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $this->seedThreeBooks($cardio, $nephro);

        $this->actingAs($this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]))
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultations.total', 1)
                ->where('consultations.data.0.mrn', '75000001')
                // EVERY headline counter is scoped, not just the list. An unscoped `active` would be
                // a cross-specialty aggregate disclosure sitting on top of a list of one row.
                // W2A: `stats` now reports the four real states by name — the seeded row carries no
                // explicit status, so it defaults to `new`, not the old blanket "not signed off".
                ->where('stats.total', 1)
                ->where('stats.new', 1)
                // `mine_open` is equal scoped or not by construction (a consult assigned to you is
                // always inside visibleTo) — pinned here so the number itself cannot drift.
                ->where('stats.mine_open', 0)
                // `open` is the OTHER headline counter built straight off $scoped() (mutation-tested:
                // swapping it for an unscoped Consultation::query()->count() left the whole suite
                // green until this pin existed) — the seeded row is `new`, hence open, and the
                // cardio viewer's own book is the one row, so this must read 1, not 3.
                ->where('stats.open', 1));
    }

    public function test_a_coordinator_and_an_admin_see_every_book(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $this->seedThreeBooks($cardio, $nephro);

        $this->actingAs($this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]))
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.total', 3));

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.total', 3));
    }

    public function test_an_observer_is_still_refused_the_workspace(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $this->seedThreeBooks($cardio, $nephro);

        $this->actingAs($this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id, 'can_coordinate_consultations' => true]))
            ->get('/consultations')->assertForbidden();
    }

    // ---- create ----------------------------------------------------------------------------------

    private function payload(Specialty $target, User $receiving, array $overrides = []): array
    {
        $reason = ConsultationReason::create(['name' => 'W1 Scope Reason']);

        return array_merge([
            'mrn' => '75000100', 'patient_name' => 'New Consult Pt', 'age' => 55, 'bed' => 'W-3',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => $target->name,
            'consultant_id' => $receiving->id, 'indication' => [$reason->id],
        ], $overrides);
    }

    public function test_a_non_coordinator_cannot_create_into_another_specialty(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $nephroConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($cardioUser)->from('/consultations')
            ->post('/consultations', $this->payload($nephro, $nephroConsultant))
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('to_service');

        $this->assertSame(0, Consultation::count());
    }

    public function test_a_team_member_can_create_into_their_own_specialty_and_it_is_owned_and_stamped(): void
    {
        [$cardio] = $this->specialties();
        $cardioConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($cardioConsultant)
            ->post('/consultations', $this->payload($cardio, $cardioConsultant))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertSame($cardio->id, (int) $c->owning_specialty_id, 'the owning team is resolved at entry');
        $this->assertSame($cardioConsultant->id, (int) $c->entered_by);
        $this->assertNotNull($c->requested_at, 'rows created from cutover onward carry a REAL request time');
    }

    public function test_a_coordinator_may_create_into_any_specialty(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $nephroConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);

        $this->actingAs($coordinator)
            ->post('/consultations', $this->payload($nephro, $nephroConsultant))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertSame($nephro->id, (int) $c->owning_specialty_id);
        // ownership is owning_specialty_id + consultant_id and is INDEPENDENT of who typed it in
        $this->assertSame($nephroConsultant->id, (int) $c->consultant_id);
        $this->assertSame($coordinator->id, (int) $c->entered_by);
    }

    public function test_entered_by_can_never_be_spoofed_from_the_payload(): void
    {
        [$cardio] = $this->specialties();
        $cardioConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $someoneElse = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($cardioConsultant)
            ->post('/consultations', $this->payload($cardio, $cardioConsultant, [
                'entered_by' => $someoneElse->id,
                'owning_specialty_id' => 999,
                'status' => Consultation::STATUS_SIGNED_OFF,
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertSame($cardioConsultant->id, (int) $c->entered_by, 'entered_by is session-sourced and immutable');
        $this->assertSame($cardio->id, (int) $c->owning_specialty_id, 'the owner is resolved server-side, not accepted');
        $this->assertSame(Consultation::STATUS_NEW, $c->status, 'status is not settable at create time');
    }

    public function test_an_external_or_free_text_service_stays_creatable_and_unassigned(): void
    {
        [$cardio] = $this->specialties();
        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $payload = $this->payload($cardio, $cardioUser, ['to_service' => 'Some Outside Clinic', 'consultant_id' => null]);

        $this->actingAs($cardioUser)->post('/consultations', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(Consultation::first()->owning_specialty_id, 'external referrals have no IM owner');
    }

    /**
     * The `is_external = false` half of the resolver, which the free-text case above cannot tell
     * apart from an unmatched string. It matters against the real data: 7 of the 12 configured
     * specialties are external, so if that filter regressed, consults to those services would
     * become owned, vanish from everyone outside that team, and stop being creatable.
     */
    public function test_a_NAMED_external_specialty_is_unowned_and_bookable_by_any_team(): void
    {
        [$cardio] = $this->specialties();
        $external = Specialty::create(['name' => 'Coronary Care Outreach', 'is_subspecialty' => false, 'is_external' => true]);
        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->assertNull(
            \App\Http\Controllers\ConsultationsController::resolveOwningSpecialtyId('Coronary Care Outreach'),
            'an EXTERNAL specialty is never an IM owner, even though its name matches a row'
        );

        $this->actingAs($cardioUser)
            ->post('/consultations', $this->payload($external, $cardioUser, ['consultant_id' => null]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertSame('Coronary Care Outreach', $c->to_service);
        $this->assertNull($c->owning_specialty_id, 'a consult to an external service lands in Unassigned');
    }

    /**
     * The rollout case. On the real database EVERY active registrar and resident (121 accounts,
     * who typed 53% of the historical consults) has a NULL specialty_id, so the own-specialty rule
     * refuses them everything — `(int) null` is 0 and never equals a real specialty id. That refusal
     * is deliberate and must stay explicit, but it must also have a documented way out, or those
     * users simply cannot record a consultation. The way out is the coordinator capability, granted
     * to exactly those accounts by 2026_08_21_000500 (pinned below).
     */
    public function test_a_user_with_no_specialty_is_refused_a_team_book_until_granted_the_coordinator_flag(): void
    {
        [$cardio] = $this->specialties();
        $cardioConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $unaffiliated = $this->user(User::ROLE_RESIDENT);   // specialty_id IS NULL — the real-world default

        $this->actingAs($unaffiliated)->from('/consultations')
            ->post('/consultations', $this->payload($cardio, $cardioConsultant))
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('to_service');
        $this->assertSame(0, Consultation::count(), 'a consult must never be silently swallowed');

        $unaffiliated->update(['can_coordinate_consultations' => true]);

        $this->actingAs($unaffiliated->fresh())
            ->post('/consultations', $this->payload($cardio, $cardioConsultant))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($cardio->id, (int) Consultation::first()->owning_specialty_id);
    }

    /**
     * The cutover data step itself: every clinical account with no specialty gets the coordinator
     * capability, so nobody who could enter a consult yesterday is locked out today. Admins do not
     * need it and Observers must never hold a capability flag (read-only is enforced first, always).
     */
    public function test_the_cutover_migration_grants_the_coordinator_flag_to_unaffiliated_clinical_users(): void
    {
        [$cardio] = $this->specialties();
        $resident = $this->user(User::ROLE_RESIDENT);
        $registrar = $this->user(User::ROLE_REGISTRAR);
        $inactive = $this->user(User::ROLE_RESIDENT, ['active' => 0]);
        $affiliated = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $observer = $this->user(User::ROLE_OBSERVER);
        $admin = $this->user(User::ROLE_ADMIN);

        $migration = require base_path('database/migrations/2026_08_21_000500_grant_consultation_coordinator_to_unaffiliated_users.php');
        $migration->up();

        $this->assertTrue((bool) $resident->fresh()->can_coordinate_consultations);
        $this->assertTrue((bool) $registrar->fresh()->can_coordinate_consultations);
        // dormant on a disabled account (a flag does nothing without a session) so that
        // re-enabling someone does not silently re-create the lockout
        $this->assertTrue((bool) $inactive->fresh()->can_coordinate_consultations);

        $this->assertFalse((bool) $affiliated->fresh()->can_coordinate_consultations, 'a user with a team keeps their own book');
        $this->assertFalse((bool) $observer->fresh()->can_coordinate_consultations, 'Observers never hold a capability flag');
        $this->assertFalse((bool) $admin->fresh()->can_coordinate_consultations, 'admins already bypass scoping');

        $migration->down();
        $this->assertFalse((bool) $resident->fresh()->can_coordinate_consultations, 'reversible');
    }

    // ---- edit ------------------------------------------------------------------------------------

    /**
     * `to_service` is the routing label a clinician reads; `owning_specialty_id` is the book that
     * actually controls visibility. If an edit moved one without the other, the consult would
     * display as one team's while living in another's — visible to neither in practice.
     */
    public function test_changing_the_service_on_an_edit_moves_the_owning_book_with_it(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);
        $cardioConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $nephroConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);

        $this->actingAs($coordinator)
            ->post('/consultations', $this->payload($cardio, $cardioConsultant))
            ->assertRedirect()->assertSessionHasNoErrors();
        $c = Consultation::first();
        $this->assertSame($cardio->id, (int) $c->owning_specialty_id);

        $this->actingAs($coordinator)
            ->put("/consultations/{$c->id}", $this->payload($nephro, $nephroConsultant))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame('Nephrology', $c->to_service);
        $this->assertSame($nephro->id, (int) $c->owning_specialty_id, 'the book follows the label');
        $this->assertSame(1, Consultation::visibleTo($nephroConsultant)->count(), 'the receiving team now sees it');
        $this->assertSame(0, Consultation::visibleTo($this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]))->count(),
            'and it no longer clutters the book it left');
    }

    public function test_a_non_coordinator_cannot_re_route_a_consult_into_another_team_by_editing_it(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $nephroConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);

        $this->actingAs($cardioUser)->post('/consultations', $this->payload($cardio, $cardioUser))
            ->assertRedirect()->assertSessionHasNoErrors();
        $c = Consultation::first();

        $this->actingAs($cardioUser)->from('/consultations')
            ->put("/consultations/{$c->id}", $this->payload($nephro, $nephroConsultant))
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('to_service');

        $c->refresh();
        $this->assertSame('Cardiology', $c->to_service);
        $this->assertSame($cardio->id, (int) $c->owning_specialty_id);

        // …but an ordinary edit that leaves the service alone still saves: legacy modify carried no
        // ownership check (J1-10) and W1 does not narrow that, it only stops silent re-routing.
        $this->actingAs($cardioUser)
            ->put("/consultations/{$c->id}", $this->payload($cardio, $cardioUser, ['bed' => 'W-9']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('W-9', $c->fresh()->bed);
    }
}
