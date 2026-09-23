<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 2026-09-23 UAT (NEG-03): a discharge dated before the admission — or a completion dated before the
 * medical discharge — used to reach the database, where the CHECK constraints refused it and the user
 * got a 500. Every discharge action and the Modify form now validate the date order first (a field
 * message, nothing written), and a CHECK violation that still slips through becomes a plain message
 * rather than a 500.
 */
class DischargeDateOrderTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::create(array_merge([
            'username' => 'ddo_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Date Order', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email' => 'ddo_'.substr(md5(uniqid('', true)), 0, 8).'@example.test', 'email_verified_at' => now(),
            'pass_exp_date' => now()->toDateString(),
        ], $attrs));
    }

    private function admission(User $consultant, array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'Date Order Pt', 'age' => 50, 'gender' => 'Male']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(5)->toDateString(), 'consultant_id' => $consultant->id,
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    private function day(int $daysAgo): string
    {
        return now()->subDays($daysAgo)->toDateString();
    }

    public function test_medical_discharge_before_the_admission_is_a_field_error_not_a_500(): void
    {
        $c = $this->user();
        $a = $this->admission($c);

        foreach ([['complete' => 0, 'delay_reason' => 'System'], ['complete' => 1, 'outcome' => 'Alive', 'discharge_to' => 'Home']] as $variant) {
            $this->actingAs($c)->from('/patients')
                ->post("/admissions/{$a->id}/medical-discharge", $variant + ['medical_discharge_date' => $this->day(7)])
                ->assertRedirect('/patients')
                ->assertSessionHasErrors(['medical_discharge_date' => 'The medical discharge date cannot be before the admission date ('.$this->day(5).').']);
        }
        $a->refresh();
        $this->assertNull($a->medical_discharge_date);
        $this->assertNull($a->discharge_date);
    }

    public function test_a_medical_discharge_on_the_admission_day_is_allowed(): void
    {
        $c = $this->user();
        $a = $this->admission($c);

        $this->actingAs($c)->from('/patients')
            ->post("/admissions/{$a->id}/medical-discharge", ['medical_discharge_date' => $this->day(5), 'delay_reason' => 'System'])
            ->assertRedirect('/patients')->assertSessionHasNoErrors();
        $this->assertSame($this->day(5), $a->fresh()->medical_discharge_date->toDateString());
    }

    public function test_completing_before_the_medical_discharge_is_a_field_error_not_a_500(): void
    {
        $c = $this->user();
        $a = $this->admission($c, ['medical_discharge_date' => $this->day(2), 'outcome' => 'Alive']);

        $this->actingAs($c)->from('/patients')
            ->post("/admissions/{$a->id}/complete-discharge", ['discharge_date' => $this->day(3), 'discharge_to' => 'Home'])
            ->assertRedirect('/patients')
            ->assertSessionHasErrors(['discharge_date' => 'The discharge date cannot be before the admission or medical discharge date ('.$this->day(2).').']);
        $this->assertNull($a->fresh()->discharge_date);

        // on the medical-discharge day itself it goes through
        $this->actingAs($c)->from('/patients')
            ->post("/admissions/{$a->id}/complete-discharge", ['discharge_date' => $this->day(2), 'discharge_to' => 'Home'])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->day(2), $a->fresh()->discharge_date->toDateString());
    }

    public function test_icu_discharge_before_the_admission_is_a_field_error_not_a_500(): void
    {
        $c = $this->user();
        $a = $this->admission($c, ['current_location' => 'ICU']);

        $this->actingAs($c)->from('/patients')
            ->post("/admissions/{$a->id}/icu-discharge", ['outcome' => 'Alive', 'discharge_date' => $this->day(6), 'discharge_to' => 'Home'])
            ->assertRedirect('/patients')
            ->assertSessionHasErrors(['discharge_date' => 'The discharge date cannot be before the admission date ('.$this->day(5).').']);
        $this->assertNull($a->fresh()->discharge_date);
    }

    public function test_modify_cannot_move_the_admission_after_a_recorded_discharge(): void
    {
        $modifier = $this->user(['role' => User::ROLE_ADMIN, 'can_modify' => 1]);
        $a = $this->admission($modifier, ['medical_discharge_date' => $this->day(3), 'outcome' => 'Alive']);
        $payload = fn (string $admit) => ['mrn' => $a->patient->mrn, 'name' => 'Date Order Pt', 'age' => 50, 'gender' => 'Male',
            'admit_date' => $admit, 'current_location' => 'Ward', 'diagnoses' => []];

        $this->actingAs($modifier)->from('/patients')
            ->post("/admissions/{$a->id}/modify", $payload($this->day(1)))
            ->assertRedirect('/patients')
            ->assertSessionHasErrors(['admit_date' => 'The admission date cannot be after a discharge date already recorded on this episode ('.$this->day(3).').']);
        $this->assertSame($this->day(5), $a->fresh()->admit_date->toDateString());

        // moving it up to (and including) the medical-discharge day is still a valid correction
        $this->actingAs($modifier)->from('/patients')
            ->post("/admissions/{$a->id}/modify", $payload($this->day(3)))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->day(3), $a->fresh()->admit_date->toDateString());
    }

    public function test_a_check_constraint_violation_that_slips_through_is_a_message_not_a_500(): void
    {
        $c = $this->user();
        $a = $this->admission($c);
        // a stand-in for any future write path that forgets to validate the date order
        Route::middleware('web')->post('/__test/check-violation', function () use ($a) {
            DB::table('admissions')->where('id', $a->id)->update(['discharge_date' => now()->subDays(30)->toDateString()]);
        });

        $this->actingAs($c)->from('/patients')->post('/__test/check-violation')
            ->assertRedirect('/patients')
            ->assertSessionHas('flash.type', 'error');
        $this->actingAs($c)->postJson('/__test/check-violation')->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertNull($a->fresh()->discharge_date);
    }

    public function test_other_database_errors_are_not_masked(): void
    {
        Route::middleware('web')->post('/__test/other-db-error', fn () => DB::select('SELECT * FROM table_that_does_not_exist'));

        $this->actingAs($this->user())->post('/__test/other-db-error')->assertStatus(500);
    }
}
