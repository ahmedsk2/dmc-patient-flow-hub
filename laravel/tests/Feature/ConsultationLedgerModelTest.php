<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Consultation ledger W1, Task 9 — the model layer: status vocabulary, the new relations, the two
 * query scopes, and the append-only ConsultationFollowup model.
 */
class ConsultationLedgerModelTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'w1m_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W1 Model User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    public function test_status_vocabulary(): void
    {
        $this->assertSame('new', Consultation::STATUS_NEW);
        $this->assertSame('active', Consultation::STATUS_ACTIVE);
        $this->assertSame('ongoing', Consultation::STATUS_ONGOING);
        $this->assertSame('signed_off', Consultation::STATUS_SIGNED_OFF);
    }

    public function test_the_existing_soft_delete_and_cache_busting_behaviour_survives(): void
    {
        $this->assertContains(SoftDeletes::class, class_uses_recursive(Consultation::class));

        $c = Consultation::create([
            'mrn' => '76000001', 'patient_name' => 'Soft Pt', 'consultation_date' => '2024-06-01', 'indication' => [],
        ]);
        $c->delete();

        $this->assertNull(Consultation::find($c->id));
        $this->assertNotNull(Consultation::withTrashed()->find($c->id));
    }

    public function test_relations_resolve(): void
    {
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $signer = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Signer']);
        $p = Patient::create(['mrn' => '76000002', 'name' => 'Rel Pt']);
        $a = Admission::create(['patient_id' => $p->id, 'admit_date' => '2026-08-01',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);

        $c = Consultation::create([
            'mrn' => '76000002', 'patient_name' => 'Rel Pt', 'consultation_date' => '2026-08-02',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $spec->id, 'indication' => [],
            'signed_off_by' => $signer->id, 'admission_id' => $a->id,
        ]);
        ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => '2026-08-03',
            'note' => 'seen on round', 'author_id' => $signer->id]);

        $c = $c->fresh();
        $this->assertSame('Cardiology', $c->owningSpecialty->name);
        $this->assertSame($signer->id, $c->signedOffBy->id);
        $this->assertSame($a->id, $c->admission->id);
        $this->assertCount(1, $c->followups);
        $this->assertSame('seen on round', $c->followups->first()->note);
        $this->assertSame($signer->id, $c->followups->first()->author->id);
    }

    public function test_followup_rows_are_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('consultation_followups', 'updated_at'));

        $c = Consultation::create([
            'mrn' => '76000003', 'patient_name' => 'Append Pt', 'consultation_date' => '2026-08-04', 'indication' => [],
        ]);
        $f = ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => '2026-08-04']);

        $this->assertNotNull($f->created_at, 'created_at is stamped automatically');
        $this->assertNull($f->updated_at, 'the model must not manage an updated_at column');
        $this->assertSame('2026-08-04', $f->followup_date->toDateString());
    }

    public function test_open_scope_excludes_signed_off_rows(): void
    {
        foreach ([Consultation::STATUS_NEW, Consultation::STATUS_ACTIVE, Consultation::STATUS_ONGOING, Consultation::STATUS_SIGNED_OFF] as $i => $status) {
            Consultation::create([
                'mrn' => '7600001' . $i, 'patient_name' => 'Open Pt ' . $i,
                'consultation_date' => '2026-08-05', 'indication' => [], 'status' => $status,
            ]);
        }

        $this->assertSame(3, Consultation::open()->count());
        $this->assertSame(0, Consultation::open()->where('status', Consultation::STATUS_SIGNED_OFF)->count());
    }

    public function test_visible_to_scopes_by_specialty_and_opens_up_for_coordinators(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $nephro = Specialty::create(['name' => 'Nephrology', 'is_subspecialty' => true, 'is_external' => false]);

        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);
        $admin = $this->user(User::ROLE_ADMIN);
        // unrelated to every fixture row below: no specialty, not a consultant/entered_by on any of them
        $otherUser = $this->user(User::ROLE_CONSULTANT);

        $base = ['consultation_date' => '2026-08-06', 'indication' => []];
        Consultation::create([...$base, 'mrn' => '76000020', 'patient_name' => 'Cardio Pt', 'owning_specialty_id' => $cardio->id]);
        Consultation::create([...$base, 'mrn' => '76000021', 'patient_name' => 'Nephro Pt', 'owning_specialty_id' => $nephro->id]);
        Consultation::create([...$base, 'mrn' => '76000022', 'patient_name' => 'Unassigned Pt', 'owning_specialty_id' => null]);
        Consultation::create([...$base, 'mrn' => '76000023', 'patient_name' => 'Assigned To Me', 'owning_specialty_id' => $nephro->id, 'consultant_id' => $cardioUser->id]);
        // entered_by is the visibility-WIDENING term on the scope (mirrors canSeeConsultation's own
        // entered_by clause) — a different specialty's row, no consultant_id, must still surface for
        // the person who typed it, and must NOT surface for someone with no relation to it at all.
        Consultation::create([...$base, 'mrn' => '76000024', 'patient_name' => 'Entered By Me', 'owning_specialty_id' => $nephro->id, 'entered_by' => $cardioUser->id]);

        // own specialty + anything assigned to or entered by me; NOT another team's book, NOT the Unassigned bucket
        $this->assertSame(
            ['76000020', '76000023', '76000024'],
            Consultation::visibleTo($cardioUser)->orderBy('mrn')->pluck('mrn')->all()
        );
        $this->assertNotContains('76000024', Consultation::visibleTo($otherUser)->pluck('mrn')->all(), 'entered_by must not leak to an unrelated user');
        $this->assertSame(5, Consultation::visibleTo($coordinator)->count());
        $this->assertSame(5, Consultation::visibleTo($admin)->count());
    }

    public function test_visible_to_excludes_observers_even_within_their_own_specialty(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $observer = $this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id]);

        Consultation::create([
            'mrn' => '76000030', 'patient_name' => 'Observed Pt', 'consultation_date' => '2026-08-07',
            'indication' => [], 'owning_specialty_id' => $cardio->id,
        ]);

        $this->assertSame(0, Consultation::visibleTo($observer)->count(), 'the consultations workspace is closed to Observers, mirroring canSeeConsultation()');
    }
}
