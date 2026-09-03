<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task #232 — audit:prune deletes audit_log rows ONLY when they are both (a) already shipped
 * off-box (id <= settings.audit_shipped_through_id, the #230 high-water mark) and (b) older than
 * the configured retention window (settings.audit_retention_years; 0 refuses to run at all).
 * Defaults to a dry run that deletes nothing; only --confirm actually deletes, via the query
 * builder (DB::table) since AuditLog blocks ORM update/delete to keep audit_log append-only.
 * NEVER scheduled — see routes/console.php.
 */
class AuditPruneTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::create([
            'username' => 'aprn_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Prune Actor', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ]);
    }

    private function seedRow(string $action = 'test.row'): AuditLog
    {
        $this->actingAs($this->actor());
        Audit::log($action, 'admission', '1');

        return AuditLog::orderByDesc('id')->first();
    }

    // The model blocks ORM update() (append-only guard) — created_at must be backdated via the
    // raw query builder, exactly as production code would have to.
    private function backdate(AuditLog $row, \DateTimeInterface $when): void
    {
        DB::table('audit_log')->where('id', $row->id)->update(['created_at' => $when]);
    }

    public function test_refuses_when_nothing_has_shipped_yet(): void
    {
        // audit_shipped_through_id defaults to 0 — never touched in this test.
        $row = $this->seedRow();
        $this->backdate($row, now()->subYears(10));

        $this->artisan('audit:prune')->assertExitCode(1);
        $this->artisan('audit:prune', ['--confirm' => true])->assertExitCode(1);

        $this->assertDatabaseHas('audit_log', ['id' => $row->id]);
    }

    public function test_refuses_when_retention_is_zero(): void
    {
        $row = $this->seedRow();
        $this->backdate($row, now()->subYears(10));
        Setting::current()->update(['audit_shipped_through_id' => $row->id, 'audit_retention_years' => 0]);

        $this->artisan('audit:prune', ['--confirm' => true])->assertExitCode(1);

        $this->assertDatabaseHas('audit_log', ['id' => $row->id]);
    }

    public function test_dry_run_reports_count_and_deletes_nothing(): void
    {
        $row = $this->seedRow();
        $this->backdate($row, now()->subYears(10));
        Setting::current()->update(['audit_shipped_through_id' => $row->id, 'audit_retention_years' => 6]);

        $this->artisan('audit:prune')
            ->expectsOutputToContain('1 row(s) eligible')
            ->assertExitCode(0);

        $this->assertDatabaseHas('audit_log', ['id' => $row->id]);
        $this->assertSame(0, AuditLog::where('action', 'audit.pruned')->count());
    }

    public function test_confirm_deletes_only_rows_that_are_both_shipped_and_old(): void
    {
        // Creation order matters: "shipped" is a prefix of ids (id <= mark), so to get one shipped
        // row that's too recent AND one old row that's unshipped, the recent-but-shipped row must
        // be created BEFORE the old-but-unshipped one.
        $eligible = $this->seedRow('test.eligible');           // old + shipped -> DELETE
        $this->backdate($eligible, now()->subYears(10));

        $shippedButRecent = $this->seedRow('test.recent');     // shipped but too recent -> KEEP
        $this->backdate($shippedButRecent, now()->subYear());

        $oldButUnshipped = $this->seedRow('test.unshipped');   // old but not yet shipped -> KEEP
        $this->backdate($oldButUnshipped, now()->subYears(10));

        Setting::current()->update([
            'audit_shipped_through_id' => $shippedButRecent->id, // covers eligible + shippedButRecent only
            'audit_retention_years' => 6,
        ]);

        $this->artisan('audit:prune', ['--confirm' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('audit_log', ['id' => $eligible->id]);
        $this->assertDatabaseHas('audit_log', ['id' => $shippedButRecent->id]);
        $this->assertDatabaseHas('audit_log', ['id' => $oldButUnshipped->id]);

        $pruned = AuditLog::where('action', 'audit.pruned')->first();
        $this->assertNotNull($pruned);
        $this->assertSame(1, (int) $pruned->details['count']);
        $this->assertSame((int) $shippedButRecent->id, (int) $pruned->details['through_id']);
    }

    public function test_confirm_with_nothing_eligible_deletes_nothing_and_does_not_log(): void
    {
        $row = $this->seedRow();
        $this->backdate($row, now()->subYear()); // shipped but not old enough
        Setting::current()->update(['audit_shipped_through_id' => $row->id, 'audit_retention_years' => 6]);

        $before = AuditLog::count();

        $this->artisan('audit:prune', ['--confirm' => true])
            ->expectsOutputToContain('nothing eligible')
            ->assertExitCode(0);

        $this->assertSame($before, AuditLog::count());
        $this->assertSame(0, AuditLog::where('action', 'audit.pruned')->count());
    }
}
