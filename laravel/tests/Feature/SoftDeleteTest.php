<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 4 — Item 1: soft-deletes on Admission/Consultation/User + the cross-cutting guarantee that
 * the RAW DB::table() analytics (Dashboard/Statistics/Reports) exclude soft-deleted rows. The
 * analytics tests are the enforcement of migration 2026_06_14_010002's checklist: a soft-deleted
 * admission must NOT inflate census, a statistics figure, OR a report figure.
 */
class SoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'sd_' . $role . '_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'SD User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admission(array $admission = [], array $patient = []): Admission
    {
        $p = Patient::create(array_merge([
            'mrn' => (string) random_int(100000, 999999), 'name' => 'Pt', 'age' => 40, 'gender' => 'Male',
        ], $patient));

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->toDateString(), 'current_location' => 'Ward',
        ], $admission));
    }

    public function test_admin_soft_deletes_admission_leaves_row_in_db(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $a = $this->admission();
        $before = DB::table('admissions')->count();

        // delete is step-up gated (Item 4) — provide a fresh step-up window
        $this->actingAs($admin)->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/admissions/{$a->id}")->assertRedirect();

        $this->assertSame($before, DB::table('admissions')->count(), 'row count is unchanged (soft-delete keeps the row)');
        $this->assertNotNull(DB::table('admissions')->where('id', $a->id)->value('deleted_at'));
        $this->assertNull(Admission::find($a->id), 'global scope hides the trashed row from Eloquent');
        $this->assertNotNull(Admission::withTrashed()->find($a->id));
    }

    public function test_deleted_admission_invisible_to_board(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $consultant = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1, 'specialty_id' => 1]);
        $a = $this->admission(['consultant_id' => $consultant->id]);
        $a->delete();

        $this->actingAs($admin)->get('/patients')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groups', fn ($groups) => collect($groups)
                ->flatMap(fn ($g) => collect($g['patients'])->pluck('id'))
                ->doesntContain($a->id)));
    }

    public function test_stats_exclude_soft_deleted_admissions(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $from = now()->startOfYear()->toDateString();
        $to = now()->toDateString();

        // two ward admissions this year; soft-delete one
        $live = $this->admission(['admit_date' => now()->subDays(3)->toDateString()]);
        $gone = $this->admission(['admit_date' => now()->subDays(2)->toDateString()]);
        $gone->delete();

        // independent SQL ground truth (excluding trashed) — the displayed KPI must match
        $expected = (int) DB::table('admissions')->whereBetween('admit_date', [$from, $to])
            ->whereRaw(Admission::NON_ICU_SQL)->whereNull('deleted_at')->count();

        $this->actingAs($admin)->get("/statistics?from={$from}&to={$to}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kpis.admissions', $expected));

        // and the figure must be strictly LESS than the naive count that included the trashed row
        $naive = (int) DB::table('admissions')->whereBetween('admit_date', [$from, $to])
            ->whereRaw(Admission::NON_ICU_SQL)->count();
        $this->assertSame($expected + 1, $naive, 'the soft-deleted admission is in the table but excluded from the stat');
    }

    public function test_report_excludes_soft_deleted_admissions(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $year = (int) now()->year;

        $live = $this->admission(['admit_date' => Carbon::create($year, 3, 5)->toDateString()]);
        $gone = $this->admission(['admit_date' => Carbon::create($year, 3, 6)->toDateString()]);
        $gone->delete();

        $expectedMarch = (int) DB::table('admissions')
            ->whereBetween('admit_date', ["{$year}-03-01", "{$year}-03-31"])
            ->whereRaw(Admission::NON_ICU_SQL)->whereNull('deleted_at')->count();

        $this->actingAs($admin)->get("/reports?year={$year}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('months', fn ($months) => (int) collect($months)->firstWhere('label', 'March')['admissions'] === $expectedMarch));
    }

    public function test_dashboard_census_excludes_soft_deleted(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->admission();                       // live active
        $gone = $this->admission();               // active then soft-deleted
        $gone->delete();

        $expected = (int) DB::table('admissions')->whereNull('discharge_date')->whereNull('deleted_at')->count();

        $this->actingAs($admin)->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('kpis.census', $expected));
    }

    public function test_admin_can_restore_admission(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $consultant = $this->user(User::ROLE_CONSULTANT, ['on_service' => 1, 'specialty_id' => 1]);
        $a = $this->admission(['consultant_id' => $consultant->id]);
        $a->delete();

        $this->actingAs($admin)->post("/trashed/admissions/{$a->id}/restore")->assertRedirect();

        $this->assertNull(DB::table('admissions')->where('id', $a->id)->value('deleted_at'));
        $this->assertNotNull(Admission::find($a->id));
        $this->assertDatabaseHas('audit_log', ['action' => 'admission.restore', 'entity_id' => (string) $a->id]);
    }

    public function test_restore_blocked_when_duplicate_active_mrn(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        // patients are canonical-by-MRN — a "re-admission with the same MRN" reuses the patient row,
        // so a second active admission for the same patient is the duplicate-active condition.
        $patient = Patient::create(['mrn' => '700700', 'name' => 'Dup Pt', 'age' => 50, 'gender' => 'Male']);
        $a = Admission::create(['patient_id' => $patient->id, 'admit_date' => now()->subDay()->toDateString(), 'current_location' => 'Ward']);
        $a->delete();
        // a NEW active admission for the SAME patient now exists
        Admission::create(['patient_id' => $patient->id, 'admit_date' => now()->toDateString(), 'current_location' => 'Ward']);

        $this->actingAs($admin)->post("/trashed/admissions/{$a->id}/restore")
            ->assertSessionHas('flash.type', 'error');
        $this->assertNotNull(DB::table('admissions')->where('id', $a->id)->value('deleted_at'),
            'restore was rejected — the row stays trashed');
    }

    public function test_audit_row_written_before_delete(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $a = $this->admission();

        $this->actingAs($admin)->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/admissions/{$a->id}");

        $audit = AuditLog::where('action', 'admission.delete')->where('entity_id', (string) $a->id)->first();
        $this->assertNotNull($audit);
        $deletedAt = Carbon::parse(DB::table('admissions')->where('id', $a->id)->value('deleted_at'));
        $this->assertTrue($audit->created_at->lessThanOrEqualTo($deletedAt),
            'the audit row is created at or before the soft-delete timestamp');
    }

    public function test_soft_deleted_user_cannot_log_in(): void
    {
        $u = $this->user(User::ROLE_CONSULTANT, ['username' => 'gone_user']);
        $u->update(['password' => 'secret12345']);
        $u->delete();

        $this->post('/login', ['username' => 'gone_user', 'password' => 'secret12345'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_soft_deleted_user_excluded_from_consultant_options(): void
    {
        $live = $this->user(User::ROLE_CONSULTANT, ['active' => 1, 'full_name' => 'Live Consultant']);
        $gone = $this->user(User::ROLE_CONSULTANT, ['active' => 1, 'full_name' => 'Gone Consultant']);
        $gone->delete();

        $ids = User::consultantOptions()->pluck('id');
        $this->assertTrue($ids->contains($live->id));
        $this->assertFalse($ids->contains($gone->id));
    }

    public function test_soft_deleted_user_still_resolves_in_historical_join(): void
    {
        // a discharged admission attributed to a now-deleted consultant must still show their name
        // in the raw per-consultant report join (raw joins are not scoped — historical attribution
        // survives the soft-delete).
        $admin = $this->user(User::ROLE_ADMIN);
        $gone = $this->user(User::ROLE_CONSULTANT, ['active' => 1, 'full_name' => 'Dr Departed']);
        $year = (int) now()->year;
        $this->admission([
            'consultant_id' => $gone->id,
            'admit_date' => Carbon::create($year, 2, 1)->toDateString(),
        ]);
        $gone->delete();

        $this->actingAs($admin)->get("/reports?year={$year}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('perConsultant', fn ($pc) => collect($pc)->pluck('name')->contains('Dr Departed')));
    }

    /**
     * Adversarial guard for the soft-delete cross-cutting risk: prove the SECONDARY statistics
     * aggregates (discharges KPI, the destination doughnut bucket, and the per-consultant discharge
     * count) — not just the headline admissions KPI — exclude a soft-deleted row. A missing
     * whereNull('deleted_at') on any of those raw queries would leave a figure at 1 after the delete.
     */
    public function test_soft_delete_excluded_from_discharge_destination_and_per_consultant_stats(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $cons = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Snapshot']);
        $from = now()->startOfYear()->toDateString();
        $to = now()->toDateString();

        // exactly one discharged, non-ICU admission this year under $cons, destination Home
        $a = $this->admission([
            'consultant_id' => $cons->id,
            'admit_date' => now()->subDays(10)->toDateString(),
            'medical_discharge_date' => now()->subDays(3)->toDateString(),
            'discharge_date' => now()->subDays(2)->toDateString(),
            'transfer_type' => 'discharge from ward',
            'discharge_to' => 'Home',
            'outcome' => 'Alive',
            'current_location' => 'Ward',
        ]);

        $snap = function () use ($admin, $from, $to): array {
            $out = ['discharges' => null, 'home' => 0, 'cons' => 0];
            $this->actingAs($admin)->get("/statistics?from={$from}&to={$to}")->assertOk()
                ->assertInertia(function (AssertableInertia $p) use (&$out) {
                    $p->where('kpis.discharges', function ($v) use (&$out) { $out['discharges'] = (int) $v; return true; })
                      ->where('destinations', function ($d) use (&$out) {
                          foreach (($d['labels'] ?? []) as $i => $lbl) {
                              if ($lbl === 'Home') { $out['home'] = (int) ($d['data'][$i] ?? 0); }
                          }
                          return true;
                      })
                      ->where('perConsultant', function ($rows) use (&$out) {
                          foreach ($rows as $r) {
                              if (($r['name'] ?? null) === 'Dr Snapshot') { $out['cons'] = (int) ($r['discharges'] ?? 0); }
                          }
                          return true;
                      });
                });

            return $out;
        };

        $before = $snap();
        $this->assertSame(1, $before['discharges'], 'precondition: the live discharge is counted');
        $this->assertSame(1, $before['home'], 'precondition: the Home destination bucket counts it');
        $this->assertSame(1, $before['cons'], 'precondition: the per-consultant discharge count counts it');

        $a->delete();   // soft-delete

        $after = $snap();
        $this->assertSame(0, $after['discharges'], 'discharges KPI must drop the soft-deleted row');
        $this->assertSame(0, $after['home'], 'destination doughnut must drop the soft-deleted row');
        $this->assertSame(0, $after['cons'], 'per-consultant discharge count must drop the soft-deleted row');
    }
}
