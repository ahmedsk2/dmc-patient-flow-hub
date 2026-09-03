<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Models\ConsultationReason;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * W3 — the service handover view (GET /consultations/handover).
 *
 * The sheet a subspecialty team reads at shift change: its ACTIVE + ONGOING consults, each with
 * its LATEST follow-up note. Scoping is NOT re-implemented in the controller — it delegates to
 * Consultation::scopeVisibleTo() (W1), and these tests exist partly to prove that delegation
 * (a Cardiology user must not see Nephrology here any more than on /consultations).
 */
class ConsultationHandoverTest extends TestCase
{
    use RefreshDatabase;

    /** Users need mfa_secret + mfa_enrolled_at + email_verified_at or they cannot authenticate. */
    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'hv_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'HV User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(), 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function consultation(Specialty $s, string $status, array $extra = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'HV Patient', 'age' => 61, 'bed' => 'W-12',
            'current_location' => 'Ward', 'consultation_from' => 'ER',
            'consultation_date' => now()->subDays(4)->toDateString(),
            'requested_at' => now()->subDays(4),
            'indication' => [], 'other_indication' => null,
            'to_service' => $s->name, 'owning_specialty_id' => $s->id, 'status' => $status,
        ], $extra));
    }

    private function rowsOf(AssertableInertia $page): array
    {
        return collect($page->toArray()['props']['groups'])
            ->flatMap(fn ($g) => $g['consultations'])->all();
    }

    // ---- scoping (delegated to scopeVisibleTo) --------------------------------------------------

    public function test_handover_lists_only_active_and_ongoing_consults_of_the_viewers_specialty(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $active = $this->consultation($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => 'Cardio Active']);
        $ongoing = $this->consultation($cardio, Consultation::STATUS_ONGOING, ['patient_name' => 'Cardio Ongoing']);
        $this->consultation($cardio, Consultation::STATUS_NEW, ['patient_name' => 'Cardio New']);
        $this->consultation($cardio, Consultation::STATUS_SIGNED_OFF, ['patient_name' => 'Cardio Signed']);
        $this->consultation($nephro, Consultation::STATUS_ACTIVE, ['patient_name' => 'Nephro Active']);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($active, $ongoing) {
                // `false` = assert the component NAME without asserting the .vue file exists on
                // disk: the page component is built in the next task, and config/inertia.php keeps
                // testing.ensure_pages_exist ON deliberately (it catches misnamed components on
                // case-sensitive CI). Flip this back to the default once Handover.vue lands.
                $page->component('Consultations/Handover', false);
                $rows = $this->rowsOf($page);
                $this->assertEqualsCanonicalizing([$active->id, $ongoing->id], array_column($rows, 'id'));
                $names = array_column($rows, 'name');
                $this->assertNotContains('Cardio New', $names, 'a not-yet-seen consult is not handover material');
                $this->assertNotContains('Cardio Signed', $names, 'a signed-off consult is off the books');
                $this->assertNotContains('Nephro Active', $names, 'specialty scoping must hold on the handover sheet');
            });
    }

    public function test_a_consult_assigned_to_the_viewer_appears_even_when_another_service_owns_it(): void
    {
        // Pins that scoping really is DELEGATED to scopeVisibleTo and not re-derived from
        // owning_specialty_id alone. An implementation that only matched the viewer's specialty
        // would still pass every other test here while DROPPING this row — and a dropped row on a
        // handover sheet is a patient nobody hands over.
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $own = $this->consultation($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => 'Own Book']);
        $assigned = $this->consultation($nephro, Consultation::STATUS_ONGOING, [
            'patient_name' => 'Assigned To Me', 'consultant_id' => $viewer->id,
        ]);
        $booked = $this->consultation($nephro, Consultation::STATUS_ACTIVE, [
            'patient_name' => 'Booked By Me', 'entered_by' => $viewer->id,
        ]);
        $this->consultation($nephro, Consultation::STATUS_ACTIVE, ['patient_name' => 'Nobody Of Mine']);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($own, $assigned, $booked) {
                $rows = $this->rowsOf($page);
                $this->assertEqualsCanonicalizing(
                    [$own->id, $assigned->id, $booked->id],
                    array_column($rows, 'id'),
                    'the consultant_id and entered_by arms of scopeVisibleTo must hold here too'
                );
                $this->assertNotContains('Nobody Of Mine', array_column($rows, 'name'));
            });
    }

    public function test_a_row_carrying_a_signoff_date_is_kept_off_the_sheet_even_if_its_status_drifted(): void
    {
        // Column drift is the standing hazard named on the model: `status = 'signed_off'` and
        // `signoff_date IS NOT NULL` are kept in lockstep by app code, but legacy:import writes
        // signoff_date alone. The handover sheet is the one surface where that drift would be
        // DISPLAYED — a closed consult presented as live shift work — so it is belt-and-braces.
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $live = $this->consultation($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => 'Still Open']);
        $this->consultation($cardio, Consultation::STATUS_ONGOING, [
            'patient_name' => 'Closed But Drifted', 'signoff_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($live) {
                $rows = $this->rowsOf($page);
                $this->assertSame([$live->id], array_column($rows, 'id'));
                $this->assertNotContains('Closed But Drifted', array_column($rows, 'name'));
            });
    }

    public function test_admin_sees_every_service_grouped_including_unassigned(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $this->consultation($cardio, Consultation::STATUS_ACTIVE);
        $this->consultation($nephro, Consultation::STATUS_ONGOING);
        Consultation::create([
            'mrn' => '90000099', 'patient_name' => 'Orphan Pt', 'indication' => [],
            'consultation_date' => now()->subDay()->toDateString(), 'to_service' => 'Some Outside Clinic',
            'owning_specialty_id' => null, 'status' => Consultation::STATUS_ONGOING,
        ]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $groups = $page->toArray()['props']['groups'];
                $this->assertEqualsCanonicalizing(
                    ['Cardiology', 'Nephrology', 'Unassigned'],
                    array_column($groups, 'name')
                );
            });
    }

    public function test_observer_is_refused_the_handover_sheet(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $this->consultation($cardio, Consultation::STATUS_ACTIVE);

        $this->actingAs($this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id]))
            ->get('/consultations/handover')->assertForbidden();
    }

    // ---- latest follow-up ----------------------------------------------------------------------

    public function test_each_consult_carries_only_its_latest_followup_note(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Cardio']);
        $c = $this->consultation($cardio, Consultation::STATUS_ACTIVE);

        ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => now()->subDays(2)->toDateString(),
            'note' => 'Older note that must not surface', 'author_id' => $viewer->id]);
        ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => now()->toDateString(),
            'note' => 'Rate controlled, continue beta blocker', 'author_id' => $viewer->id]);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $row = $this->rowsOf($page)[0];
                $this->assertSame('Rate controlled, continue beta blocker', $row['last_followup']['note']);
                $this->assertSame(now()->toDateString(), $row['last_followup']['date']);
                $this->assertSame('Dr Cardio', $row['last_followup']['author']);
                $this->assertTrue($row['last_followup']['is_today']);
            });
    }

    public function test_a_consult_with_no_followup_reports_null_rather_than_being_dropped(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $this->consultation($cardio, Consultation::STATUS_ONGOING, ['patient_name' => 'Never Ticked']);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $row = $this->rowsOf($page)[0];
                $this->assertSame('Never Ticked', $row['name']);
                $this->assertNull($row['last_followup']);
            });
    }

    public function test_latest_followup_loads_in_one_query_not_one_per_row(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        foreach (range(1, 6) as $i) {
            $c = $this->consultation($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => "Pt {$i}"]);
            ConsultationFollowup::create(['consultation_id' => $c->id,
                'followup_date' => now()->subDay()->toDateString(), 'note' => "note {$i}", 'author_id' => $viewer->id]);
            ConsultationFollowup::create(['consultation_id' => $c->id,
                'followup_date' => now()->toDateString(), 'note' => "latest {$i}", 'author_id' => $viewer->id]);
        }

        $followupQueries = 0;
        DB::listen(function ($q) use (&$followupQueries) {
            if (str_contains($q->sql, 'consultation_followups')) {
                $followupQueries++;
            }
        });

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $notes = array_map(fn ($r) => $r['last_followup']['note'], $this->rowsOf($page));
                sort($notes);
                $this->assertSame(['latest 1', 'latest 2', 'latest 3', 'latest 4', 'latest 5', 'latest 6'], $notes);
            });

        $this->assertSame(1, $followupQueries,
            'the latest follow-up must load in ONE grouped query — an N+1 across the handover list is the defect this pins');
    }

    // ---- presentation payload -------------------------------------------------------------------

    public function test_row_carries_ageing_entered_by_and_indication_names(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $reason = ConsultationReason::create(['name' => 'Chest pain']);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['full_name' => 'Dr Coordinator']);
        $consultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Cardio']);
        $this->consultation($cardio, Consultation::STATUS_ACTIVE, [
            'requested_at' => now()->subDays(6), 'consultation_date' => now()->subDays(6)->toDateString(),
            'indication' => [$reason->id], 'other_indication' => 'second opinion',
            'consultant_id' => $consultant->id, 'entered_by' => $coordinator->id,
        ]);

        $this->actingAs($consultant)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $row = $this->rowsOf($page)[0];
                $this->assertSame(6, $row['open_days']);
                $this->assertSame('Dr Cardio', $row['consultant']);
                $this->assertSame('Dr Coordinator', $row['entered_by'], 'entered_by is displayed distinctly from the owner');
                $this->assertSame(['Chest pain'], $row['reasons']);
                $this->assertSame('second opinion', $row['other']);
            });
    }

    public function test_group_counts_report_todays_followup_completeness(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $seen = $this->consultation($cardio, Consultation::STATUS_ACTIVE);
        $this->consultation($cardio, Consultation::STATUS_ACTIVE);
        $this->consultation($cardio, Consultation::STATUS_ONGOING);
        ConsultationFollowup::create(['consultation_id' => $seen->id,
            'followup_date' => now()->toDateString(), 'note' => 'seen', 'author_id' => $viewer->id]);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $counts = $page->toArray()['props']['groups'][0]['counts'];
                $this->assertSame(3, $counts['total']);
                $this->assertSame(2, $counts['active']);
                $this->assertSame(1, $counts['ongoing']);
                $this->assertSame(1, $counts['seen_today']);
            });
    }
}
