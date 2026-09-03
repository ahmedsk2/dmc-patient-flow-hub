<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 — Item 5: tamper-evident hash chain on audit_log. Each row stamps prev_hash (the
 * predecessor's row_hash) + row_hash (sha256 of its canonical form); the model refuses ORM
 * update()/delete(); `php artisan audit:verify` walks the chain and fails on any break.
 */
class AuditHashChainTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::create([
            'username' => 'hc_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Hash Actor', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ]);
    }

    public function test_audit_row_gets_row_hash_on_create(): void
    {
        $this->actingAs($this->actor());
        Audit::log('test.one', 'admission', '1');
        $row = AuditLog::first();
        $this->assertNotNull($row->fresh()->row_hash);
        $this->assertNull($row->fresh()->prev_hash);   // first row in the chain
    }

    public function test_sequential_rows_chain_correctly(): void
    {
        $this->actingAs($this->actor());
        Audit::log('test.one', 'admission', '1');
        Audit::log('test.two', 'admission', '2');
        $rows = AuditLog::orderBy('id')->get();
        $this->assertSame($rows[0]->row_hash, $rows[1]->prev_hash);
    }

    public function test_canonical_form_is_deterministic(): void
    {
        $this->actingAs($this->actor());
        Audit::log('test.one', 'admission', '1', ['k' => 'v']);
        $row = AuditLog::first()->fresh();
        $this->assertSame($row->canonical(), $row->canonical());
        // the stored row_hash equals sha256 of the canonical form
        $this->assertSame(hash('sha256', $row->canonical()), $row->row_hash);
    }

    public function test_model_update_throws(): void
    {
        $this->actingAs($this->actor());
        Audit::log('test.one', 'admission', '1');
        $row = AuditLog::first();
        $this->expectException(\RuntimeException::class);
        $row->update(['action' => 'tampered']);
    }

    public function test_model_delete_throws(): void
    {
        $this->actingAs($this->actor());
        Audit::log('test.one', 'admission', '1');
        $row = AuditLog::first();
        $this->expectException(\RuntimeException::class);
        $row->delete();
    }

    public function test_artisan_verify_passes_on_intact_chain(): void
    {
        $this->actingAs($this->actor());
        for ($i = 0; $i < 5; $i++) {
            Audit::log("test.{$i}", 'admission', (string) $i);
        }
        $this->artisan('audit:verify')->assertExitCode(0)->expectsOutputToContain('intact');
    }

    public function test_artisan_verify_fails_on_tampered_row(): void
    {
        $this->actingAs($this->actor());
        for ($i = 0; $i < 3; $i++) {
            Audit::log("test.{$i}", 'admission', (string) $i);
        }
        $middle = AuditLog::orderBy('id')->get()[1];
        // tamper at the DB layer (bypasses the ORM immutability guard) — the chain walk must catch it
        DB::table('audit_log')->where('id', $middle->id)->update(['action' => 'tampered']);

        $this->artisan('audit:verify')->assertExitCode(1);
    }
}
