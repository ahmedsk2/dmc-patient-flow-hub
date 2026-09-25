<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Country;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Role walkthrough 2026-09-25 (owner decision "B"): only consultants may hold a patient they put
 * there themselves. Assign-to-me enforces it on the queue; this covers the other door — naming a
 * consultant on the New Admission form, which anyone with Can-add can submit.
 */
class AdmitWithConsultantTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'awc_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'AWC User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function payload(array $overrides = []): array
    {
        Country::firstOrCreate(['name' => 'Saudi Arabia'], ['code' => 'SA']);
        DB::table('icd10')->insertOrIgnore(['code' => 'A00', 'name' => 'Known Dx']);

        return array_merge([
            'mrn' => (string) random_int(100000, 999999), 'name' => 'AWC Pt', 'age' => 45,
            'gender' => 'Male', 'nationality' => 'Saudi Arabia', 'bed' => 'A1',
            'admit_date' => now()->toDateString(), 'admitted_from' => 'ER', 'current_location' => 'Ward',
            'diagnoses' => ['A00'],
        ], $overrides);
    }

    public function test_an_active_consultant_can_be_named_at_admission(): void
    {
        $registrar = $this->user(User::ROLE_REGISTRAR, ['can_add' => 1]);
        $consultant = $this->user(User::ROLE_CONSULTANT);

        $this->actingAs($registrar)->post('/admissions', $this->payload(['consultant_id' => $consultant->id]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($consultant->id, (int) Admission::latest('id')->first()->consultant_id);
    }

    public function test_a_non_consultant_cannot_be_named_including_yourself(): void
    {
        $registrar = $this->user(User::ROLE_REGISTRAR, ['can_add' => 1, 'can_assign' => 1]);
        $resident = $this->user(User::ROLE_RESIDENT, ['can_add' => 1]);
        $admin = $this->user(User::ROLE_ADMIN);
        $observer = $this->user(User::ROLE_OBSERVER);

        foreach ([[$registrar, $registrar], [$resident, $resident], [$registrar, $admin], [$registrar, $observer]] as [$actor, $named]) {
            $this->actingAs($actor)->from('/admissions/create')
                ->post('/admissions', $this->payload(['consultant_id' => $named->id]))
                ->assertSessionHasErrors('consultant_id');
        }
        $this->assertSame(0, Admission::count());
    }

    public function test_an_inactive_or_deleted_consultant_cannot_be_named(): void
    {
        $registrar = $this->user(User::ROLE_REGISTRAR, ['can_add' => 1]);
        $inactive = $this->user(User::ROLE_CONSULTANT, ['active' => 0]);
        $deleted = $this->user(User::ROLE_CONSULTANT);
        $deleted->delete();

        foreach ([$inactive, $deleted] as $named) {
            $this->actingAs($registrar)->from('/admissions/create')
                ->post('/admissions', $this->payload(['consultant_id' => $named->id]))
                ->assertSessionHasErrors('consultant_id');
        }
        $this->assertSame(0, Admission::count());
    }

    public function test_leaving_the_consultant_empty_still_sends_the_patient_to_the_queue(): void
    {
        $registrar = $this->user(User::ROLE_REGISTRAR, ['can_add' => 1]);

        $this->actingAs($registrar)->post('/admissions', $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(Admission::latest('id')->first()->consultant_id);
    }
}
