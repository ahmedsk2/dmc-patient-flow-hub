<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Handover;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoverCheckpointsTest extends TestCase
{
    use RefreshDatabase;

    /** An admin (canManageAdmission() is true regardless of ownership) + an active admission. */
    private function adminAndAdmission(): array
    {
        $admin = User::create([
            'username' => 'ho_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'HO Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'HO Patient']);
        $admission = Admission::create([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);

        return [$admin, $admission];
    }

    public function test_handover_checkpoints_and_notification_resolved_at_round_trip(): void
    {
        $u = User::create([
            'username' => 'ho_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'HO User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'HO Patient']);
        $admission = Admission::create([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);

        $h = Handover::create(['admission_id' => $admission->id, 'body' => 'x', 'updated_by' => $u->id,
            'checkpoints' => ['vte_completed' => true, 'code_status' => 'dnr']]);
        $this->assertSame(true, Handover::find($h->id)->checkpoints['vte_completed']);
        $this->assertSame('dnr', Handover::find($h->id)->checkpoints['code_status']);

        $n = Notification::create(['user_id' => $u->id, 'type' => 'handover.incomplete', 'created_at' => now()]);
        $this->assertNull($n->resolved_at);
        $n->update(['resolved_at' => now()]);
        $this->assertNotNull(Notification::find($n->id)->resolved_at);
    }

    public function test_save_persists_checkpoints_on_handover_and_revision(): void
    {
        [$admin, $admission] = $this->adminAndAdmission();
        $this->actingAs($admin)->postJson("/admissions/{$admission->id}/handover", [
            'body' => 'today note',
            'checkpoints' => ['vte_completed' => true, 'ready_for_discharge' => false, 'high_risk' => true,
                'needs_workup' => false, 'workup_pending' => true, 'code_status' => 'full'],
        ])->assertOk();

        $h = Handover::where('admission_id', $admission->id)->first();
        $this->assertTrue($h->checkpoints['vte_completed']);
        $this->assertSame('full', $h->checkpoints['code_status']);
        $rev = \App\Models\HandoverRevision::where('admission_id', $admission->id)->latest('id')->first();
        $this->assertTrue($rev->checkpoints['high_risk']);   // snapshotted in history
    }

    public function test_save_rejects_a_bad_code_status(): void
    {
        [$admin, $admission] = $this->adminAndAdmission();
        $this->actingAs($admin)->postJson("/admissions/{$admission->id}/handover", [
            'body' => 'x', 'checkpoints' => ['code_status' => 'bogus'],
        ])->assertStatus(422);
    }
}
