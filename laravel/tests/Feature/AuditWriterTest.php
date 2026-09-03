<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Phase-0 Item 4: the centralized audit writer (App\Support\Audit::log) fills actor identity and
 * IP from the session/request so every callsite stays consistent. The rows it writes must remain
 * byte-identical to the inline AuditLog::create([...]) sites it replaces (behavior-preserving).
 */
class AuditWriterTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'username' => 'aud_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Audit Actor', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ]);
    }

    public function test_log_writes_one_row_with_session_actor_and_request_ip(): void
    {
        $u = $this->user();
        $this->actingAs($u);

        Audit::log('test.action', 'admission', '42', ['k' => 'v']);

        $this->assertSame(1, AuditLog::count());
        $log = AuditLog::first();
        $this->assertSame((int) $u->id, (int) $log->actor_id);
        $this->assertSame(Auth::id(), (int) $log->actor_id);
        $this->assertSame($u->name, $log->actor_name);
        $this->assertSame('test.action', $log->action);
        $this->assertSame('admission', $log->entity_type);
        $this->assertSame('42', $log->entity_id);
        $this->assertSame('v', $log->details['k'] ?? null);
        $this->assertNotNull($log->ip);
    }

    public function test_log_defaults_entity_id_to_null_and_details_to_empty(): void
    {
        $this->actingAs($this->user());

        Audit::log('test.bulk', 'consultant');

        $log = AuditLog::first();
        $this->assertNull($log->entity_id);
        $this->assertSame([], $log->details);
    }
}
