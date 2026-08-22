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
 * Wave 2b — entered_by is shipped to the client, separately from the owning consultant, so the
 * workspace can show who TYPED a record versus who OWNS it. Ownership (owning_specialty_id +
 * consultant_id) is independent of entry.
 */
class ConsultationEnteredByTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ceb_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Entered User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    public function test_the_list_ships_the_typist_and_the_owner_as_separate_fields(): void
    {
        $cardio = Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Owner']);
        $typist = $this->user(User::ROLE_REGISTRAR, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Typist']);

        Consultation::create([
            'mrn' => '72000001', 'patient_name' => 'Attribution Pt', 'age' => 47, 'bed' => 'W-5',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'owning_specialty_id' => $cardio->id, 'consultant_id' => $owner->id,
            'entered_by' => $typist->id, 'status' => Consultation::STATUS_ACTIVE,
        ]);

        // seeded row is STATUS_ACTIVE, not the default `new` tab — request the matching tab so
        // the row is actually in `consultations.data` (default GET /consultations is `?status=new`)
        $this->actingAs($owner)->get('/consultations?status=active')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('consultations.data.0.consultant', 'Dr Owner')
                ->where('consultations.data.0.entered_by', 'Dr Typist')
                ->where('consultations.data.0.entered_by_id', $typist->id)
        );
    }

    public function test_a_consultation_with_no_recorded_typist_ships_an_em_dash(): void
    {
        $cardio = Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Owner']);

        Consultation::create([
            'mrn' => '72000002', 'patient_name' => 'Imported Pt', 'age' => 80, 'bed' => 'W-6',
            'current_location' => 'Ward', 'consultation_date' => '2019-01-01',
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'owning_specialty_id' => $cardio->id, 'consultant_id' => $owner->id,
            'entered_by' => null, 'status' => Consultation::STATUS_ACTIVE,
        ]);

        // same reason as above: seeded row is STATUS_ACTIVE, so request that tab explicitly
        $this->actingAs($owner)->get('/consultations?status=active')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('consultations.data.0.entered_by', '—')
                ->where('consultations.data.0.entered_by_id', null)
        );
    }
}
