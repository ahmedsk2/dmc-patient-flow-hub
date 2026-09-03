<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Consultation ledger W0 — deleting a consultation is a SOFT delete that an admin restores from
 * Recently Deleted (/trashed). The confirmation dialog said "permanently … cannot be undone" and the
 * success flash said only "Consultation deleted."; both now tell the truth. This pins the server
 * half (the dialog copy is pinned by ConsultationsIndex.w0DeleteCopy.test.js).
 */
class W0ConsultationDeleteCopyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'w0x_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'W0 Admin', 'password' => 'secret12345',
            'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    public function test_delete_flash_says_the_row_is_recoverable_and_the_row_is_only_soft_deleted(): void
    {
        $c = Consultation::create([
            'mrn' => '95000001', 'patient_name' => 'Soft Delete Pt',
            'consultation_date' => now()->toDateString(), 'indication' => [],
        ]);

        $this->actingAs($this->admin())->delete("/consultations/{$c->id}")
            ->assertRedirect()
            ->assertSessionHas('flash', fn ($f) => ($f['type'] ?? null) === 'success'
                && str_contains(strtolower($f['message'] ?? ''), 'restore')
                && ! str_contains(strtolower($f['message'] ?? ''), 'permanent'));

        // soft delete: hidden from the normal query, still present and restorable
        $this->assertNull(Consultation::find($c->id));
        $trashed = Consultation::onlyTrashed()->find($c->id);
        $this->assertNotNull($trashed, 'the row survives as a trashed record');
        $trashed->restore();
        $this->assertNotNull(Consultation::find($c->id), 'and it comes back exactly as it was');
    }
}
