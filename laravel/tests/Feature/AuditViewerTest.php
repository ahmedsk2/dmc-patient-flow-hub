<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 2 — Items 1, 2, 3: the admin audit-log viewer (paginate + filters + export), the
 * per-patient activity panel on the edit endpoint, and PHI-read (break-glass) logging on
 * registry search/export.
 */
class AuditViewerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'username' => 'av_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'AV Admin', 'full_name' => 'AV Admin', 'password' => 'secret12345',
            'role' => User::ROLE_ADMIN, 'active' => 1, 'can_modify' => 1,
        ]);
    }

    private function consultant(): User
    {
        return User::create([
            'username' => 'cons_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Cons', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
        ]);
    }

    /** Insert an audit row with explicit fields (the model observer still stamps the hash chain). */
    private function logRow(string $action, ?string $entityType = null, ?string $entityId = null, ?string $at = null): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $this->admin->id, 'actor_name' => $this->admin->name,
            'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'details' => [], 'ip' => '127.0.0.1',
        ] + ($at ? ['created_at' => $at] : []));
    }

    // ---- Item 1: viewer ------------------------------------------------------------------------

    public function test_non_admin_cannot_access_audit_log(): void
    {
        $this->actingAs($this->consultant())->get('/audit')->assertForbidden();
    }

    public function test_admin_can_paginate_audit_log(): void
    {
        $this->logRow('admission.discharge', 'admission', '1');
        $this->logRow('user.update', 'user', '2');
        $this->logRow('consultation.signoff', 'consultation', '3');

        $this->actingAs($this->admin)->get('/audit')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Audit/Index')
                ->has('logs.data', 3));
    }

    public function test_filter_by_action(): void
    {
        $this->logRow('admission.discharge', 'admission', '1');
        $this->logRow('user.update', 'user', '2');

        $this->actingAs($this->admin)->get('/audit?action=user.update')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'user.update'));
    }

    public function test_filter_by_entity_type_and_id(): void
    {
        $this->logRow('admission.modify', 'admission', '42');
        $this->logRow('consultation.signoff', 'consultation', '7');

        $this->actingAs($this->admin)->get('/audit?entity_type=admission&entity_id=42')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('logs.data', 1)
                ->where('logs.data.0.entity_id', '42'));
    }

    public function test_filter_by_date_range(): void
    {
        $this->logRow('admission.create', 'admission', '1', '2024-01-10 10:00:00');
        $this->logRow('admission.create', 'admission', '2', '2024-03-05 10:00:00');

        $this->actingAs($this->admin)->get('/audit?from=2024-02-01')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('logs.data', 1)
                ->where('logs.data.0.entity_id', '2'));
    }

    public function test_phi_read_actions_grouped_under_category(): void
    {
        $this->logRow('registry.search', 'registry', null);
        $this->logRow('admission.modify', 'admission', '1');

        $this->actingAs($this->admin)->get('/audit?category=phi_read')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'registry.search')
                ->where('logs.data.0.category', 'phi_read'));
    }

    public function test_export_returns_csv(): void
    {
        $this->logRow('user.update', 'user', '2');
        $res = $this->actingAs($this->admin)->get('/audit/export');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        // header columns (fputcsv quotes cells containing spaces, e.g. "Entity Type")
        $body = $res->streamedContent();
        $this->assertStringContainsString('ID,Date/Time,Actor,Action', $body);
        $this->assertStringContainsString('Entity Type', $body);
        $this->assertStringContainsString('Details (JSON)', $body);
    }

    public function test_export_applies_same_filters(): void
    {
        $this->logRow('user.update', 'user', '2');
        $this->logRow('admission.discharge', 'admission', '1');

        $res = $this->actingAs($this->admin)->get('/audit/export?action=user.update');
        $res->assertOk();
        $body = $res->streamedContent();
        // one header row + exactly one data row
        $dataLines = array_values(array_filter(explode("\n", trim($body)), fn ($l) => $l !== ''));
        $this->assertCount(2, $dataLines);
        $this->assertStringContainsString('user.update', $body);
        $this->assertStringNotContainsString('admission.discharge', $body);
    }

    public function test_non_admin_cannot_export(): void
    {
        $this->actingAs($this->consultant())->get('/audit/export')->assertForbidden();
        $this->actingAs($this->consultant())->get('/audit/export-xlsx')->assertForbidden();
    }

    public function test_export_xlsx_returns_spreadsheet(): void
    {
        $this->logRow('user.update', 'user', '2');
        $res = $this->actingAs($this->admin)->get('/audit/export-xlsx');
        $res->assertOk();
        $this->assertStringContainsString('spreadsheet', strtolower($res->headers->get('Content-Type') ?? ''));
    }

    // ---- Item 2: per-patient activity panel ----------------------------------------------------

    public function test_edit_endpoint_returns_activity_for_admission(): void
    {
        $p = Patient::create(['mrn' => '80000001', 'name' => 'Act Pt']);
        $a = Admission::create(['patient_id' => $p->id, 'admit_date' => '2024-01-10',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);

        $this->logRow('admission.create', 'admission', (string) $a->id);
        $this->logRow('admission.bed', 'admission', (string) $a->id);
        $this->logRow('admission.bed', 'admission', '999999');   // different admission — excluded

        $this->actingAs($this->admin)
            ->getJson("/admissions/{$a->id}/edit")
            ->assertOk()
            ->assertJsonCount(2, 'activity')
            // latest first
            ->assertJsonPath('activity.0.action', 'admission.bed')
            ->assertJsonPath('activity.1.action', 'admission.create');
    }

    public function test_edit_endpoint_activity_limited_to_50(): void
    {
        $p = Patient::create(['mrn' => '80000002', 'name' => 'Busy Pt']);
        $a = Admission::create(['patient_id' => $p->id, 'admit_date' => '2024-01-10',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);
        for ($i = 0; $i < 55; $i++) {
            $this->logRow('admission.bed', 'admission', (string) $a->id);
        }

        $this->actingAs($this->admin)
            ->getJson("/admissions/{$a->id}/edit")
            ->assertOk()
            ->assertJsonCount(50, 'activity');
    }

    public function test_composite_index_used_for_activity_lookup(): void
    {
        $this->assertTrue(Schema::hasIndex('audit_log', 'audit_log_entity_type_entity_id_index'));
    }

    // ---- Item 3: PHI-read logging --------------------------------------------------------------

    public function test_registry_search_writes_audit_row(): void
    {
        $this->actingAs($this->admin)->get('/registry?mode=admissions')->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'registry.search')->count());
        $row = AuditLog::where('action', 'registry.search')->first();
        $this->assertSame('admissions', $row->details['mode']);
        $this->assertSame('registry', $row->entity_type);
    }

    public function test_registry_search_redacts_search_term(): void
    {
        $this->actingAs($this->admin)->get('/registry?search=JohnDoe')->assertOk();

        $row = AuditLog::where('action', 'registry.search')->latest('id')->first();
        $this->assertSame('7 chars', $row->details['filters']['search']);
        $this->assertStringNotContainsString('JohnDoe', json_encode($row->details));
    }

    public function test_registry_export_writes_audit_row(): void
    {
        $this->actingAs($this->admin)->get('/registry/export')->assertOk();

        $row = AuditLog::where('action', 'registry.export')->first();
        $this->assertNotNull($row);
        $this->assertIsInt($row->details['row_count']);
    }

    public function test_registry_export_xlsx_writes_audit_row(): void
    {
        $this->actingAs($this->admin)->get('/registry/export-xlsx')->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'registry.export_xlsx')->count());
    }

    public function test_single_audit_row_per_search(): void
    {
        $this->actingAs($this->admin)->get('/registry')->assertOk();
        $this->actingAs($this->admin)->get('/registry')->assertOk();
        $this->actingAs($this->admin)->get('/registry')->assertOk();

        $this->assertSame(3, AuditLog::where('action', 'registry.search')->count());
    }

    public function test_record_open_logged_only_when_setting_on(): void
    {
        $p = Patient::create(['mrn' => '80000003', 'name' => 'Open Pt']);
        $a = Admission::create(['patient_id' => $p->id, 'admit_date' => '2024-01-10',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);

        // default OFF — opening the record writes NO registry.open row
        $this->actingAs($this->admin)->getJson("/admissions/{$a->id}/edit")->assertOk();
        $this->assertSame(0, AuditLog::where('action', 'registry.open')->count());

        // turn it ON — now it logs one row per open
        Setting::current()->update(['log_record_opens' => true]);
        $this->actingAs($this->admin)->getJson("/admissions/{$a->id}/edit")->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'registry.open')->count());
    }
}
