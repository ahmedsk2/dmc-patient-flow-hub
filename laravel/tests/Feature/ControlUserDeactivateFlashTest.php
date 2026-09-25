<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Role walkthrough 2026-09-25 (U2). Unticking "Active" in Control -> Users -> Edit and pressing the
 * ordinary Save button immediately ends every one of that user's live sessions and revokes their
 * trusted devices (ControlController::updateUser) — but the success flash only ever said
 * "Updated {username}.", with no hint anything more than a field save had happened. The count is
 * real (the DB::delete() row count captured as `sessions_ended`), so the flash can say it exactly.
 */
class ControlUserDeactivateFlashTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cudf_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Deactivate Flash User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function seedSession(int $userId, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'ip_address' => '10.0.0.1',
            'user_agent' => 'test', 'payload' => 'x', 'last_activity' => now()->getTimestamp(),
        ]);
    }

    private function updatePayload(User $u, bool $active): array
    {
        return [
            'username' => $u->username, 'full_name' => $u->full_name, 'email' => $u->email,
            'role' => (int) $u->role, 'active' => $active, 'on_service' => (bool) $u->on_service,
            'specialty_id' => $u->specialty_id,
            'can_assign' => (bool) $u->can_assign, 'can_add' => (bool) $u->can_add,
            'can_manage' => (bool) $u->can_manage, 'can_modify' => (bool) $u->can_modify,
            'can_coordinate_consultations' => (bool) $u->can_coordinate_consultations,
        ];
    }

    public function test_deactivating_a_user_flashes_the_real_session_count(): void
    {
        $target = $this->user();
        $this->seedSession($target->id, 'sess_flash_a');
        $this->seedSession($target->id, 'sess_flash_b');

        $response = $this->actingAs($this->admin())
            ->put("/control/users/{$target->id}", $this->updatePayload($target, false));

        $response->assertSessionHas('flash.type', 'success');
        $message = $response->getSession()->get('flash')['message'];
        $this->assertStringContainsString('signed', strtolower($message));
        $this->assertStringContainsString('2 session', $message);
        $this->assertStringNotContainsString('Updated', $message);
    }

    public function test_a_non_deactivating_update_keeps_the_plain_updated_message(): void
    {
        $target = $this->user();

        $response = $this->actingAs($this->admin())
            ->put("/control/users/{$target->id}", $this->updatePayload($target, true));

        $response->assertSessionHas('flash.type', 'success');
        $message = $response->getSession()->get('flash')['message'];
        $this->assertSame("Updated {$target->username}.", $message);
    }

    /**
     * Fix-up review finding (2026-09-25, all three lenses): resaving a user whose `active` was
     * ALREADY false — e.g. editing their name with the Active box still unticked, exactly the no-op
     * case the frontend's own confirm-dialog guard (`editing.value.active && !uForm.active`) already
     * skips — used to fire the ungated `if (! $data['active'])` branch and flash "Deactivated ...
     * signed them out (0 session(s) ended)." even though no transition happened. Assert the plain
     * message survives here.
     */
    public function test_resaving_an_already_inactive_user_does_not_claim_a_fresh_deactivation(): void
    {
        $target = $this->user(extra: ['active' => 0]);

        $response = $this->actingAs($this->admin())
            ->put("/control/users/{$target->id}", $this->updatePayload($target, false));

        $response->assertSessionHas('flash.type', 'success');
        $message = $response->getSession()->get('flash')['message'];
        $this->assertSame("Updated {$target->username}.", $message);
        $this->assertStringNotContainsString('Deactivated', $message);
    }
}
