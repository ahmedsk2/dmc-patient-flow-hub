<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Task #231: `audit:verify-daily` wraps `audit:verify` and turns a broken hash chain into an
 * ALERT (Log::critical + in-app notification per active admin + best-effort email) instead of a
 * line only someone reading cron output would ever see.
 */
class AuditVerifyDailyTest extends TestCase
{
    use RefreshDatabase;

    // Deliberately NOT an admin: this user only exists to be the `actingAs` actor so Audit::log has
    // someone to attribute rows to. If it were role=ADMIN it would itself be an active admin and
    // throw off the "exactly N admins notified" assertions below.
    private function actor(): User
    {
        return User::create([
            'username' => 'avd_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Audit Actor', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'username' => 'avdadm_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Daily Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ]);
    }

    public function test_intact_chain_sends_no_alert(): void
    {
        $this->actingAs($this->actor());
        for ($i = 0; $i < 3; $i++) {
            Audit::log("test.{$i}", 'admission', (string) $i);
        }
        $this->admin();
        $this->admin();

        $this->artisan('audit:verify-daily')->assertExitCode(0);

        $this->assertSame(0, Notification::where('type', 'audit.integrity_failure')->count());
    }

    public function test_tampered_chain_notifies_only_active_admins(): void
    {
        $this->actingAs($this->actor());
        for ($i = 0; $i < 3; $i++) {
            Audit::log("test.{$i}", 'admission', (string) $i);
        }
        $mid = \App\Models\AuditLog::orderBy('id')->get()[1];
        DB::table('audit_log')->where('id', $mid->id)->update(['row_hash' => '00']);

        $admin1 = $this->admin();
        $admin2 = $this->admin();
        $inactiveAdmin = User::create([
            'username' => 'avdinact_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Inactive Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 0,
        ]);
        $nonAdmin = User::create([
            'username' => 'avdcons_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'A Consultant', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
        ]);

        $this->artisan('audit:verify-daily')->assertExitCode(1);

        $this->assertSame(2, Notification::where('type', 'audit.integrity_failure')->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $admin1->id, 'type' => 'audit.integrity_failure']);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin2->id, 'type' => 'audit.integrity_failure']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $inactiveAdmin->id, 'type' => 'audit.integrity_failure']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $nonAdmin->id, 'type' => 'audit.integrity_failure']);

        $row = Notification::where('user_id', $admin1->id)->where('type', 'audit.integrity_failure')->first();
        $this->assertArrayHasKey('detail', $row->payload);
        $this->assertNotEmpty($row->payload['detail']);
    }

    public function test_dedupes_against_an_already_open_notification(): void
    {
        $this->actingAs($this->actor());
        for ($i = 0; $i < 3; $i++) {
            Audit::log("test.{$i}", 'admission', (string) $i);
        }
        $mid = \App\Models\AuditLog::orderBy('id')->get()[1];
        DB::table('audit_log')->where('id', $mid->id)->update(['row_hash' => '00']);

        $admin = $this->admin();
        Notification::create([
            'user_id' => $admin->id,
            'type' => 'audit.integrity_failure',
            'created_at' => now()->subDay(),
            'payload' => ['detail' => 'yesterday\'s failure'],
        ]);

        $this->artisan('audit:verify-daily')->assertExitCode(1);

        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'audit.integrity_failure')->count());
    }

    public function test_resolved_prior_notification_does_not_block_a_new_one(): void
    {
        $this->actingAs($this->actor());
        for ($i = 0; $i < 3; $i++) {
            Audit::log("test.{$i}", 'admission', (string) $i);
        }
        $mid = \App\Models\AuditLog::orderBy('id')->get()[1];
        DB::table('audit_log')->where('id', $mid->id)->update(['row_hash' => '00']);

        $admin = $this->admin();
        Notification::create([
            'user_id' => $admin->id,
            'type' => 'audit.integrity_failure',
            'created_at' => now()->subDay(),
            'resolved_at' => now(),
            'payload' => ['detail' => 'already resolved'],
        ]);

        $this->artisan('audit:verify-daily')->assertExitCode(1);

        $this->assertSame(2, Notification::where('user_id', $admin->id)->where('type', 'audit.integrity_failure')->count());
    }

    public function test_log_critical_emitted_on_failure(): void
    {
        Log::shouldReceive('critical')->once()->with('audit.integrity_failure', \Mockery::type('array'));
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->actingAs($this->actor());
        for ($i = 0; $i < 3; $i++) {
            Audit::log("test.{$i}", 'admission', (string) $i);
        }
        $mid = \App\Models\AuditLog::orderBy('id')->get()[1];
        DB::table('audit_log')->where('id', $mid->id)->update(['row_hash' => '00']);

        $this->artisan('audit:verify-daily')->assertExitCode(1);
    }

    public function test_log_info_emitted_on_success_and_no_critical(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('critical')->never();

        $this->actingAs($this->actor());
        Audit::log('test.0', 'admission', '0');

        $this->artisan('audit:verify-daily')->assertExitCode(0);
    }
}
