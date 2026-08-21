# Per-Specialty Consultation Ledger — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the consultation section into a bookkeeping ledger owned by each Internal Medicine subspecialty team — four working states, a daily follow-up log, a recorded response, specialty scoping with a coordinator capability, and physician-visible dashboards.

**Architecture:** Extend the existing `consultations` table in place (additively, keeping all ~1,283 historical rows) rather than building a parallel model, and add one append-only child table for the daily follow-up log — copying the proven `handover_revisions` shape. Ownership becomes `owning_specialty_id` + `consultant_id` and is deliberately independent of `entered_by`, which stays immutable and session-sourced. All authorization stays server-side behind single-purpose predicates on `User`, and all scoping flows through one query scope (`Consultation::scopeVisibleTo`) so the rule can never drift between call sites.

**Tech Stack:** Laravel 13 (PHP 8.5), Inertia 2, Vue 3 `<script setup>`, Tailwind v4, MySQL 8.4, PHPUnit, Vitest.

**Source spec:** [`../specs/2026-08-21-consultation-ledger-design.md`](../specs/2026-08-21-consultation-ledger-design.md) (committed `9f46d24`).

---

## Before you start — read this

**This is a LIVE clinical system.** It holds real patient data and ~1,283 real consultation rows. Two rules
protect it, and they are the reason the wave order is fixed:

1. **Wave W0 must land before any new consultation column exists.** `php artisan legacy:import` currently
   **truncates the `consultations` table** before reading anything from the legacy database. Ship a single new
   field before W0 and the next data reload silently destroys every consultation the doctors entered.
2. **Open legacy consultations backfill to `ongoing`, never `active`.** Mapping them to `active` would invent a
   daily follow-up obligation for every one of them, so on launch day every team would open a huge false
   "must be seen today" list. This project has already been burned by exactly that failure mode with the
   handover backlog.

**Line numbers in this plan drift.** Locate code by matching the quoted snippets, not by line number.

### Environment

| What | Command |
|---|---|
| PHP binary | `C:/wamp64/bin/php/php8.5.0/php.exe` — **not on PATH**, and **always** pass `-d xdebug.mode=off` |
| Backend tests | `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=SomeTest` |
| Migrate (test DB) | `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan migrate` |
| Frontend tests | `cd laravel && npx vitest run` |

Baseline before you start: **PHPUnit 763 passing, Vitest 591 passing.** Neither number may go down.

**`public/build` is committed.** After *any* change under `resources/`, run in this exact order and commit the
rebuilt assets alongside the source change:

```bash
cd laravel && npx vite build && node scripts/check-source-allowlist.mjs --write && node scripts/check-source-allowlist.mjs && node scripts/contrast.mjs
```

Both scripts must print PASS. **No Composer is available — do not add a package.**

### Repo testing conventions

- **There are no model factories.** Build fixtures with explicit `::create()` calls. Read
  `tests/Feature/Round5J1Test.php` or `tests/Feature/GapWave4bTest.php` first and copy the idiom exactly.
- Users need `mfa_secret` + `mfa_enrolled_at` + `email_verified_at` or they cannot log in (MFA is mandatory).
  The `password` attribute has a `hashed` cast — assign the **plain** password.
- Logging in posts to `/login` with a **username or email** plus password.
- Non-api POST routes need `$this->jsonValidate(...)` to return a JSON 422 in tests.
- **Tailwind v4:** never spell utility-like tokens inside `.vue` comments or string literals — the extractor
  mints them and breaks the allowlist. Reuse existing AA-safe tokens (the `bg-tint-X` / `text-on-X` pattern).

### Invariants that must survive every task

1. `entered_by` is **immutable and session-sourced** (`Auth::id()`), never settable from the request payload.
   Ownership is `owning_specialty_id` + `consultant_id` and is **independent** of who typed the record.
2. **Observer read-only is enforced before any capability flag**, everywhere. Existing tests pin this.
3. **Coordinators** may create into any specialty, see all and modify all — but may **not** sign off, delete,
   or reverse a sign-off.
4. Every schema change is its **own reversible migration**. Nothing destructive to existing rows.
5. Every raw `consultations` analytics query excludes soft-deleted rows (`whereNull('deleted_at')`).
6. PHI never enters a URL — free-text search terms ride a POST body.
7. Existing tests keep passing or are **deliberately updated — never deleted.** ~37 backend authorization test
   methods already cover consultations.

---

## Shared naming contract

Every wave uses these exact identifiers.

**New columns**

| Table | Column | Type |
|---|---|---|
| `settings` | `consultations_source_of_truth` | boolean, default `false` |
| `users` | `can_coordinate_consultations` | boolean, default `false` |
| `consultations` | `owning_specialty_id` | FK → `specialties`, nullable, indexed |
| `consultations` | `status` | string(16), indexed, default `new` |
| `consultations` | `signed_off_by` | FK → `users`, nullable, `nullOnDelete` |
| `consultations` | `admission_id` | FK → `admissions`, nullable, `nullOnDelete`, indexed |
| `consultations` | `requested_at` | datetime, nullable, indexed |
| `consultations` | `signed_off_at` | datetime, nullable |
| `consultations` | `response_disposition` | string(32), nullable |
| `consultations` | `response_followup_needed` | boolean, nullable |
| `consultations` | `response_note` | text, nullable |

**New table `consultation_followups`:** `id`, `consultation_id` (FK, `cascadeOnDelete`), `followup_date` (date),
`note` (text, nullable), `author_id` (FK `users`, nullable, `nullOnDelete`), `created_at` (`useCurrent`,
append-only — no `updated_at`), `unique(['consultation_id','followup_date'])`.

**Status constants** on `App\Models\Consultation`: `STATUS_NEW = 'new'`, `STATUS_ACTIVE = 'active'`,
`STATUS_ONGOING = 'ongoing'`, `STATUS_SIGNED_OFF = 'signed_off'`.

**`response_disposition` values:** `advice_given` · `taking_over` · `follow_up_arranged` · `no_further_input`.

**Permission predicates** on `App\Models\User`:

| Method | Meaning |
|---|---|
| `canCoordinateConsultations(): bool` | Observer → false; `isAdmin() \|\| can_coordinate_consultations` |
| `canSeeConsultation(Consultation $c): bool` | own specialty, or coordinator, or admin |
| `canModifyConsultation(Consultation $c): bool` | own specialty, or coordinator, or admin |
| `canManageConsultation(Consultation $c): bool` | **exists already — the SIGN-OFF gate.** Observer → false; `isAdmin() \|\| can_manage \|\| consultant_id === id`. Coordinators must **not** gain sign-off through it. |

**New routes** (in the existing auth group beside the current consultation routes):

| Route | Name |
|---|---|
| `POST /consultations/{consultation}/status` | `consultations.status` |
| `POST /consultations/{consultation}/followup` | `consultations.followup` |
| `GET /consultations/handover` | `consultations.handover` |
| `GET /consultations/dashboard` | `consultations.dashboard` |

**Audit action strings:** `consultation.status_change` · `consultation.followup` · `consultation.assign`.
**Notification type:** `consultation.assigned`.

---

## File structure

The existing `ConsultationsController` is 166 lines covering six actions. This plan would roughly triple it if
everything landed there, so the new surfaces get their own single-responsibility controllers, and the metric SQL
gets its own support class rather than living in a controller.

### Created

| File | Responsibility |
|---|---|
| `database/migrations/*_add_consultations_source_of_truth_to_settings.php` | W0 — the import safety flag |
| `database/migrations/*_add_ledger_columns_to_consultations.php` | W1 — the nine new consultation columns |
| `database/migrations/*_create_consultation_followups_table.php` | W1 — the append-only daily log |
| `database/migrations/*_backfill_consultation_ledger_state.php` | W1 — status + specialty backfill (data only) |
| `database/migrations/*_add_can_coordinate_consultations_to_users.php` | W1 — the coordinator flag |
| `app/Models/ConsultationFollowup.php` | One daily follow-up entry; append-only |
| `app/Http/Requests/ConsultationSignoffRequest.php` | Sign-off response payload + its authorization gate |
| `app/Http/Requests/ConsultationStatusRequest.php` | Status-transition payload + legal-transition rules |
| `app/Http/Requests/ConsultationFollowupRequest.php` | Daily follow-up payload |
| `app/Http/Controllers/ConsultationFollowupController.php` | Recording daily follow-ups |
| `app/Http/Controllers/ConsultationHandoverController.php` | W3 — the shift-change view |
| `app/Http/Controllers/ConsultationDashboardController.php` | W4 — physician-scoped dashboard |
| `app/Support/ConsultationMetrics.php` | W4 — every metric query, one method each, no SQL in controllers |
| `resources/js/Pages/Consultations/Handover.vue` | W3 — printable handover list |
| `resources/js/Pages/Consultations/Dashboard.vue` | W4 — physician dashboard |
| `tests/Feature/ConsultationImportSafetyTest.php` | W0 — proves a reload cannot destroy consultations |
| `tests/Feature/ConsultationBackfillTest.php` | W1 — proves open rows land in `ongoing`, times stay NULL |
| `tests/Feature/ConsultationScopingTest.php` | W1 — specialty scoping + coordinator capability |
| `tests/Feature/ConsultationStateMachineTest.php` | W2 — legal/illegal transitions |
| `tests/Feature/ConsultationSignoffResponseTest.php` | W2 — response capture + coordinator refused sign-off |
| `tests/Feature/ConsultationFollowupTest.php` | W2 — one tick per day, auto-promote, signed-off refused |
| `tests/Feature/ConsultationHandoverTest.php` | W3 |
| `tests/Feature/ConsultationDashboardTest.php` | W4 — incl. NULL `requested_at` excluded from medians |

### Modified

| File | Change |
|---|---|
| `app/Console/Commands/LegacyImport.php` | W0 — honour the source-of-truth flag; never truncate consultations when set |
| `app/Http/Controllers/DashboardController.php` | W0 — honest label/window for the signed-off donut |
| `app/Models/Consultation.php` | W1 — constants, relations, `scopeVisibleTo`, `scopeOpen`; preserve SoftDeletes + `DashboardCache::bust()` |
| `app/Models/User.php` | W1 — the permission predicates above |
| `app/Models/Setting.php` | W0 — expose the new flag |
| `app/Http/Controllers/ConsultationsController.php` | W1–W2 — scoped `index`, reworked `signoff`/`reverseSignoff`, new `status` action |
| `app/Http/Requests/ConsultationRequest.php` | W1 — reject creating into another specialty unless coordinator/admin |
| `app/Http/Controllers/ControlController.php` | W1 — persist the coordinator flag |
| `routes/web.php` | W1–W4 — the four new routes |
| `resources/js/Pages/Consultations/Index.vue` | W0–W2 — delete-copy fix, four status tabs, sign-off modal, ageing column, follow-up tick, patient lookup, `entered_by` |
| `resources/js/Pages/Control/Index.vue` | W1 — coordinator flag in the capability UI |
| `resources/js/Pages/Dashboard.vue` | W0 — donut label |
| `resources/js/__tests__/ConsultationsIndex.wave3.test.js` | W0–W2 — extended, never replaced |
| `tests/Feature/LegacyImportTest.php` | W0 — extended with the safety case |

---
## Wave W0 — Safety gate

This wave ships **before any new consultation column exists**. It closes the one defect that could destroy real clinical data (`php artisan legacy:import` truncates and rebuilds `consultations`, so the next data reload would silently erase every consult the doctors entered in the new system) and fixes the two live cosmetic-but-dishonest defects the design review found: a dashboard donut labelled "24h" that actually spans up to 48 hours, and a delete dialog that promises permanence for what is really a soft delete.

Deliberately **not** in this wave: every new column (`owning_specialty_id`, `status`, `signed_off_by`, `admission_id`, `requested_at`, `signed_off_at`, `response_*`), the `consultation_followups` table, the `can_coordinate_consultations` capability, specialty scoping, and the new routes — all of that is W1+ and must not land until Task 1 is merged. One further item is handed forward explicitly: **W1 owns adding the `consultations_source_of_truth` toggle to Control → System** (W1 already edits the Control panel for the coordinator capability). Until then the flag is flipped at cutover with the one-line tinker command given in Task 1, Step 5.

---

### Task 1: `legacy:import` must never destroy app-owned consultations

**Files:**
- Create: `laravel/database/migrations/2026_08_21_000000_add_consultations_source_of_truth_to_settings.php`
- Modify: `laravel/app/Console/Commands/LegacyImport.php`
- Test: `laravel/tests/Feature/LegacyImportTest.php`

Background you need before reading the code. `LegacyImport::handle()` truncates a hard-coded list of new-schema tables (with foreign-key checks disabled) **before** it reads a single row from the `legacy` connection, then rebuilds each one. `consultations` is in that list. Three consequences, all of which this task fixes:

1. Any consultation entered in the Laravel app is erased by the next import.
2. `settings` is in the same truncate list and is rebuilt by `importSettings()` from the legacy `settings` row — which has no such column — so a naive flag would **reset itself to `false` on the first import** and the second import would destroy everything. The flag must be carried across the rebuild.
3. `patients` is truncated and rebuilt (ids restart at 1) and `users` rows carrying a `legacy_id` are deleted and re-inserted (ids change). `consultations.patient_id` / `consultant_id` / `entered_by` are plain `nullOnDelete` FKs, and TRUNCATE with FK checks off does **not** cascade — so surviving consultation rows would keep pointing at ids that now belong to *different people*. Preserving the rows is not enough; they must be re-pointed by natural key (`patients.mrn`, `users.legacy_id`). A user with **no** `legacy_id` was created inside the app, is never deleted by the import, and keeps its id — which is what keeps the immutable `entered_by` attribution honest.

- [ ] **Step 1: Write the failing test**

Append these four methods to the existing `Tests\Feature\LegacyImportTest` class (insert them immediately after `test_import_is_idempotent()`, before the `// ===== Phase 4 — Item 8` banner comment). They reuse that class's existing `seedLegacy()` fixture and its `setUp()`/`tearDown()` legacy-schema harness.

```php
    // ============================================================================================
    // W0 — cutover safety gate: settings.consultations_source_of_truth. Once the Laravel app owns
    // consultations, legacy:import must neither truncate nor re-import them — and must re-point the
    // surviving rows at the REBUILT patient/user ids (patients restart at id 1; legacy-sourced users
    // are deleted and re-inserted), or a preserved consult would silently attach to another patient.
    // ============================================================================================

    /** One legacy consultation row, so we can prove it is (or is not) re-imported. */
    private function seedLegacyConsultation(): void
    {
        DB::connection('legacy')->table('consultations')->insert([
            'id' => 501, 'MRN' => '10001', 'PNAME' => 'Legacy Cx', 'age' => '44', 'BED' => 'W-1',
            'consultation_date' => '2024-01-02', 'consultation_from' => 'Ward', 'current_location' => 'Ward',
            'indication' => '[]', 'consultant_id' => 7, 'entered_by_id' => 7, 'signoff_date' => null,
            'other_ind' => null, 'consultation_to_service' => '1',
        ]);
    }

    /**
     * A consultation as the Laravel app would create it: linked to a patient by MRN, to a
     * PREVIOUSLY-IMPORTED consultant (legacy_id 7 — the import will delete and re-insert that user
     * with a new id), and entered by an APP-CREATED user (no legacy_id — its id must survive).
     * Returns [consultation id, entered-by user id].
     */
    private function seedNewSystemConsultation(): array
    {
        $mk = fn (array $extra) => \App\Models\User::create(array_merge([
            'username' => 'w0_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W0 User', 'password' => 'secret12345',
            'role' => \App\Models\User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => \App\Support\Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));

        $previouslyImported = $mk(['legacy_id' => 7]);      // will be deleted + re-inserted by the import
        $appCreated = $mk([]);                               // no legacy_id -> the import never touches it

        \App\Models\Patient::create(['mrn' => '99999', 'name' => 'Decoy Pt']);   // shifts the id the real patient gets
        $patient = \App\Models\Patient::create(['mrn' => '10001', 'name' => 'Pt One']);

        $c = \App\Models\Consultation::create([
            'mrn' => '10001', 'patient_id' => $patient->id, 'patient_name' => 'New System Cx',
            'consultation_date' => now()->toDateString(), 'indication' => [],
            'to_service' => 'Hospitalist', 'consultant_id' => $previouslyImported->id,
            'entered_by' => $appCreated->id,
        ]);

        return [$c->id, $appCreated->id];
    }

    public function test_import_preserves_and_relinks_consultations_when_the_flag_is_on(): void
    {
        $this->seedLegacy();
        $this->seedLegacyConsultation();
        [$cxId, $appUserId] = $this->seedNewSystemConsultation();
        \App\Models\Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import')
            ->expectsOutputToContain('consultations preserved')
            ->assertSuccessful();

        // the app's consultation survived, and the legacy table was NOT replayed over it
        $this->assertSame(1, DB::table('consultations')->count(), 'exactly the one app-owned row remains');
        $this->assertSame(0, DB::table('consultations')->whereNotNull('legacy_id')->count(),
            'legacy consultations are not re-imported while the app owns them');
        $row = DB::table('consultations')->where('id', $cxId)->first();
        $this->assertNotNull($row);
        $this->assertSame('New System Cx', $row->patient_name);

        // re-linked by natural key: still the SAME patient (patients were rebuilt with new ids)
        $linkedMrn = DB::table('consultations as c')->join('patients as p', 'p.id', '=', 'c.patient_id')
            ->where('c.id', $cxId)->value('p.mrn');
        $this->assertSame('10001', $linkedMrn, 'preserved consult still points at ITS patient after the rebuild');

        // consultant re-pointed at the re-imported legacy user (member_id 7)
        $this->assertSame(
            (int) DB::table('users')->where('legacy_id', 7)->value('id'),
            (int) $row->consultant_id,
            'consultant re-pointed at the rebuilt user row, not at whoever inherited the old id'
        );
        // entered_by is app-created -> its id never moved, so attribution is untouched
        $this->assertSame($appUserId, (int) $row->entered_by, 'entered_by attribution survives the import');

        // and the flag itself survives the settings rebuild (or the NEXT import would wipe everything)
        $this->assertSame(1, (int) DB::table('settings')->orderBy('id')->value('consultations_source_of_truth'));
    }

    public function test_import_still_rebuilds_consultations_when_the_flag_is_off(): void
    {
        $this->seedLegacy();
        $this->seedLegacyConsultation();
        $this->seedNewSystemConsultation();   // flag left at its default (false)

        $this->artisan('legacy:import')->assertSuccessful();

        $this->assertSame(0, DB::table('consultations')->where('patient_name', 'New System Cx')->count(),
            'unchanged behaviour: with the flag off the table is still rebuilt from legacy');
        $this->assertSame(1, DB::table('consultations')->where('legacy_id', 501)->count());
        $this->assertSame(0, (int) DB::table('settings')->orderBy('id')->value('consultations_source_of_truth'));
    }

    public function test_import_refuses_a_forced_consultation_wipe_while_the_app_owns_them(): void
    {
        $this->seedLegacy();
        [$cxId] = $this->seedNewSystemConsultation();
        \App\Models\Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import', ['--wipe-consultations' => true])
            ->expectsOutputToContain('Refusing to wipe consultations')
            ->assertFailed();

        // fail loudly and change NOTHING — not even the rest of the import
        $this->assertSame(1, DB::table('consultations')->where('id', $cxId)->count());
    }

    public function test_import_still_imports_everything_else_while_consultations_are_preserved(): void
    {
        $this->seedLegacy();
        $this->seedNewSystemConsultation();
        \App\Models\Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->artisan('legacy:import')->assertSuccessful();

        // admissions / patients / reference tables import exactly as they do today
        $this->assertSame('Saudi Arabia', DB::table('patients')->where('mrn', '10001')->value('nationality'));
        $this->assertSame(4, DB::table('admissions')->count());
        $this->assertSame(1, DB::table('specialties')->where('name', 'Hospitalist')->count());
        $this->assertNotNull(DB::table('users')->where('legacy_id', 7)->value('id'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=LegacyImportTest
```

Expected failure — the settings column does not exist yet, so the very first `update()` blows up:

```
Illuminate\Database\QueryException: SQLSTATE[42S22]: Column not found: 1054
Unknown column 'consultations_source_of_truth' in 'field list'
FAILED  Tests\Feature\LegacyImportTest > import preserves and relinks consultations when the flag is on
```

(The three other new methods fail the same way; the pre-existing methods in the file still pass.)

- [ ] **Step 3: Write the implementation**

Create `laravel/database/migrations/2026_08_21_000000_add_consultations_source_of_truth_to_settings.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultation ledger W0 — cutover safety gate.
 *
 * `php artisan legacy:import` rebuilds the new schema from the original DMC database, and every
 * column of the consultation ledger is absent from that legacy schema. Once the doctors start
 * entering consultations HERE, a reload must not replay the legacy table over them.
 *
 *   consultations_source_of_truth = false (default)  legacy:import behaves exactly as before
 *   consultations_source_of_truth = true             legacy:import leaves `consultations` alone
 *
 * Additive and reversible; existing rows keep the default, so nothing changes until it is flipped
 * at cutover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('consultations_source_of_truth')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('consultations_source_of_truth');
        });
    }
};
```

In `laravel/app/Console/Commands/LegacyImport.php`, replace the `$signature` line:

```php
    protected $signature = 'legacy:import {--fresh : wipe new tables first (default true)}';
```

with:

```php
    protected $signature = 'legacy:import
        {--fresh : wipe new tables first (default true)}
        {--wipe-consultations : rebuild consultations from legacy even though this app may own them — REFUSED while settings.consultations_source_of_truth is on}';
```

Replace the whole of `handle()` (from `public function handle(): int` down to its closing `}`, i.e. the block currently ending with `return self::SUCCESS;`) with:

```php
    public function handle(): int
    {
        // Transforming the whole legacy DB (tens of thousands of episodes) materialises large result
        // sets; the CLI default of 128M is not enough. Raise the ceiling to 1G, but never LOWER an
        // admin's already-higher (or unlimited) setting.
        $this->ensureMemory('1024M');

        // ── Consultation ledger W0: cutover safety gate ────────────────────────────────────────
        // Read the flag BEFORE anything is truncated: `settings` is itself rebuilt below, so this is
        // the last moment the stored value is readable. Query the table directly rather than through
        // Setting::current(), which memoises per-request and would go stale across the rebuild.
        $preserveConsultations = (bool) DB::table('settings')->orderBy('id')->value('consultations_source_of_truth');

        if ($this->option('wipe-consultations') && $preserveConsultations) {
            $this->error('Refusing to wipe consultations: settings.consultations_source_of_truth is ON, '
                . 'so THIS application — not the legacy database — owns the consultation ledger. '
                . 'Nothing was imported. Turn the flag off in Control → System if you really mean to '
                . 'rebuild consultations from the legacy dump (this destroys every consultation '
                . 'entered here since cutover).');

            return self::FAILURE;
        }

        $this->legacy = DB::connection('legacy');
        $this->info('Importing from legacy connection: ' . $this->legacy->getDatabaseName());

        // Preserve explicit id=0 reference rows (legacy consultation_reason 'other'=0) instead of
        // letting an AUTO_INCREMENT column turn 0 into the next value.
        DB::statement("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");

        // Handover + notification tables MUST be truncated whenever admissions are re-imported:
        // they reference admissions/users by id, and a fresh import re-seeds those ids — TRUNCATE
        // (with FK checks off) bypasses the cascade, so stale rows would otherwise survive and
        // point at the wrong (or missing) episodes after a new legacy dump is loaded.
        $tables = ['handover_signatures', 'handover_revisions', 'handovers', 'notifications',
                   'admission_diagnoses', 'admissions', 'consultations', 'patients', 'icd10',
                   'specialties', 'consultation_reasons', 'tb_diagnoses', 'countries', 'settings'];
        $consultationLinks = [];
        if ($preserveConsultations) {
            $tables = array_values(array_diff($tables, ['consultations']));
            // Surviving rows point at patient/user ids that are about to be re-seeded. Capture their
            // natural keys now so they can be re-pointed after the rebuild (see relinkPreserved…).
            $consultationLinks = $this->captureConsultationLinks();
        }

        Schema::disableForeignKeyConstraints();
        foreach ($tables as $t) {
            DB::table($t)->truncate();
        }
        DB::table('users')->whereNotNull('legacy_id')->delete();
        Schema::enableForeignKeyConstraints();

        $this->importReference();
        $userMap = $this->importUsers();
        $patientMap = $this->importPatients();
        $this->importAdmissions($userMap, $patientMap);
        if ($preserveConsultations) {
            $kept = (int) DB::table('consultations')->count();
            $this->warn("  consultations preserved: {$kept} row(s) kept and NOT re-imported — "
                . 'settings.consultations_source_of_truth is ON, so this application owns the '
                . 'consultation ledger and the legacy table is ignored.');
            $this->relinkPreservedConsultations($consultationLinks, $userMap, $patientMap);
        } else {
            $this->importConsultations($userMap, $patientMap);
        }
        $this->importSettings($preserveConsultations);

        $this->newLine();
        $this->info('✔ Import complete.');
        $this->table(['Table', 'Rows'], collect(['users', 'patients', 'admissions', 'admission_diagnoses', 'consultations', 'icd10', 'specialties'])
            ->map(fn ($t) => [$t, number_format(DB::table($t)->count())])->all());

        return self::SUCCESS;
    }

    /**
     * Natural-key snapshot of the surviving consultations' foreign keys, taken BEFORE the truncate.
     * `patients` is rebuilt from scratch and legacy-sourced users are deleted + re-inserted, so both
     * sets of ids change; `patients.mrn` and `users.legacy_id` survive and let each row be re-pointed
     * afterwards. A user with NO legacy_id was created inside this application, is never deleted
     * here, and keeps its id — which is what preserves the immutable `entered_by` attribution.
     */
    private function captureConsultationLinks(): array
    {
        return DB::table('consultations as c')
            ->leftJoin('users as cu', 'cu.id', '=', 'c.consultant_id')
            ->leftJoin('users as eu', 'eu.id', '=', 'c.entered_by')
            ->selectRaw('c.id, c.mrn, c.patient_id, c.consultant_id, c.entered_by,
                         cu.legacy_id consultant_legacy_id, eu.legacy_id entered_by_legacy_id')
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Re-point preserved consultations at the rebuilt rows. Writes through the query builder on
     * purpose: this is a mechanical re-link, not a clinical edit, so it must not touch updated_at,
     * fire model events, or bust caches. An unresolvable reference becomes NULL (the columns are all
     * nullable, nullOnDelete) rather than a dangling id pointing at a different human being.
     */
    private function relinkPreservedConsultations(array $links, array $userMap, array $patientMap): void
    {
        $remapUser = function ($currentId, $legacyId) use ($userMap) {
            if ($currentId === null) { return null; }
            if ($legacyId === null) { return (int) $currentId; }   // app-created user — its id survived
            return $userMap[(int) $legacyId] ?? null;              // legacy user — new id, or gone
        };

        $changed = 0;
        foreach ($links as $l) {
            $mrn = mb_substr(trim((string) ($l['mrn'] ?? '')), 0, 64);
            $new = [
                'patient_id' => $mrn === '' ? null : ($patientMap[$mrn] ?? null),
                'consultant_id' => $remapUser($l['consultant_id'], $l['consultant_legacy_id']),
                'entered_by' => $remapUser($l['entered_by'], $l['entered_by_legacy_id']),
            ];
            if ((int) $new['patient_id'] === (int) $l['patient_id']
                && (int) $new['consultant_id'] === (int) $l['consultant_id']
                && (int) $new['entered_by'] === (int) $l['entered_by']) {
                continue;
            }
            DB::table('consultations')->where('id', $l['id'])->update($new);
            $changed++;
        }
        $this->info("  preserved consultations re-linked: {$changed} row(s) re-pointed at the rebuilt patient/user ids");
    }
```

Finally, still in `LegacyImport.php`, change `importSettings()` so the flag survives the `settings` rebuild. Replace its signature line and add one row to the insert:

```php
    /**
     * Rebuild the single settings row from legacy. `consultations_source_of_truth` has no legacy
     * counterpart, so it is CARRIED THROUGH from the value read at the top of handle(); otherwise
     * the first import would silently reset the gate to false and the second would destroy the
     * ledger it was installed to protect.
     */
    private function importSettings(bool $preserveConsultations): void
    {
        $s = $this->legacy->table('settings')->where('id', 0)->first();
        DB::table('settings')->insert([
            'id' => 1,
            'min_hospitalist' => (int) ($s->min_hospitalist ?? 6),
            'max_hospitalist' => (int) ($s->max_hospitalist ?? 30),
            'min_subs' => (int) ($s->min_subs ?? 7),
            'max_subs' => (int) ($s->max_subs ?? 7),
            'short_los' => (int) ($s->short_los ?? 5),
            'long_los' => (int) ($s->long_los ?? 11),
            'mfa_enforcement' => (int) ($s->mfa_enforcement ?? 0),
            'consultations_source_of_truth' => $preserveConsultations,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->info('  settings imported');
    }
```

Then run the migration against the test database:

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan migrate
```

Expected: `2026_08_21_000000_add_consultations_source_of_truth_to_settings .......... DONE`

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=LegacyImportTest
```

Expected: `OK` / `Tests:  17 passed` — the 13 pre-existing methods plus the 4 new ones, including

```
✓ import preserves and relinks consultations when the flag is on
✓ import still rebuilds consultations when the flag is off
✓ import refuses a forced consultation wipe while the app owns them
✓ import still imports everything else while consultations are preserved
```

- [ ] **Step 5: Commit**

```
cd C:/Users/ahmed/Downloads/DMC && git add laravel/app/Console/Commands/LegacyImport.php laravel/database/migrations/2026_08_21_000000_add_consultations_source_of_truth_to_settings.php laravel/tests/Feature/LegacyImportTest.php && git commit -m "feat(import): consultations_source_of_truth gate so legacy:import preserves and re-links app-owned consultations (W0)"
```

Cutover note for whoever runs the switch (W1 adds the Control → System toggle; until then):

```
cd laravel && C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan tinker --execute="App\Models\Setting::current()->update(['consultations_source_of_truth' => true]);"
```

---

### Task 2: relabel the dashboard consultation metric to what it actually measures

**Files:**
- Modify: `laravel/app/Http/Controllers/DashboardController.php`
- Modify: `laravel/resources/js/Pages/Dashboard.vue`
- Modify: `laravel/resources/js/__tests__/Dashboard.adminBand.test.js`
- Modify: `laravel/tests/Feature/Round5J2Test.php`
- Modify: `laravel/docs/DASHBOARD-AND-STATISTICS-METRICS.md`
- Test: `laravel/tests/Feature/W0ConsultDonutWindowTest.php`

**The chosen approach, stated explicitly: RELABEL, do not re-window.** `consultations.signoff_date` is a `DATE` column (`laravel/database/migrations/2026_06_08_120005_create_consultations_table.php:26`) — there is no time of day stored anywhere, so a true rolling 24-hour window is *not computable* from the data we have. Faking one (e.g. `>= now()->subDay()` on a date column) would produce the identical rows with a more convincing lie. So the query keeps counting the same rows it counts today, and the name, the payload key, the donut label, the heading, the caption and the ARIA text all change to say **"today or yesterday"**, which is exactly what it measures. Two consequential edits ride along, both deliberate and both pinned by the test: the payload key `signed24h` is **renamed** to `signedTodayOrYesterday` (so no consumer can keep reading a misleading name), and the window is **bounded at both ends** with `whereBetween` so a future-dated `signoff_date` — only reachable through legacy data, since sign-off writes `now()->toDateString()` — can no longer inflate a count labelled "today or yesterday".

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/W0ConsultDonutWindowTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Consultation ledger W0 — the dashboard consultation donut told a half-truth: the slice was
 * labelled "Signed off (24h)" while the query counted `signoff_date >= yesterday`, a window that is
 * 24 to 48 hours wide depending on the hour of the day. `signoff_date` is a DATE column, so a real
 * rolling 24h window is not computable; the metric is therefore RELABELLED to what it measures
 * (today or yesterday) and the payload key renamed so nothing can keep reading the old claim.
 */
class W0ConsultDonutWindowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'w0d_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'W0 Admin', 'password' => 'secret12345',
            'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function consult(string $mrn, ?string $signoff): Consultation
    {
        return Consultation::create([
            'mrn' => $mrn, 'patient_name' => 'Donut Pt ' . $mrn,
            'consultation_date' => now()->subDays(6)->toDateString(),
            'signoff_date' => $signoff, 'indication' => [],
        ]);
    }

    public function test_consult_donut_counts_sign_offs_from_today_and_yesterday_only(): void
    {
        $this->consult('94000001', now()->toDateString());               // counted
        $this->consult('94000002', now()->subDay()->toDateString());     // counted
        $this->consult('94000003', now()->subDays(2)->toDateString());   // outside the window
        $this->consult('94000004', now()->addDay()->toDateString());     // bad legacy data: future date
        $this->consult('94000005', null);                                // still open -> the "active" half

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultDonut.signedTodayOrYesterday', 2)
                ->where('consultDonut.active', 1)
                ->missing('consultDonut.signed24h'));
    }

    public function test_consult_donut_is_zero_when_nothing_was_signed_recently(): void
    {
        $this->consult('94000010', now()->subDays(30)->toDateString());
        $this->consult('94000011', null);

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultDonut.signedTodayOrYesterday', 0)
                ->where('consultDonut.active', 1));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=W0ConsultDonutWindowTest
```

Expected failure — the key does not exist yet and the old one still does:

```
FAILED  Tests\Feature\W0ConsultDonutWindowTest > consult donut counts sign offs from today and yesterday only
Inertia property [consultDonut.signedTodayOrYesterday] does not exist.
```

- [ ] **Step 3: Write the implementation**

In `laravel/app/Http/Controllers/DashboardController.php`, replace these two lines (currently at :42-44):

```php
        // consultation donut = [signed-off in the last 24h, active] — legacy dashboard/1.php
        // (`signoff_date + INTERVAL 1 DAY >= CURDATE()`, i.e. yesterday + today) (J2-5)
        $signed24h = (int) DB::table('consultations')->where('signoff_date', '>=', Carbon::yesterday()->toDateString())->whereNull('deleted_at')->count();
```

with:

```php
        // Consultation donut, first slice. `signoff_date` is a DATE column — there is no time of day
        // anywhere in the data — so a true rolling 24-hour window is NOT computable, and the old
        // `signed24h` name plus the "(24h)" label claimed a precision the column cannot carry (the
        // real window is 24-48h wide depending on the hour). W0 relabels instead of faking: the key,
        // the donut label, the heading and the caption now all say "today or yesterday", which is
        // exactly the legacy dashboard/1.php population (`signoff_date + INTERVAL 1 DAY >= CURDATE()`,
        // i.e. yesterday + today) (J2-5). Bounded at BOTH ends so the label is literally true: a
        // future-dated signoff_date (only reachable through legacy data — sign-off writes CURDATE())
        // no longer counts toward a figure captioned "today or yesterday".
        $signedTodayOrYesterday = (int) DB::table('consultations')
            ->whereBetween('signoff_date', [Carbon::yesterday()->toDateString(), $today])
            ->whereNull('deleted_at')->count();
```

In the same file, replace the payload line (currently at :439):

```php
            'consultDonut' => ['signed24h' => $signed24h, 'active' => $activeConsults],
```

with:

```php
            'consultDonut' => ['signedTodayOrYesterday' => $signedTodayOrYesterday, 'active' => $activeConsults],
```

In `laravel/resources/js/Pages/Dashboard.vue`, replace the consultation-donut options block (currently at :289-299):

```js
// consultation donut — legacy dashboard/1.php pair: [signed off in the last 24h, active] (J2-5)
const consultDonutOptions = computed(() => ({
    chart: { type: 'donut', toolbar: dlToolbar, fontFamily: 'inherit', animations: chartAnimations(reduced) },
    colors: [series.value.accent, series.value.primary],
    labels: ['Signed off (24h)', 'Active'],
    legend: { ...donutLegend, labels: { colors: axisColor.value } },
    dataLabels: { enabled: true, formatter: (v, o) => o.w.globals.series[o.seriesIndex] },
    stroke: { width: 2, colors: [strokeColor.value] },
    plotOptions: { pie: { donut: { size: '70%' } } },
}));
const consultDonutSeries = computed(() => [props.consultDonut.signed24h, props.consultDonut.active]);
```

with:

```js
// consultation donut — legacy dashboard/1.php pair: [signed off today or yesterday, active] (J2-5).
// W0: the slice used to be captioned 24h while the query spanned today + yesterday. signoff_date is
// a date, not a timestamp, so the honest name is the one below.
const consultDonutOptions = computed(() => ({
    chart: { type: 'donut', toolbar: dlToolbar, fontFamily: 'inherit', animations: chartAnimations(reduced) },
    colors: [series.value.accent, series.value.primary],
    labels: ['Signed off (today + yesterday)', 'Active'],
    legend: { ...donutLegend, labels: { colors: axisColor.value } },
    dataLabels: { enabled: true, formatter: (v, o) => o.w.globals.series[o.seriesIndex] },
    stroke: { width: 2, colors: [strokeColor.value] },
    plotOptions: { pie: { donut: { size: '70%' } } },
}));
const consultDonutSeries = computed(() => [props.consultDonut.signedTodayOrYesterday, props.consultDonut.active]);
```

Replace the guard (currently at :325):

```js
const hasConsultDonut = computed(() => (props.consultDonut.signed24h + props.consultDonut.active) > 0);
```

with:

```js
const hasConsultDonut = computed(() => (props.consultDonut.signedTodayOrYesterday + props.consultDonut.active) > 0);
```

Replace the accessible-alternative rows (currently at :336-337):

```js
const consultDonutRows = computed(() => (hasConsultDonut.value
    ? [['Signed off (24h)', props.consultDonut.signed24h], ['Active', props.consultDonut.active]] : []));
```

with:

```js
const consultDonutRows = computed(() => (hasConsultDonut.value
    ? [['Signed off (today + yesterday)', props.consultDonut.signedTodayOrYesterday], ['Active', props.consultDonut.active]] : []));
```

Replace the template card (currently at :549-556):

```html
            <!-- legacy dashboard/1.php consultation donut: signed off in the last 24h vs active (J2-5) -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Consultations <span class="font-normal text-ink-400">(24h sign-offs vs active)</span></h2>
                <ChartFigure title="Consultations — 24h sign-offs vs active" caption="Consultations signed off in the last 24 hours versus those still active." :columns="['Status', 'Count']" :rows="consultDonutRows">
                    <apexchart v-if="hasConsultDonut" type="donut" height="260" :options="consultDonutOptions" :series="consultDonutSeries" :aria-label="`Donut chart: ${consultDonut.signed24h} consultations signed off in the last 24 hours, ${consultDonut.active} active`" />
                    <p v-else class="grid h-[260px] place-items-center text-sm text-ink-400">No data for this period.</p>
                </ChartFigure>
            </div>
```

with:

```html
            <!-- legacy dashboard/1.php consultation donut: signed off today or yesterday vs active (J2-5) -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Consultations <span class="font-normal text-ink-400">(sign-offs today + yesterday vs active)</span></h2>
                <ChartFigure title="Consultations — sign-offs today and yesterday vs active" caption="Consultations signed off today or yesterday, versus those still awaiting sign-off. Sign-off is recorded as a calendar date with no time of day, so this counts two calendar days rather than a rolling 24 hours." :columns="['Status', 'Count']" :rows="consultDonutRows">
                    <apexchart v-if="hasConsultDonut" type="donut" height="260" :options="consultDonutOptions" :series="consultDonutSeries" :aria-label="`Donut chart: ${consultDonut.signedTodayOrYesterday} consultations signed off today or yesterday, ${consultDonut.active} active`" />
                    <p v-else class="grid h-[260px] place-items-center text-sm text-ink-400">No data for this period.</p>
                </ChartFigure>
            </div>
```

In `laravel/resources/js/__tests__/Dashboard.adminBand.test.js`, replace the fixture line (currently at :51):

```js
    consultDonut: { signed24h: 0, active: 0 }, los: { labels: [], data: [] },
```

with:

```js
    consultDonut: { signedTodayOrYesterday: 0, active: 0 }, los: { labels: [], data: [] },
```

In `laravel/tests/Feature/Round5J2Test.php`, deliberately update the pinned key (currently at :229) — same expected number, honest name:

```php
                ->where('consultDonut.signed24h', 1)   // legacy: signoff_date + 1 day >= today
```

becomes:

```php
                ->where('consultDonut.signedTodayOrYesterday', 1)   // legacy: signoff_date + 1 day >= today
```

Finally, in `laravel/docs/DASHBOARD-AND-STATISTICS-METRICS.md`, insert this row into the **Charts** table immediately after the `**Consultations (6 months)**` row:

```
| **Consultations donut** | Two slices: **Signed off (today + yesterday)** = `COUNT(consultations WHERE signoff_date BETWEEN yesterday AND today)`, and **Active** = `COUNT(consultations WHERE signoff_date IS NULL)`. `signoff_date` is a **DATE** column with no time of day, so this is deliberately a two-calendar-day window, **not** a rolling 24 hours — the pre-W0 "(24h)" label overstated what the data can express. Same population as legacy `dashboard/1.php` (`signoff_date + INTERVAL 1 DAY >= CURDATE()`). |
```

- [ ] **Step 4: Run test to verify it passes**

Rebuild the committed bundle first (any change under `resources/` requires it), in this exact order:

```
cd laravel && npx vite build
cd laravel && node scripts/check-source-allowlist.mjs --write
cd laravel && node scripts/check-source-allowlist.mjs
cd laravel && node scripts/contrast.mjs
```

Expected: the build writes `public/build/manifest.json` and its assets; `check-source-allowlist.mjs` prints `PASS`; `contrast.mjs` prints `PASS`.

Then the tests:

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=W0ConsultDonutWindowTest
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=Round5J2Test
cd laravel && npx vitest run
```

Expected: `Tests: 2 passed` for `W0ConsultDonutWindowTest`; `Round5J2Test` fully green (its consult-donut assertion now reads the new key); Vitest green with no failures in `Dashboard.adminBand.test.js`.

- [ ] **Step 5: Commit**

```
cd C:/Users/ahmed/Downloads/DMC && git add laravel/app/Http/Controllers/DashboardController.php laravel/resources/js/Pages/Dashboard.vue laravel/resources/js/__tests__/Dashboard.adminBand.test.js laravel/tests/Feature/Round5J2Test.php laravel/tests/Feature/W0ConsultDonutWindowTest.php laravel/docs/DASHBOARD-AND-STATISTICS-METRICS.md laravel/public/build && git commit -m "fix(dashboard): relabel the consultation donut to sign-offs today + yesterday (signoff_date is a DATE; 24h was never computable)"
```

---

### Task 3: the delete dialog must stop promising permanence

**Files:**
- Modify: `laravel/resources/js/Pages/Consultations/Index.vue`
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Test: `laravel/resources/js/__tests__/ConsultationsIndex.w0DeleteCopy.test.js`
- Test: `laravel/tests/Feature/W0ConsultationDeleteCopyTest.php`

`Consultation` uses `SoftDeletes` (`laravel/app/Models/Consultation.php:13`) and `TrashedController::restoreConsultation` restores it from `/trashed` ("Recently Deleted", `laravel/routes/web.php:251`). The confirmation currently says *"Permanently delete … cannot be undone"*, and the success flash says *"Consultation deleted."* — the first is false, the second is at best incomplete. Both are corrected here; the dialog copy is pinned by a Vitest spec and the flash by a backend test.

- [ ] **Step 1: Write the failing test**

Create `laravel/resources/js/__tests__/ConsultationsIndex.w0DeleteCopy.test.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// Consultation ledger W0: the delete confirmation used to promise a permanent, irreversible
// deletion. It is a SOFT delete an admin restores from Recently Deleted, so the copy must say so.
const { post, deleteFn, ask } = vi.hoisted(() => ({ post: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(() => Promise.resolve(true)) }));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => ({ ...obj, post: vi.fn(), put: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';

const admin = { role: 0, is_admin: true, id: 1, can: { manage: true } };
const props = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { active: 0, total: 0, mine_active: 0 }, reasons: [], consultants: [], specialties: [],
};
const mountWith = (user) => { authUser = user; return shallowMount(ConsultationsIndex, { props, global: { stubs: { teleport: true } } }); };

beforeEach(() => { post.mockClear(); deleteFn.mockClear(); ask.mockClear(); });

describe('Consultations/Index — W0 delete confirmation copy', () => {
    it('does not claim the delete is permanent or irreversible', async () => {
        const vm = mountWith(admin).vm;
        await vm.deleteConsult({ id: 9, name: 'Ada Patient', mrn: '55501', date: '2026-08-20' });

        const body = ask.mock.calls[0][1];
        expect(body).not.toMatch(/permanent/i);
        expect(body).not.toMatch(/cannot be undone/i);
        expect(body).not.toMatch(/erased|destroyed/i);
    });

    it('tells the user an administrator can restore it from Recently Deleted', async () => {
        const vm = mountWith(admin).vm;
        await vm.deleteConsult({ id: 9, name: 'Ada Patient', mrn: '55501', date: '2026-08-20' });

        const [title, body, tone] = ask.mock.calls[0];
        expect(title).toBe('Remove consultation');
        expect(body).toContain('Ada Patient');
        expect(body).toContain('55501');
        expect(body).toContain('ledger');
        expect(body).toContain('restore');
        expect(body).toContain('Recently Deleted');
        expect(tone).toBe('danger');   // still a guarded action, just an honest one
    });

    it('still deletes only after the confirmation resolves true', async () => {
        const vm = mountWith(admin).vm;
        await vm.deleteConsult({ id: 9, name: 'Ada Patient', mrn: '55501', date: '2026-08-20' });
        expect(deleteFn).toHaveBeenCalledWith('/consultations/9', { preserveScroll: true });

        ask.mockResolvedValueOnce(false);
        deleteFn.mockClear();
        await vm.deleteConsult({ id: 10, name: 'Bob Patient', mrn: '55502', date: '2026-08-20' });
        expect(deleteFn).not.toHaveBeenCalled();
    });
});
```

Create `laravel/tests/Feature/W0ConsultationDeleteCopyTest.php`:

```php
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
            'username' => 'w0x_' . substr(md5(uniqid('', true)), 0, 8),
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
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && npx vitest run resources/js/__tests__/ConsultationsIndex.w0DeleteCopy.test.js
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=W0ConsultationDeleteCopyTest
```

Expected Vitest failure (the current copy is the lie under test):

```
FAIL  resources/js/__tests__/ConsultationsIndex.w0DeleteCopy.test.js
 × does not claim the delete is permanent or irreversible
   AssertionError: expected 'Permanently delete the consultation for…' not to match /permanent/i
 × tells the user an administrator can restore it from Recently Deleted
   AssertionError: expected 'Delete consultation' to be 'Remove consultation'
```

Expected PHPUnit failure:

```
FAILED  Tests\Feature\W0ConsultationDeleteCopyTest > delete flash says the row is recoverable and the row is only soft deleted
Session is missing expected key [flash].   (the flash message is "Consultation deleted." — no "restore")
```

- [ ] **Step 3: Write the implementation**

In `laravel/resources/js/Pages/Consultations/Index.vue`, replace the delete handler and the comment block that follows it (currently at :113-118):

```js
const deleteConsult = async (c) => { if (await ask('Delete consultation', `Permanently delete the consultation for ${c.name} (MRN ${c.mrn}), entered ${c.date || 'recently'}. This removes it from the consultation registry entirely and cannot be undone.`, 'danger')) router.delete(`/consultations/${c.id}`, { preserveScroll: true }); };

// sign off — Wave 2, Item 4: no confirm. A single sign-off is reversible (the reverse-signoff
// button, already instant) and low-stakes; the server flash is the feedback. deleteConsult keeps
// its danger confirm (irreversible). Sign-all stays confirmed on the Handovers page (bulk).
const signoff = (row) => router.post(`/consultations/${row.id}/signoff`, {}, { preserveScroll: true });
```

with:

```js
// W0: this used to promise a permanent, irreversible deletion. It is a SOFT delete — the row keeps
// its deleted_at and an admin restores it from Recently Deleted (/trashed) — so the copy now says
// what actually happens. The danger tone stays: it is still a guarded action.
const deleteConsult = async (c) => {
    const body = `Remove the consultation for ${c.name} (MRN ${c.mrn}), entered ${c.date || 'recently'}? `
        + 'It leaves the consultation ledger and stops counting anywhere, but it is kept — '
        + 'an administrator can restore it from Recently Deleted.';
    if (await ask('Remove consultation', body, 'danger')) router.delete(`/consultations/${c.id}`, { preserveScroll: true });
};

// sign off — Wave 2, Item 4: no confirm. A single sign-off is reversible (the reverse-signoff
// button, already instant) and low-stakes; the server flash is the feedback. deleteConsult keeps
// its danger confirm because removing a consult from the ledger changes every count, even though
// it is recoverable. Sign-all stays confirmed on the Handovers page (bulk).
const signoff = (row) => router.post(`/consultations/${row.id}/signoff`, {}, { preserveScroll: true });
```

In `laravel/app/Http/Controllers/ConsultationsController.php`, replace the flash line at the end of `destroy()`:

```php
        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation deleted.']);
```

with:

```php
        // W0: soft delete — say so, so nobody believes the record is gone for good.
        return back()->with('flash', ['type' => 'success',
            'message' => 'Consultation removed from the ledger. An admin can restore it from Recently Deleted.']);
```

- [ ] **Step 4: Run test to verify it passes**

Rebuild the committed bundle first (this task changed `resources/`), in this exact order:

```
cd laravel && npx vite build
cd laravel && node scripts/check-source-allowlist.mjs --write
cd laravel && node scripts/check-source-allowlist.mjs
cd laravel && node scripts/contrast.mjs
```

Expected: the build succeeds; `check-source-allowlist.mjs` prints `PASS`; `contrast.mjs` prints `PASS`.

Then:

```
cd laravel && npx vitest run
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=W0ConsultationDeleteCopyTest
```

Expected: Vitest green overall, including

```
✓ resources/js/__tests__/ConsultationsIndex.w0DeleteCopy.test.js (3 tests)
✓ resources/js/__tests__/ConsultationsIndex.wave2.test.js (2 tests)
```

(the pre-existing `wave2` spec keeps passing — it only asserts that `ask` is called once with tone `danger`, which is still true), and `Tests:  1 passed` for `W0ConsultationDeleteCopyTest`.

- [ ] **Step 5: Commit**

```
cd C:/Users/ahmed/Downloads/DMC && git add laravel/resources/js/Pages/Consultations/Index.vue laravel/app/Http/Controllers/ConsultationsController.php laravel/resources/js/__tests__/ConsultationsIndex.w0DeleteCopy.test.js laravel/tests/Feature/W0ConsultationDeleteCopyTest.php laravel/public/build && git commit -m "fix(consultations): delete dialog and flash tell the truth — soft delete, restorable from Recently Deleted"
```
## Wave W1 — Foundation (schema, backfill, coordinator capability, specialty scoping)

W1 lays every structural piece the consultation ledger needs: the new `consultations` columns, the append-only `consultation_followups` table, a **deliberate** backfill of the 1,283 historical rows, the `can_coordinate_consultations` user flag with its Control → Users control, the model layer (status constants, relations, `visibleTo`/`open` scopes, the new `ConsultationFollowup` model), and server-side specialty scoping on the workspace list and the create path.

**W1 adds columns but does NOT change the visible workflow.** After W1 the workspace still shows the same Active/Signed-off list, sign-off is still one click, and nothing in the UI reads or writes `status`, `requested_at`, or the follow-up table. The four states become interactive in **W2** — W1 only guarantees that the data they need exists, is honest about what it does not know (NULL timestamps for historical rows), and is scoped so a team sees its own book.

Two existing tests are **deliberately updated** in Task 10 (never deleted) because scoping changes what an un-specialtied user sees and what they may create. Both updates are spelled out in full there.

---

### Task 4: Migration — add the ledger columns to `consultations`

**Files:**
- Create: `laravel/database/migrations/2026_08_21_000100_add_ledger_columns_to_consultations.php`
- Test: `laravel/tests/Feature/ConsultationLedgerSchemaTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationLedgerSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Consultation ledger W1 — schema tasks (4, 5, 7).
 *
 * These pin the SHAPE of the ledger: every new column is additive and nullable, the follow-up
 * table is append-only with a one-tick-per-day guarantee, and the coordinator flag defaults off.
 * Nothing here changes behaviour — the workflow that uses these columns arrives in W2.
 */
class ConsultationLedgerSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultations_table_has_every_ledger_column(): void
    {
        foreach ([
            'owning_specialty_id', 'status', 'signed_off_by', 'admission_id',
            'requested_at', 'signed_off_at',
            'response_disposition', 'response_followup_needed', 'response_note',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('consultations', $column),
                "consultations is missing the ledger column {$column}"
            );
        }
    }

    public function test_a_legacy_shaped_row_still_inserts_and_defaults_are_honest(): void
    {
        // exactly the column set the legacy importer writes — proves the migration is ADDITIVE:
        // none of the 1,283 historical rows needed a value for any new column.
        $id = DB::table('consultations')->insertGetId([
            'mrn' => '77000001', 'patient_name' => 'Legacy Shape', 'age' => 61, 'bed' => 'W-1',
            'current_location' => 'Ward', 'consultation_date' => '2024-03-01',
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('consultations')->where('id', $id)->first();

        $this->assertSame('new', $row->status, 'status must default to new');
        $this->assertNull($row->owning_specialty_id);
        $this->assertNull($row->admission_id);
        $this->assertNull($row->requested_at, 'requested_at must never be fabricated');
        $this->assertNull($row->signed_off_at);
        $this->assertNull($row->signed_off_by);
        $this->assertNull($row->response_disposition);
        $this->assertNull($row->response_followup_needed);
        $this->assertNull($row->response_note);
    }

    public function test_owning_specialty_is_nulled_not_cascaded_when_a_specialty_is_removed(): void
    {
        $spec = Specialty::create(['name' => 'W1 Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $c = Consultation::create([
            'mrn' => '77000002', 'patient_name' => 'Owned Pt', 'consultation_date' => '2024-03-02',
            'to_service' => 'W1 Cardiology', 'indication' => [], 'owning_specialty_id' => $spec->id,
        ]);

        DB::table('specialties')->where('id', $spec->id)->delete();

        // the consult row SURVIVES with a NULL owner (Unassigned) — clinical data is never destroyed
        $this->assertNotNull(DB::table('consultations')->where('id', $c->id)->first());
        $this->assertNull(DB::table('consultations')->where('id', $c->id)->value('owning_specialty_id'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerSchemaTest
```

Expected failure: `consultations is missing the ledger column owning_specialty_id` (and the second test fails with `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'status'`).

- [ ] **Step 3: Write the implementation**

Create `laravel/database/migrations/2026_08_21_000100_add_ledger_columns_to_consultations.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultation ledger (W1) — ADDITIVE columns on `consultations`.
 *
 * Every column is nullable (or defaulted), so all 1,283 historical rows keep their exact current
 * content and remain readable/editable the moment this lands. `to_service` and `consultation_date`
 * are deliberately RETAINED unchanged: they stay the display/continuity values, while
 * `owning_specialty_id` and `requested_at` become authoritative going forward.
 *
 *  owning_specialty_id — the scoping key (nullable => "Unassigned" bucket for unmatched legacy rows)
 *  status              — new | active | ongoing | signed_off (see Consultation::STATUS_*)
 *  signed_off_by       — who signed off (today only the audit log knows)
 *  admission_id        — ties a consult to the actual stay (re-admissions each create a new row)
 *  requested_at        — REAL request time; NULL for every historical row, never fabricated
 *  signed_off_at       — REAL sign-off time; NULL for every historical row
 *  response_*          — the structured outcome captured at sign-off (W2 fills these)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->foreignId('admission_id')->nullable()->after('patient_id')
                ->constrained('admissions')->nullOnDelete();          // constrained() also indexes it
            $table->dateTime('requested_at')->nullable()->after('consultation_date')->index();
            $table->foreignId('owning_specialty_id')->nullable()->after('to_service')
                ->constrained('specialties')->nullOnDelete();
            $table->string('status', 16)->default('new')->after('owning_specialty_id')->index();
            $table->foreignId('signed_off_by')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('signed_off_at')->nullable()->after('signoff_date');
            $table->string('response_disposition', 32)->nullable()->after('signed_off_at');
            $table->boolean('response_followup_needed')->nullable()->after('response_disposition');
            $table->text('response_note')->nullable()->after('response_followup_needed');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            // dropConstrainedForeignId drops the FK, its index, and the column
            $table->dropConstrainedForeignId('admission_id');
            $table->dropConstrainedForeignId('owning_specialty_id');
            $table->dropConstrainedForeignId('signed_off_by');
            $table->dropIndex(['requested_at']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'requested_at', 'status', 'signed_off_at',
                'response_disposition', 'response_followup_needed', 'response_note',
            ]);
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan migrate
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerSchemaTest
```

Expected: `OK (3 tests, ...)` — `Tests: 3 passed`.

- [ ] **Step 5: Commit**

```
git add laravel/database/migrations/2026_08_21_000100_add_ledger_columns_to_consultations.php laravel/tests/Feature/ConsultationLedgerSchemaTest.php
git commit -m "feat(consultations): add ledger columns (owning specialty, status, real timestamps, response) to consultations"
```

---

### Task 5: Migration — create the append-only `consultation_followups` table

**Files:**
- Create: `laravel/database/migrations/2026_08_21_000200_create_consultation_followups_table.php`
- Modify: `laravel/tests/Feature/ConsultationLedgerSchemaTest.php`

- [ ] **Step 1: Write the failing test**

Add these three methods to `laravel/tests/Feature/ConsultationLedgerSchemaTest.php`, immediately before the file's final closing `}`:

```php
    // ---- Task 5: consultation_followups (append-only, one tick per consult per day) -------------

    public function test_followups_table_is_append_only(): void
    {
        $this->assertTrue(Schema::hasTable('consultation_followups'));

        foreach (['id', 'consultation_id', 'followup_date', 'note', 'author_id', 'created_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('consultation_followups', $column),
                "consultation_followups is missing {$column}"
            );
        }

        // append-only: a follow-up tick is a fact that happened, it is never edited
        $this->assertFalse(
            Schema::hasColumn('consultation_followups', 'updated_at'),
            'consultation_followups is append-only and must have no updated_at'
        );
    }

    public function test_a_consult_cannot_be_ticked_twice_on_the_same_day(): void
    {
        $c = Consultation::create([
            'mrn' => '77000003', 'patient_name' => 'Tick Pt', 'consultation_date' => '2024-03-03',
            'to_service' => 'Cardiology', 'indication' => [],
        ]);

        DB::table('consultation_followups')->insert([
            'consultation_id' => $c->id, 'followup_date' => '2026-08-21', 'note' => 'seen',
        ]);

        // the unique index IS the correctness guarantee behind "seen 8 of 12 today"
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('consultation_followups')->insert([
            'consultation_id' => $c->id, 'followup_date' => '2026-08-21', 'note' => 'seen again',
        ]);
    }

    public function test_followups_are_removed_with_their_consultation_row(): void
    {
        $c = Consultation::create([
            'mrn' => '77000004', 'patient_name' => 'Cascade Pt', 'consultation_date' => '2024-03-04',
            'to_service' => 'Cardiology', 'indication' => [],
        ]);
        DB::table('consultation_followups')->insert([
            'consultation_id' => $c->id, 'followup_date' => '2026-08-20',
        ]);

        // NOTE: Consultation soft-deletes, so $c->delete() only stamps deleted_at and the follow-ups
        // correctly stay. This asserts the FK itself, on a HARD delete of the underlying row.
        DB::table('consultations')->where('id', $c->id)->delete();

        $this->assertSame(0, DB::table('consultation_followups')->where('consultation_id', $c->id)->count());
    }
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerSchemaTest
```

Expected failure: `Failed asserting that false is true.` on `Schema::hasTable('consultation_followups')`, plus `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'dmc_test2.consultation_followups' doesn't exist` in the other two.

- [ ] **Step 3: Write the implementation**

Create `laravel/database/migrations/2026_08_21_000200_create_consultation_followups_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily follow-up log — one row per consult per day the team ticked it off.
 *
 * Copies the proven append-only shape of `handover_revisions`
 * (2026_06_11_000001_create_handover_tables.php): id + cascading FK + text body + nullable author
 * FK (nullOnDelete, so a deleted account never erases history) + created_at useCurrent and NO
 * updated_at. A tick is a fact that happened; it is appended, never edited.
 *
 * unique([consultation_id, followup_date]) is the correctness guarantee: a consult cannot be
 * double-ticked for one day, so the team's "seen 8 of 12 today" completeness count is always exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->date('followup_date');
            $table->text('note')->nullable();                       // optional one-liner
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();          // append-only — no updated_at
            $table->unique(['consultation_id', 'followup_date']);   // one tick per consult per day
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_followups');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan migrate
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerSchemaTest
```

Expected: `Tests: 6 passed`.

- [ ] **Step 5: Commit**

```
git add laravel/database/migrations/2026_08_21_000200_create_consultation_followups_table.php laravel/tests/Feature/ConsultationLedgerSchemaTest.php
git commit -m "feat(consultations): create append-only consultation_followups table with one-tick-per-day uniqueness"
```

---

### Task 6: Migration — backfill status and owning specialty for the historical rows

> **This is the most dangerous task in the plan.** It touches every one of the ~1,283 real
> consultation rows. It writes only columns that did not exist five minutes ago; it never edits
> `to_service`, `consultation_date`, `signoff_date`, `consultant_id` or `entered_by`.
>
> **Open legacy rows (`signoff_date IS NULL`) become `ongoing`, NEVER `active`.**
> Verbatim reason, to be preserved in the migration docblock and in the test:
> *mapping open legacy rows to `active` would fabricate a daily follow-up obligation for every one
> of them, so on launch day every team would open a huge false "must be seen today" list. This
> project already suffered exactly that with the handover backlog.* `ongoing` states the truth —
> on the books, no daily commitment asserted — and teams promote to `active` deliberately.
>
> **`requested_at` / `signed_off_at` stay NULL.** Both legacy clinical columns are DATE-only.
> Deriving a datetime from them would manufacture precision that never existed and would poison
> every turnaround metric. Turnaround therefore covers cutover onward only.
>
> **`owning_specialty_id` is resolved, never guessed.** Case-insensitive match of `to_service`
> against `specialties.name`, restricted to internal specialties — the same shape as the precedent
> `2026_06_11_000002_resolve_numeric_consultation_to_service.php`. Anything that does not match
> (external services, free text such as "Some Outside Clinic", blanks) stays NULL and lands in the
> Unassigned bucket.

**Files:**
- Create: `laravel/database/migrations/2026_08_21_000300_backfill_consultation_ledger_state.php`
- Test: `laravel/tests/Feature/ConsultationBackfillTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationBackfillTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Consultation ledger W1, Task 6 — the backfill of the historical rows.
 *
 * The rule that matters clinically: an OPEN legacy consult becomes `ongoing`, never `active`.
 * `active` means "this team owes this patient a follow-up TODAY". Backfilling 1,283 open legacy
 * rows to `active` would fabricate that obligation for every one of them and hand every team a
 * huge false worklist on launch day — the exact failure this project already hit with the handover
 * backlog. `ongoing` is the honest state; teams promote to `active` by hand.
 *
 * The migration is re-runnable in isolation (same idiom as FinalSweepG1Test's numeric-to_service
 * test): build rows, require the migration, call up(), assert.
 */
class ConsultationBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_08_21_000300_backfill_consultation_ledger_state.php');
        $migration->up();
    }

    private function consult(array $overrides = []): Consultation
    {
        static $n = 0;
        $n++;

        return Consultation::create(array_merge([
            'mrn' => '7810000' . $n, 'patient_name' => 'Backfill Pt ' . $n,
            'consultation_date' => '2024-04-0' . min($n, 9), 'indication' => [],
        ], $overrides));
    }

    public function test_open_legacy_rows_become_ongoing_and_never_active(): void
    {
        $open = $this->consult(['to_service' => 'Cardiology', 'signoff_date' => null]);

        $this->runBackfill();

        $status = DB::table('consultations')->where('id', $open->id)->value('status');
        $this->assertSame('ongoing', $status);
        $this->assertNotSame('active', $status,
            'open legacy rows must NOT be backfilled to active — that fabricates a daily follow-up obligation');
    }

    public function test_signed_off_legacy_rows_become_signed_off(): void
    {
        $closed = $this->consult(['to_service' => 'Cardiology', 'signoff_date' => '2024-04-10']);

        $this->runBackfill();

        $this->assertSame('signed_off', DB::table('consultations')->where('id', $closed->id)->value('status'));
    }

    public function test_owning_specialty_resolves_case_insensitively_and_never_guesses(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        Specialty::create(['name' => 'Dietary', 'is_subspecialty' => true, 'is_external' => true]);

        $lower    = $this->consult(['to_service' => 'cardiology']);
        $padded   = $this->consult(['to_service' => '  Cardiology  ']);
        $external = $this->consult(['to_service' => 'Dietary']);
        $freeText = $this->consult(['to_service' => 'Some Outside Clinic']);
        $blank    = $this->consult(['to_service' => null]);

        $this->runBackfill();

        $owner = fn ($c) => DB::table('consultations')->where('id', $c->id)->value('owning_specialty_id');

        $this->assertSame($cardio->id, (int) $owner($lower));
        $this->assertSame($cardio->id, (int) $owner($padded));
        $this->assertNull($owner($external), 'external services have no IM team — they stay Unassigned');
        $this->assertNull($owner($freeText), 'an unmatched service must never be guessed into a team');
        $this->assertNull($owner($blank));

        // to_service itself is untouched — it stays the display/continuity value
        $this->assertSame('cardiology', DB::table('consultations')->where('id', $lower->id)->value('to_service'));
    }

    public function test_real_timestamps_are_never_fabricated_from_a_date(): void
    {
        $open   = $this->consult(['to_service' => 'Cardiology']);
        $closed = $this->consult(['to_service' => 'Cardiology', 'signoff_date' => '2024-04-11']);

        $this->runBackfill();

        foreach ([$open, $closed] as $c) {
            $row = DB::table('consultations')->where('id', $c->id)->first();
            $this->assertNull($row->requested_at, 'requested_at must stay NULL for historical rows');
            $this->assertNull($row->signed_off_at, 'signed_off_at must stay NULL for historical rows');
            $this->assertNull($row->signed_off_by);
        }
    }

    public function test_backfill_touches_no_clinical_field(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $c = $this->consult([
            'to_service' => 'Cardiology', 'signoff_date' => '2024-04-12',
            'consultation_from' => 'ER', 'bed' => 'W-12', 'age' => 71,
        ]);

        $this->runBackfill();

        $row = DB::table('consultations')->where('id', $c->id)->first();
        $this->assertSame('2024-04-12', (string) $row->signoff_date);
        $this->assertSame('ER', $row->consultation_from);
        $this->assertSame('W-12', $row->bed);
        $this->assertSame(71, (int) $row->age);
        $this->assertSame($cardio->id, (int) $row->owning_specialty_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationBackfillTest
```

Expected failure: `failed to open stream: No such file or directory` for `database/migrations/2026_08_21_000300_backfill_consultation_ledger_state.php` (every test errors on `runBackfill()`).

- [ ] **Step 3: Write the implementation**

Create `laravel/database/migrations/2026_08_21_000300_backfill_consultation_ledger_state.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill of the ~1,283 historical consultations into the ledger model.
 *
 * STATUS
 *   signoff_date NOT NULL -> signed_off
 *   signoff_date IS NULL  -> ONGOING, *** never active ***
 *
 *   Why ongoing: mapping open legacy rows to `active` would fabricate a daily follow-up obligation
 *   for every one of them, so on launch day every team would open a huge false "must be seen today"
 *   list. This project already suffered exactly that with the handover backlog. `ongoing` states
 *   the truth — on the books, no daily commitment asserted — and teams promote to `active`
 *   deliberately, one consult at a time.
 *
 * OWNING SPECIALTY
 *   Resolved by case-insensitive match of `to_service` against `specialties.name`, restricted to
 *   INTERNAL specialties (is_external = 0) — the same shape as the precedent data-fix migration
 *   2026_06_11_000002_resolve_numeric_consultation_to_service. External services and free text
 *   (e.g. "Some Outside Clinic") match nothing and stay NULL: the Unassigned bucket. A specialty is
 *   NEVER guessed — a wrong owner would hide a patient from the team that is actually seeing them.
 *
 * TIMESTAMPS
 *   requested_at / signed_off_at are LEFT NULL. Both legacy columns are DATE-only; deriving a
 *   datetime would manufacture precision that never existed. Turnaround metrics cover cutover
 *   onward and must exclude NULLs explicitly.
 *
 * Raw query-builder / raw SQL is used deliberately: it bypasses the model's SoftDeletes global
 * scope, so soft-deleted historical rows are backfilled too and stay consistent if restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('consultations')->whereNotNull('signoff_date')->update(['status' => 'signed_off']);
        DB::table('consultations')->whereNull('signoff_date')->update(['status' => 'ongoing']);

        DB::statement("
            UPDATE consultations c
            JOIN specialties s
              ON LOWER(TRIM(s.name)) = LOWER(TRIM(c.to_service))
             AND s.is_external = 0
            SET c.owning_specialty_id = s.id
            WHERE c.owning_specialty_id IS NULL
              AND c.to_service IS NOT NULL
              AND TRIM(c.to_service) <> ''
        ");
    }

    public function down(): void
    {
        // Data backfill — intentionally NOT reversed here. The columns it writes did not exist
        // before 2026_08_21_000100; rolling THAT migration back drops them entirely, which is the
        // real reversal. Re-setting status to its default here would be a destructive no-value
        // write over rows the team may already have moved by hand.
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan migrate
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationBackfillTest
```

Expected: `Tests: 6 passed`.

- [ ] **Step 5: Commit**

```
git add laravel/database/migrations/2026_08_21_000300_backfill_consultation_ledger_state.php laravel/tests/Feature/ConsultationBackfillTest.php
git commit -m "feat(consultations): backfill legacy rows to ongoing/signed_off and resolve owning specialty, leaving real timestamps NULL"
```

---

### Task 7: Migration — add `users.can_coordinate_consultations`

**Files:**
- Create: `laravel/database/migrations/2026_08_21_000400_add_can_coordinate_consultations_to_users.php`
- Modify: `laravel/tests/Feature/ConsultationLedgerSchemaTest.php`

- [ ] **Step 1: Write the failing test**

Add these two methods to `laravel/tests/Feature/ConsultationLedgerSchemaTest.php`, immediately before the file's final closing `}`:

```php
    // ---- Task 7: the coordinator capability flag ------------------------------------------------

    public function test_users_have_a_coordinator_flag_that_defaults_off(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'can_coordinate_consultations'));

        $u = \App\Models\User::create([
            'username' => 'w1_default_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'W1 Default', 'password' => 'secret12345',
            'role' => \App\Models\User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => \App\Support\Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);

        // default OFF: granting coordination is an explicit administrative act
        $this->assertSame(0, (int) DB::table('users')->where('id', $u->id)->value('can_coordinate_consultations'));
    }

    public function test_existing_users_are_unaffected_by_the_new_flag(): void
    {
        // a row written the way the legacy importer writes users (no capability columns at all)
        $id = DB::table('users')->insertGetId([
            'username' => 'w1_legacy_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'W1 Legacy', 'password' => 'x', 'role' => 4, 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, (int) DB::table('users')->where('id', $id)->value('can_coordinate_consultations'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerSchemaTest
```

Expected failure: `Failed asserting that false is true.` on `Schema::hasColumn('users', 'can_coordinate_consultations')`.

- [ ] **Step 3: Write the implementation**

Create `laravel/database/migrations/2026_08_21_000400_add_can_coordinate_consultations_to_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The consultation-coordinator capability — a fifth per-user flag alongside the existing
 * can_assign / can_add / can_manage / can_modify (2026_06_08_120001_extend_users_table).
 *
 * A coordinator may book a consult into ANY specialty, see all consults and modify all of them.
 * They deliberately may NOT sign off, delete, or reverse a sign-off: signing off asserts the
 * clinical work is done, and that stays with the owning consultant / can_manage / admin.
 *
 * Default FALSE — nobody gains anything when this migration runs; the flag is granted per user in
 * Control -> Users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_coordinate_consultations')->default(false)->after('can_modify');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_coordinate_consultations');
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan migrate
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerSchemaTest
```

Expected: `Tests: 8 passed`.

- [ ] **Step 5: Commit**

```
git add laravel/database/migrations/2026_08_21_000400_add_can_coordinate_consultations_to_users.php laravel/tests/Feature/ConsultationLedgerSchemaTest.php
git commit -m "feat(users): add can_coordinate_consultations capability flag (default off)"
```

---

### Task 8: User permission methods + the Control → Users coordinator control

Task 9's `Consultation::scopeVisibleTo()` calls `User::canCoordinateConsultations()`, so the User
layer lands first.

**Files:**
- Modify: `laravel/app/Models/User.php`
- Modify: `laravel/app/Http/Controllers/ControlController.php`
- Modify: `laravel/resources/js/Pages/Control/Index.vue`
- Test: `laravel/tests/Feature/ConsultationCoordinatorTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationCoordinatorTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Consultation ledger W1, Task 8 — the coordinator capability and the permission predicates.
 *
 * Two guarantees are load-bearing and pinned here:
 *   1. Observers are read-only BEFORE any capability flag is consulted — an Observer carrying the
 *      coordinator flag coordinates nothing.
 *   2. A coordinator does NOT gain sign-off. canManageConsultation() is the sign-off gate and stays
 *      admin / can_manage / owning consultant only.
 */
class ConsultationCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'w1c_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W1 Coord User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    // ---- the predicate ---------------------------------------------------------------------------

    public function test_who_may_coordinate(): void
    {
        $this->assertTrue($this->admin()->canCoordinateConsultations(), 'admins coordinate implicitly');
        $this->assertTrue($this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true])->canCoordinateConsultations());
        $this->assertFalse($this->user(User::ROLE_CONSULTANT)->canCoordinateConsultations(), 'the flag is off by default');

        // observer-first: the read-only guarantee is checked BEFORE the flag
        $this->assertFalse(
            $this->user(User::ROLE_OBSERVER, ['can_coordinate_consultations' => true])->canCoordinateConsultations(),
            'Observers are read-only regardless of capability flags'
        );
    }

    public function test_visibility_and_modify_predicates(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $nephro = Specialty::create(['name' => 'Nephrology', 'is_subspecialty' => true, 'is_external' => false]);

        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $nephroUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);
        $observer = $this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id]);

        $c = Consultation::create([
            'mrn' => '79000001', 'patient_name' => 'Scoped Pt', 'consultation_date' => '2024-05-01',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $cardio->id, 'indication' => [],
            'entered_by' => $coordinator->id,
        ]);

        $this->assertTrue($cardioUser->canSeeConsultation($c));
        $this->assertFalse($nephroUser->canSeeConsultation($c), 'another team must not see this book');
        $this->assertTrue($coordinator->canSeeConsultation($c));
        $this->assertTrue($this->admin()->canSeeConsultation($c));
        $this->assertFalse($observer->canSeeConsultation($c), 'the consultations workspace is closed to Observers');

        $this->assertTrue($cardioUser->canModifyConsultation($c));
        $this->assertTrue($coordinator->canModifyConsultation($c), 'coordinators modify all');
        $this->assertFalse($nephroUser->canModifyConsultation($c));
        $this->assertFalse($observer->canModifyConsultation($c));
    }

    public function test_a_coordinator_does_not_gain_sign_off(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true, 'can_manage' => false]);

        $c = Consultation::create([
            'mrn' => '79000002', 'patient_name' => 'Signoff Pt', 'consultation_date' => '2024-05-02',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $cardio->id,
            'consultant_id' => $owner->id, 'indication' => [],
        ]);

        $this->assertTrue($owner->canManageConsultation($c));
        $this->assertFalse($coordinator->canManageConsultation($c),
            'coordination must never become authority to declare the clinical work finished');

        // and the live endpoint agrees
        $this->actingAs($coordinator)->post("/consultations/{$c->id}/signoff")->assertForbidden();
        $this->assertNull($c->fresh()->signoff_date);
    }

    // ---- Control -> Users wiring -----------------------------------------------------------------

    public function test_admin_can_grant_and_revoke_the_flag_in_control(): void
    {
        $target = $this->user(User::ROLE_REGISTRAR);

        $payload = [
            'username' => $target->username, 'full_name' => 'Coord Target', 'email' => null,
            'role' => User::ROLE_REGISTRAR, 'active' => 1, 'on_service' => 0, 'specialty_id' => null,
            'can_assign' => 0, 'can_add' => 0, 'can_manage' => 0, 'can_modify' => 0,
        ];

        $this->actingAs($this->admin())
            ->put("/control/users/{$target->id}", [...$payload, 'can_coordinate_consultations' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue((bool) $target->fresh()->can_coordinate_consultations);

        $this->actingAs($this->admin())
            ->put("/control/users/{$target->id}", [...$payload, 'can_coordinate_consultations' => 0])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse((bool) $target->fresh()->can_coordinate_consultations);
    }

    public function test_a_payload_without_the_flag_leaves_it_untouched(): void
    {
        // pins the 'sometimes' rule: pre-existing callers (and the ~2 older tests that post the old
        // capability set) must never silently REVOKE coordination by omission
        $target = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);

        $this->actingAs($this->admin())->put("/control/users/{$target->id}", [
            'username' => $target->username, 'full_name' => 'Coord Target', 'email' => null,
            'role' => User::ROLE_REGISTRAR, 'active' => 1, 'on_service' => 0, 'specialty_id' => null,
            'can_assign' => 0, 'can_add' => 0, 'can_manage' => 0, 'can_modify' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue((bool) $target->fresh()->can_coordinate_consultations);
    }

    public function test_control_ships_the_flag_to_the_users_table(): void
    {
        $target = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);

        $this->actingAs($this->admin())->get('/control')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('users', fn ($users) => collect($users)->firstWhere('id', $target->id)['can']['coordinate'] === true));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationCoordinatorTest
```

Expected failure: `Error: Call to undefined method App\Models\User::canCoordinateConsultations()`.

- [ ] **Step 3: Write the implementation**

**3a.** In `laravel/app/Models/User.php`, add the four capability booleans to the cast list. Replace:

```php
            'can_manage' => 'boolean',
            'can_modify' => 'boolean',
            'role' => 'integer',
```

with:

```php
            'can_manage' => 'boolean',
            'can_modify' => 'boolean',
            'can_coordinate_consultations' => 'boolean',
            'role' => 'integer',
```

**3b.** In the same file, insert the three new predicates immediately after `canManageConsultation()` (i.e. after the line `return $this->isAdmin() || $this->can_manage || (int) $c->consultant_id === (int) $this->id;` and its closing `}`, before `public function mfaEnabled()`):

```php
    /**
     * Consultation COORDINATOR (W1): may book a consult into any specialty, see every consult and
     * modify every consult. Deliberately NOT a sign-off grant — see canManageConsultation(), which
     * remains the sign-off gate (owning consultant / can_manage / admin). Observers are rejected
     * FIRST, so the read-only guarantee can never be bought with a capability flag.
     */
    public function canCoordinateConsultations(): bool
    {
        if ($this->isObserver()) {
            return false;
        }

        return $this->isAdmin() || (bool) $this->can_coordinate_consultations;
    }

    /**
     * May this user SEE a consultation? Ownership is `owning_specialty_id` + `consultant_id` and is
     * independent of `entered_by` — but you always keep sight of a consult you entered or that is
     * assigned to you, otherwise a registrar who books one loses it the moment they save.
     * Unassigned rows (NULL owning specialty) are visible to admins and coordinators only.
     * Observers are excluded: the consultations workspace is a clinical-role page (J2-12).
     */
    public function canSeeConsultation(Consultation $c): bool
    {
        if ($this->isObserver()) {
            return false;
        }
        if ($this->isAdmin() || $this->canCoordinateConsultations()) {
            return true;
        }
        if ($this->specialty_id !== null && (int) $c->owning_specialty_id === (int) $this->specialty_id) {
            return true;
        }

        return (int) $c->consultant_id === (int) $this->id || (int) $c->entered_by === (int) $this->id;
    }

    /**
     * May this user EDIT a consultation? Coordinators modify all; everyone else may edit what they
     * can see. NOTE: W1 defines this predicate but deliberately does NOT wire it into
     * ConsultationsController::update(), which keeps its legacy-open gate (J1-10: legacy modify had
     * no ownership check, pinned by Round5J1Test). W2 flips update() over to this predicate.
     */
    public function canModifyConsultation(Consultation $c): bool
    {
        if ($this->isObserver()) {
            return false;
        }

        return $this->isAdmin() || $this->canCoordinateConsultations() || $this->canSeeConsultation($c);
    }

```

**3c.** In `laravel/app/Http/Controllers/ControlController.php`, ship the flag to the users table. Replace:

```php
                'can' => ['assign' => (bool) $u->can_assign, 'add' => (bool) $u->can_add, 'manage' => (bool) $u->can_manage, 'modify' => (bool) $u->can_modify],
```

with:

```php
                'can' => ['assign' => (bool) $u->can_assign, 'add' => (bool) $u->can_add, 'manage' => (bool) $u->can_manage, 'modify' => (bool) $u->can_modify,
                    'coordinate' => (bool) $u->can_coordinate_consultations],
```

**3d.** In the same controller, in `updateUser()`, replace:

```php
            'can_modify' => ['required', 'boolean'],
        ]);
```

with:

```php
            'can_modify' => ['required', 'boolean'],
            // 'sometimes', not 'required': a payload that predates this flag (older callers and the
            // existing Control tests) must leave the grant UNCHANGED rather than silently revoke it.
            // The Control form always sends it, so the admin UI is unaffected.
            'can_coordinate_consultations' => ['sometimes', 'boolean'],
        ]);
```

**3e.** Still in `updateUser()`, add the flag to the audit diff field list. Replace:

```php
        $fields = ['username', 'full_name', 'email', 'role', 'active', 'on_service',
            'specialty_id', 'can_assign', 'can_add', 'can_manage', 'can_modify'];
```

with:

```php
        $fields = ['username', 'full_name', 'email', 'role', 'active', 'on_service',
            'specialty_id', 'can_assign', 'can_add', 'can_manage', 'can_modify',
            'can_coordinate_consultations'];
```

**3f.** In `laravel/resources/js/Pages/Control/Index.vue`, add the field to the edit form. Replace:

```js
const uForm = useForm({ username: '', full_name: '', email: '', role: 5, active: true, on_service: false, specialty_id: '', can_assign: false, can_add: false, can_manage: false, can_modify: false });
```

with:

```js
const uForm = useForm({ username: '', full_name: '', email: '', role: 5, active: true, on_service: false, specialty_id: '', can_assign: false, can_add: false, can_manage: false, can_modify: false, can_coordinate_consultations: false });
```

**3g.** In the same file, load it when opening a user. Replace:

```js
    uForm.can_assign = u.can.assign; uForm.can_add = u.can.add; uForm.can_manage = u.can.manage; uForm.can_modify = u.can.modify;
```

with:

```js
    uForm.can_assign = u.can.assign; uForm.can_add = u.can.add; uForm.can_manage = u.can.manage; uForm.can_modify = u.can.modify;
    uForm.can_coordinate_consultations = !!u.can.coordinate;
```

**3h.** In the same file, add the instant-filter option. Replace:

```html
                    <option value="">Any capability</option><option value="assign">Can assign</option><option value="add">Can add</option><option value="manage">Can manage</option><option value="modify">Can modify</option>
```

with:

```html
                    <option value="">Any capability</option><option value="assign">Can assign</option><option value="add">Can add</option><option value="manage">Can manage</option><option value="modify">Can modify</option><option value="coordinate">Can coordinate consults</option>
```

**3i.** In the same file, add the checkbox to the capability grid. Replace:

```html
                        <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="uForm.can_modify" class="rounded text-brand-600" /> Can modify</label>
                    </div>
```

with:

```html
                        <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="uForm.can_modify" class="rounded text-brand-600" /> Can modify</label>
                        <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="uForm.can_coordinate_consultations" class="rounded text-brand-600" /> Can coordinate consults</label>
                    </div>
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationCoordinatorTest
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=AuditDiffTest
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ResidualR2Test
```

Expected: `Tests: 6 passed` for `ConsultationCoordinatorTest`, and both existing suites still `OK` (they post the old capability set — the `sometimes` rule is what keeps them green).

Then rebuild the committed front-end bundle (a `resources/` file changed), in this order:

```
cd laravel && npx vite build
cd laravel && node scripts/check-source-allowlist.mjs --write
cd laravel && node scripts/check-source-allowlist.mjs
cd laravel && node scripts/contrast.mjs
cd laravel && npx vitest run
```

Expected: `check-source-allowlist.mjs` prints `PASS`, `contrast.mjs` prints `PASS`, Vitest reports all suites passing.

- [ ] **Step 5: Commit**

```
git add laravel/app/Models/User.php laravel/app/Http/Controllers/ControlController.php laravel/resources/js/Pages/Control/Index.vue laravel/public/build laravel/scripts/source-allowlist.json laravel/tests/Feature/ConsultationCoordinatorTest.php
git commit -m "feat(consultations): coordinator capability - User predicates + Control -> Users grant"
```

---

### Task 9: Model layer — Consultation status constants, relations, scopes, and `ConsultationFollowup`

**Files:**
- Create: `laravel/app/Models/ConsultationFollowup.php`
- Modify: `laravel/app/Models/Consultation.php`
- Test: `laravel/tests/Feature/ConsultationLedgerModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationLedgerModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Consultation ledger W1, Task 9 — the model layer: status vocabulary, the new relations, the two
 * query scopes, and the append-only ConsultationFollowup model.
 */
class ConsultationLedgerModelTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'w1m_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W1 Model User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    public function test_status_vocabulary(): void
    {
        $this->assertSame('new', Consultation::STATUS_NEW);
        $this->assertSame('active', Consultation::STATUS_ACTIVE);
        $this->assertSame('ongoing', Consultation::STATUS_ONGOING);
        $this->assertSame('signed_off', Consultation::STATUS_SIGNED_OFF);
    }

    public function test_the_existing_soft_delete_and_cache_busting_behaviour_survives(): void
    {
        $this->assertContains(SoftDeletes::class, class_uses_recursive(Consultation::class));

        $c = Consultation::create([
            'mrn' => '76000001', 'patient_name' => 'Soft Pt', 'consultation_date' => '2024-06-01', 'indication' => [],
        ]);
        $c->delete();

        $this->assertNull(Consultation::find($c->id));
        $this->assertNotNull(Consultation::withTrashed()->find($c->id));
    }

    public function test_relations_resolve(): void
    {
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $signer = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Signer']);
        $p = Patient::create(['mrn' => '76000002', 'name' => 'Rel Pt']);
        $a = Admission::create(['patient_id' => $p->id, 'admit_date' => '2026-08-01',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);

        $c = Consultation::create([
            'mrn' => '76000002', 'patient_name' => 'Rel Pt', 'consultation_date' => '2026-08-02',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $spec->id, 'indication' => [],
            'signed_off_by' => $signer->id, 'admission_id' => $a->id,
        ]);
        ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => '2026-08-03',
            'note' => 'seen on round', 'author_id' => $signer->id]);

        $c = $c->fresh();
        $this->assertSame('Cardiology', $c->owningSpecialty->name);
        $this->assertSame($signer->id, $c->signedOffBy->id);
        $this->assertSame($a->id, $c->admission->id);
        $this->assertCount(1, $c->followups);
        $this->assertSame('seen on round', $c->followups->first()->note);
        $this->assertSame($signer->id, $c->followups->first()->author->id);
    }

    public function test_followup_rows_are_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('consultation_followups', 'updated_at'));

        $c = Consultation::create([
            'mrn' => '76000003', 'patient_name' => 'Append Pt', 'consultation_date' => '2026-08-04', 'indication' => [],
        ]);
        $f = ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => '2026-08-04']);

        $this->assertNotNull($f->created_at, 'created_at is stamped automatically');
        $this->assertNull($f->updated_at, 'the model must not manage an updated_at column');
        $this->assertSame('2026-08-04', $f->followup_date->toDateString());
    }

    public function test_open_scope_excludes_signed_off_rows(): void
    {
        foreach ([Consultation::STATUS_NEW, Consultation::STATUS_ACTIVE, Consultation::STATUS_ONGOING, Consultation::STATUS_SIGNED_OFF] as $i => $status) {
            Consultation::create([
                'mrn' => '7600001' . $i, 'patient_name' => 'Open Pt ' . $i,
                'consultation_date' => '2026-08-05', 'indication' => [], 'status' => $status,
            ]);
        }

        $this->assertSame(3, Consultation::open()->count());
        $this->assertSame(0, Consultation::open()->where('status', Consultation::STATUS_SIGNED_OFF)->count());
    }

    public function test_visible_to_scopes_by_specialty_and_opens_up_for_coordinators(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $nephro = Specialty::create(['name' => 'Nephrology', 'is_subspecialty' => true, 'is_external' => false]);

        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);
        $admin = $this->user(User::ROLE_ADMIN);

        $base = ['consultation_date' => '2026-08-06', 'indication' => []];
        Consultation::create([...$base, 'mrn' => '76000020', 'patient_name' => 'Cardio Pt', 'owning_specialty_id' => $cardio->id]);
        Consultation::create([...$base, 'mrn' => '76000021', 'patient_name' => 'Nephro Pt', 'owning_specialty_id' => $nephro->id]);
        Consultation::create([...$base, 'mrn' => '76000022', 'patient_name' => 'Unassigned Pt', 'owning_specialty_id' => null]);
        Consultation::create([...$base, 'mrn' => '76000023', 'patient_name' => 'Assigned To Me', 'owning_specialty_id' => $nephro->id, 'consultant_id' => $cardioUser->id]);

        // own specialty + anything assigned to me; NOT another team's book, NOT the Unassigned bucket
        $this->assertSame(
            ['76000020', '76000023'],
            Consultation::visibleTo($cardioUser)->orderBy('mrn')->pluck('mrn')->all()
        );
        $this->assertSame(4, Consultation::visibleTo($coordinator)->count());
        $this->assertSame(4, Consultation::visibleTo($admin)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerModelTest
```

Expected failure: `Error: Undefined constant App\Models\Consultation::STATUS_NEW`, and `Error: Class "App\Models\ConsultationFollowup" not found`.

- [ ] **Step 3: Write the implementation**

Replace the whole of `laravel/app/Models/Consultation.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consultation extends Model
{
    // Phase 4 — Item 1: soft-delete (delete() sets deleted_at; global scope hides trashed rows).
    use SoftDeletes;

    /**
     * Ledger states (W1). W1 only STORES them — the interactive four-state workflow arrives in W2.
     *   new        — logged, not yet seen
     *   active     — the team rounds on it daily and ticks it off
     *   ongoing    — on the books, no daily commitment asserted (also where every OPEN legacy row
     *                was backfilled, so launch day does not invent a worklist)
     *   signed_off — closed with a recorded response
     */
    public const STATUS_NEW = 'new';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_SIGNED_OFF = 'signed_off';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Consultations feed the dashboard's 6-month consults chart (heavy tier) — bust on write.
        static::saved(fn () => \App\Support\DashboardCache::bust());
        static::deleted(fn () => \App\Support\DashboardCache::bust());
    }

    protected function casts(): array
    {
        return [
            'consultation_date' => 'date',
            'signoff_date' => 'date',
            'indication' => 'array',
            // W1: the REAL timestamps. NULL on every historical row — never fabricated from a DATE.
            'requested_at' => 'datetime',
            'signed_off_at' => 'datetime',
            'response_followup_needed' => 'boolean',
        ];
    }

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function consultant(): BelongsTo { return $this->belongsTo(User::class, 'consultant_id'); }
    public function enteredBy(): BelongsTo { return $this->belongsTo(User::class, 'entered_by'); }

    /** W1 ledger relations. */
    public function followups(): HasMany { return $this->hasMany(ConsultationFollowup::class); }
    public function owningSpecialty(): BelongsTo { return $this->belongsTo(Specialty::class, 'owning_specialty_id'); }
    public function signedOffBy(): BelongsTo { return $this->belongsTo(User::class, 'signed_off_by'); }
    public function admission(): BelongsTo { return $this->belongsTo(Admission::class); }

    public function scopeActive(Builder $q): Builder { return $q->whereNull('signoff_date'); }

    /** Everything still on the books — the three non-closed states. */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', '<>', self::STATUS_SIGNED_OFF);
    }

    /**
     * Specialty scoping — the ONE visibility rule, mirroring User::canSeeConsultation() so the list
     * query and the per-row predicate can never drift apart.
     *
     * Admins and coordinators see everything (including the Unassigned bucket). Everyone else sees
     * their own specialty's book, plus any consult assigned to them or entered by them — ownership
     * is owning_specialty_id + consultant_id and is INDEPENDENT of entered_by, but you never lose
     * sight of a consult you personally booked. A user with no specialty_id and no coordinator flag
     * therefore sees only their own rows: unit-wide coordinators are given the flag deliberately.
     */
    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        if ($u->isAdmin() || $u->canCoordinateConsultations()) {
            return $q;
        }

        return $q->where(function (Builder $w) use ($u) {
            if ($u->specialty_id !== null) {
                $w->where('owning_specialty_id', $u->specialty_id);
            }
            $w->orWhere('consultant_id', $u->id)->orWhere('entered_by', $u->id);
        });
    }
}
```

Create `laravel/app/Models/ConsultationFollowup.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One daily tick on a consultation — the raw material for "seen 8 of 12 today" and for handover.
 *
 * APPEND-ONLY, like handover_revisions: the table has created_at and NO updated_at. We express that
 * with `const UPDATED_AT = null` (rather than $timestamps = false) so Eloquent still stamps
 * created_at automatically and nothing has to manage it by hand. A tick is a fact that happened; it
 * is appended, never edited. The DB's unique(consultation_id, followup_date) makes double-ticking a
 * single day impossible, which is what keeps completeness counts exact.
 */
class ConsultationFollowup extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'followup_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function consultation(): BelongsTo { return $this->belongsTo(Consultation::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerModelTest
```

Expected: `Tests: 6 passed`.

- [ ] **Step 5: Commit**

```
git add laravel/app/Models/Consultation.php laravel/app/Models/ConsultationFollowup.php laravel/tests/Feature/ConsultationLedgerModelTest.php
git commit -m "feat(consultations): ledger model layer - status constants, followup/owner/signer/admission relations, visibleTo + open scopes"
```

---

### Task 10: Enforce specialty scoping server-side (list + create)

This is the only task in W1 that changes behaviour a user could notice: the workspace list is now
scoped, and a non-coordinator can no longer book a consult into another team's book. It therefore
carries the two **deliberate** updates to existing tests.

**Files:**
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Modify: `laravel/app/Http/Requests/ConsultationRequest.php`
- Modify: `laravel/tests/Feature/TermOutOfUrlTest.php` (deliberate update)
- Modify: `laravel/tests/Feature/ResidualR3Test.php` (deliberate update)
- Test: `laravel/tests/Feature/ConsultationScopingTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationScopingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Consultation ledger W1, Task 10 — server-side specialty scoping.
 *
 * The list is scoped by Consultation::scopeVisibleTo(); creating into another team's book is
 * refused by ConsultationRequest unless the user is a coordinator or an admin. entered_by stays
 * immutable and session-sourced — it can never be set from the request payload.
 */
class ConsultationScopingTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'w1s_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W1 Scope User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    /** @return array{0: Specialty, 1: Specialty} */
    private function specialties(): array
    {
        return [
            Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]),
            Specialty::create(['name' => 'Nephrology', 'is_subspecialty' => true, 'is_external' => false]),
        ];
    }

    private function seedThreeBooks(Specialty $cardio, Specialty $nephro): void
    {
        $base = ['consultation_date' => '2026-08-10', 'indication' => [], 'current_location' => 'Ward'];
        Consultation::create([...$base, 'mrn' => '75000001', 'patient_name' => 'Cardio Pt',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $cardio->id]);
        Consultation::create([...$base, 'mrn' => '75000002', 'patient_name' => 'Nephro Pt',
            'to_service' => 'Nephrology', 'owning_specialty_id' => $nephro->id]);
        Consultation::create([...$base, 'mrn' => '75000003', 'patient_name' => 'Unassigned Pt',
            'to_service' => 'Some Outside Clinic', 'owning_specialty_id' => null]);
    }

    public function test_a_team_member_sees_only_their_own_book(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $this->seedThreeBooks($cardio, $nephro);

        $this->actingAs($this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]))
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultations.total', 1)
                ->where('consultations.data.0.mrn', '75000001')
                ->where('stats.total', 1));
    }

    public function test_a_coordinator_and_an_admin_see_every_book(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $this->seedThreeBooks($cardio, $nephro);

        $this->actingAs($this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]))
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.total', 3));

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.total', 3));
    }

    public function test_an_observer_is_still_refused_the_workspace(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $this->seedThreeBooks($cardio, $nephro);

        $this->actingAs($this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id, 'can_coordinate_consultations' => true]))
            ->get('/consultations')->assertForbidden();
    }

    // ---- create ----------------------------------------------------------------------------------

    private function payload(Specialty $target, User $receiving, array $overrides = []): array
    {
        $reason = ConsultationReason::create(['name' => 'W1 Scope Reason']);

        return array_merge([
            'mrn' => '75000100', 'patient_name' => 'New Consult Pt', 'age' => 55, 'bed' => 'W-3',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => $target->name,
            'consultant_id' => $receiving->id, 'indication' => [$reason->id],
        ], $overrides);
    }

    public function test_a_non_coordinator_cannot_create_into_another_specialty(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $nephroConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($cardioUser)->from('/consultations')
            ->post('/consultations', $this->payload($nephro, $nephroConsultant))
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('to_service');

        $this->assertSame(0, Consultation::count());
    }

    public function test_a_team_member_can_create_into_their_own_specialty_and_it_is_owned_and_stamped(): void
    {
        [$cardio] = $this->specialties();
        $cardioConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($cardioConsultant)
            ->post('/consultations', $this->payload($cardio, $cardioConsultant))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertSame($cardio->id, (int) $c->owning_specialty_id, 'the owning team is resolved at entry');
        $this->assertSame($cardioConsultant->id, (int) $c->entered_by);
        $this->assertNotNull($c->requested_at, 'rows created from cutover onward carry a REAL request time');
    }

    public function test_a_coordinator_may_create_into_any_specialty(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $nephroConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);

        $this->actingAs($coordinator)
            ->post('/consultations', $this->payload($nephro, $nephroConsultant))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertSame($nephro->id, (int) $c->owning_specialty_id);
        // ownership is owning_specialty_id + consultant_id and is INDEPENDENT of who typed it in
        $this->assertSame($nephroConsultant->id, (int) $c->consultant_id);
        $this->assertSame($coordinator->id, (int) $c->entered_by);
    }

    public function test_entered_by_can_never_be_spoofed_from_the_payload(): void
    {
        [$cardio] = $this->specialties();
        $cardioConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $someoneElse = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($cardioConsultant)
            ->post('/consultations', $this->payload($cardio, $cardioConsultant, [
                'entered_by' => $someoneElse->id,
                'owning_specialty_id' => 999,
                'status' => Consultation::STATUS_SIGNED_OFF,
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertSame($cardioConsultant->id, (int) $c->entered_by, 'entered_by is session-sourced and immutable');
        $this->assertSame($cardio->id, (int) $c->owning_specialty_id, 'the owner is resolved server-side, not accepted');
        $this->assertSame(Consultation::STATUS_NEW, $c->status, 'status is not settable at create time');
    }

    public function test_an_external_or_free_text_service_stays_creatable_and_unassigned(): void
    {
        [$cardio] = $this->specialties();
        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $payload = $this->payload($cardio, $cardioUser, ['to_service' => 'Some Outside Clinic', 'consultant_id' => null]);

        $this->actingAs($cardioUser)->post('/consultations', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(Consultation::first()->owning_specialty_id, 'external referrals have no IM owner');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationScopingTest
```

Expected failure: `Failed asserting that 3 matches expected 1.` on `consultations.total` (the list is still unscoped), and `Session is missing expected key [errors]` on the cross-specialty create.

- [ ] **Step 3: Write the implementation**

**3a.** In `laravel/app/Http/Controllers/ConsultationsController.php`, scope the list and its counters. Replace:

```php
        $consultations = Consultation::query()
            ->with('consultant:id,full_name,name')
```

with:

```php
        // W1: specialty scoping is enforced HERE, server-side. The UI never decides who sees what.
        $viewer = Auth::user();

        $consultations = Consultation::query()
            ->visibleTo($viewer)
            ->with('consultant:id,full_name,name')
```

**3b.** In the same method, replace the whole `'stats' => [...]` block:

```php
            'stats' => [
                'active' => Consultation::whereNull('signoff_date')->count(),
                'total' => Consultation::count(),
                // personal counter for consultant-role viewers (K1-13): own active out of total active
                'mine_active' => Consultation::whereNull('signoff_date')->where('consultant_id', Auth::id())->count(),
            ],
```

with:

```php
            'stats' => [
                // every counter is scoped the same way as the list — a headline the viewer cannot
                // drill into would be a lie about their own book
                'active' => Consultation::visibleTo($viewer)->whereNull('signoff_date')->count(),
                'total' => Consultation::visibleTo($viewer)->count(),
                // personal counter for consultant-role viewers (K1-13): own active out of total active
                'mine_active' => Consultation::visibleTo($viewer)->whereNull('signoff_date')
                    ->where('consultant_id', Auth::id())->count(),
            ],
```

**3c.** In the same controller, resolve the owning specialty and stamp the real request time on create. Replace:

```php
        $patient = Patient::where('mrn', $data['mrn'])->first();
        $c = Consultation::create([
            ...$data,
            'patient_id' => $patient?->id,
            'indication' => $data['indication'] ?? [],
            'entered_by' => Auth::id(),                  // session-sourced
        ]);
```

with:

```php
        $patient = Patient::where('mrn', $data['mrn'])->first();
        // W1: the owning team is RESOLVED server-side from to_service (internal specialties only).
        // An external/free-text service resolves to NULL — the Unassigned bucket. Never guessed,
        // and never accepted from the payload.
        $c = Consultation::create([
            ...$data,
            'patient_id' => $patient?->id,
            'owning_specialty_id' => self::resolveOwningSpecialtyId($data['to_service'] ?? null),
            'requested_at' => now(),                     // REAL request time — cutover onward only
            'indication' => $data['indication'] ?? [],
            'entered_by' => Auth::id(),                  // session-sourced, immutable
        ]);
```

**3d.** Still in `ConsultationsController`, add the resolver as the last method of the class (immediately before the final closing `}` of the file):

```php
    /**
     * Map a `to_service` name onto the owning IM subspecialty (case-insensitive, internal only) —
     * the same matching rule as the W1 backfill migration, so a row created today and a row
     * backfilled yesterday land in the same book. Anything unmatched (external services, free text)
     * returns NULL: Unassigned. Shared with ConsultationRequest's own-specialty rule.
     */
    public static function resolveOwningSpecialtyId(?string $toService): ?int
    {
        $wanted = mb_strtolower(trim((string) $toService));
        if ($wanted === '') {
            return null;
        }

        $match = Specialty::where('is_external', false)->get(['id', 'name'])
            ->first(fn (Specialty $s) => mb_strtolower(trim((string) $s->name)) === $wanted);

        return $match?->id;
    }
```

**3e.** In `laravel/app/Http/Requests/ConsultationRequest.php`, refuse a cross-specialty create. Replace:

```php
            'to_service' => ['required', 'string', 'max:128'],
```

with:

```php
            'to_service' => array_merge(['required', 'string', 'max:128'], $this->ownSpecialtyRule()),
```

**3f.** In the same file, add the rule builder as a new private method placed immediately after `rules()` (before `consultationDateRules()`):

```php
    /**
     * W1 scoping: a consult belongs to the team it is booked into, so a plain clinical user may
     * only create into their OWN specialty. Coordinators (can_coordinate_consultations) and admins
     * may book into any specialty — that is exactly what the capability is for.
     *
     * Deliberately CREATE-only. UPDATE keeps the legacy-open modify semantics (J1-10: legacy modify
     * carried no ownership check, pinned by Round5J1Test), and moving a consult between teams gets
     * its own explicit reassign action in W2 rather than riding on a generic edit.
     *
     * An unmatched / external / free-text service is unowned and stays creatable by anyone: those
     * referrals belong to no IM team and land in the Unassigned bucket.
     *
     * @return array<int, \Closure>
     */
    private function ownSpecialtyRule(): array
    {
        if ($this->route('consultation') !== null) {
            return [];   // UPDATE — unchanged by W1
        }

        $user = $this->user();
        if ($user->isAdmin() || $user->canCoordinateConsultations()) {
            return [];
        }

        return [function (string $attribute, mixed $value, \Closure $fail) use ($user) {
            $targetId = \App\Http\Controllers\ConsultationsController::resolveOwningSpecialtyId((string) $value);
            if ($targetId === null) {
                return;   // external / free-text service — unowned
            }
            if ((int) $user->specialty_id !== $targetId) {
                $fail('You may only create consultations for your own specialty. Ask a consultation coordinator to book this one.');
            }
        }];
    }

```

**3g. Deliberate update to `laravel/tests/Feature/TermOutOfUrlTest.php`.** The search test acts as a
consultant with no specialty over two consults with no owning specialty; under scoping that viewer
correctly sees nothing. Give the viewer a team and put the two consults in that team's book — the
behaviour under test (the term travelling in a POST body) is unchanged. Replace:

```php
        $consultant = $this->user(User::ROLE_CONSULTANT);
        Consultation::create(['mrn' => '70000006', 'patient_name' => 'Cons Findme', 'consultation_date' => now()->toDateString(),
            'indication' => [], 'current_location' => 'Ward']);
        Consultation::create(['mrn' => '70000007', 'patient_name' => 'Cons Other', 'consultation_date' => now()->toDateString(),
            'indication' => [], 'current_location' => 'Ward']);
```

with:

```php
        // W1 scoping: the workspace is scoped to the viewer's specialty, so this fixture now states
        // the team explicitly. What the test pins — the search TERM riding in a POST body, never a
        // URL — is unchanged.
        $cardio = \App\Models\Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $consultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        Consultation::create(['mrn' => '70000006', 'patient_name' => 'Cons Findme', 'consultation_date' => now()->toDateString(),
            'indication' => [], 'current_location' => 'Ward', 'owning_specialty_id' => $cardio->id]);
        Consultation::create(['mrn' => '70000007', 'patient_name' => 'Cons Other', 'consultation_date' => now()->toDateString(),
            'indication' => [], 'current_location' => 'Ward', 'owning_specialty_id' => $cardio->id]);
```

Check the helper signature at the top of `TermOutOfUrlTest.php` before applying: if its `user()`
helper does not accept a second `array $extra` argument, add the specialty with
`tap($this->user(User::ROLE_CONSULTANT), fn ($u) => $u->update(['specialty_id' => $cardio->id]))`
instead.

**3h. Deliberate update to `laravel/tests/Feature/ResidualR3Test.php`.** Its "full legacy payload"
test posts as a **registrar with no specialty** into the internal specialty *Cardiology* — exactly
the case W1 now routes through the coordinator capability. Give that registrar the flag; the
assertion (a complete legacy payload saves) is unchanged. Replace:

```php
        $this->actingAs($this->user(User::ROLE_REGISTRAR))
            ->post('/consultations', $this->consultationPayload())
            ->assertRedirect()->assertSessionHasNoErrors();
```

with:

```php
        // W1: booking into a specialty you do not belong to is the COORDINATOR capability. A unit
        // registrar who books consults for every team is precisely who gets the flag.
        $this->actingAs($this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]))
            ->post('/consultations', $this->consultationPayload())
            ->assertRedirect()->assertSessionHasNoErrors();
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationScopingTest
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=TermOutOfUrlTest
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ResidualR3Test
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=Round5J1Test
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=Round5J2Test
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=Round9L1Test
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=Round4H1Test
```

Expected: `Tests: 8 passed` for `ConsultationScopingTest`, and every listed existing suite green
(these are where the ~37 consultation authorization methods live).

Then the whole backend suite, to prove nothing else moved:

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test
```

Expected: all tests pass — no failures, no errors.

- [ ] **Step 5: Commit**

```
git add laravel/app/Http/Controllers/ConsultationsController.php laravel/app/Http/Requests/ConsultationRequest.php laravel/tests/Feature/ConsultationScopingTest.php laravel/tests/Feature/TermOutOfUrlTest.php laravel/tests/Feature/ResidualR3Test.php
git commit -m "feat(consultations): scope the workspace to the viewer's specialty and refuse cross-specialty creates for non-coordinators"
```
### Task 10a: Re-link the *new* id-bearing columns after a legacy rebuild

> **Why this task exists.** Task 1 established that skipping the truncate is **not** enough: `legacy:import`
> re-seeds `patients` and `users` ids, so preserved consultations keep ids that now belong to **different
> people**. Task 1 fixed that for `patient_id`, `consultant_id` and `entered_by`. Task 4 then added three more
> id-bearing columns that the same rebuild invalidates. Miss this and a preserved consultation silently points
> at the wrong specialty, the wrong stay, or the wrong signing clinician — worse than deletion, because it
> looks correct.
>
> - `owning_specialty_id` — `specialties` is truncated and re-inserted. Internal specialties are re-inserted
>   with their legacy id, but **external services get fresh auto-increment ids**, so a stored id can point at a
>   different service after a rebuild. Re-resolve by **name**, which is stable.
> - `admission_id` — `admissions` is rebuilt wholesale. Only rows carrying a `legacy_id` can be found again;
>   an app-created admission does not survive, so the honest answer is NULL.
> - `signed_off_by` — a user id, needing the same `legacy_id` remap as `consultant_id`.

**Files:**
- Modify: `laravel/app/Console/Commands/LegacyImport.php` (the `captureConsultationLinks()` and `relinkPreservedConsultations()` methods added in Task 1)
- Test: `laravel/tests/Feature/LegacyImportTest.php`

- [ ] **Step 1: Write the failing test**

Add to `laravel/tests/Feature/LegacyImportTest.php`. It inherits this class's harness on purpose — `LegacyImportTest`
breaks out of `RefreshDatabase`'s transaction (TRUNCATE forces an implicit COMMIT) and repairs state in
`tearDown()`. Do not re-create that harness in a new file.

```php
public function test_preserved_consultations_relink_specialty_admission_and_signer(): void
{
    $this->seedLegacySchema();                       // existing helper in this class
    DB::table('settings')->update(['consultations_source_of_truth' => true]);

    // An EXTERNAL specialty: re-inserted with a FRESH auto-increment id by importReference(),
    // so its id before and after the import differ — the case a naive id-carry would corrupt.
    $extId = DB::table('specialties')->insertGetId([
        'name' => 'Dietary', 'is_subspecialty' => true, 'is_external' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $signer = User::create([
        'username' => 'w1a_signer', 'name' => 'W1a Signer', 'email' => 'w1a.signer@test.local',
        'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
        'legacy_id' => 4242,                          // a LEGACY user — its id WILL be re-seeded
    ]);
    $patient = Patient::create(['mrn' => '77001122', 'name' => 'Relink Pt']);
    $legacyAdm = Admission::create([
        'patient_id' => $patient->id, 'admit_date' => '2024-03-01', 'legacy_id' => 990001,
    ]);
    $appAdm = Admission::create([
        'patient_id' => $patient->id, 'admit_date' => '2024-03-02',   // no legacy_id — cannot survive
    ]);

    $withLegacyAdm = Consultation::create([
        'mrn' => '77001122', 'patient_id' => $patient->id, 'patient_name' => 'Relink Pt',
        'indication' => [], 'to_service' => 'Dietary', 'owning_specialty_id' => $extId,
        'admission_id' => $legacyAdm->id, 'signed_off_by' => $signer->id,
        'signoff_date' => '2024-03-05', 'status' => Consultation::STATUS_SIGNED_OFF,
    ]);
    $withAppAdm = Consultation::create([
        'mrn' => '77001122', 'patient_id' => $patient->id, 'patient_name' => 'Relink Pt',
        'indication' => [], 'to_service' => 'Dietary', 'owning_specialty_id' => $extId,
        'admission_id' => $appAdm->id, 'status' => Consultation::STATUS_ONGOING,
    ]);

    $this->artisan('legacy:import')->assertExitCode(0);

    $a = DB::table('consultations')->where('id', $withLegacyAdm->id)->first();
    $b = DB::table('consultations')->where('id', $withAppAdm->id)->first();

    // specialty re-resolved BY NAME onto whatever id the rebuild assigned
    $newExtId = DB::table('specialties')->whereRaw('LOWER(name) = ?', ['dietary'])->value('id');
    $this->assertNotNull($newExtId);
    $this->assertSame((int) $newExtId, (int) $a->owning_specialty_id,
        'owning_specialty_id must follow the specialty NAME across a rebuild, not its old id');

    // admission re-resolved via admissions.legacy_id
    $newAdmId = DB::table('admissions')->where('legacy_id', 990001)->value('id');
    $this->assertNotNull($newAdmId);
    $this->assertSame((int) $newAdmId, (int) $a->admission_id);

    // an app-created admission does not survive the rebuild — NULL, never a stale/wrong id
    $this->assertNull($b->admission_id,
        'a consult pointing at an app-created admission must be NULLed, not left pointing at a rebuilt row');

    // signer remapped by users.legacy_id, exactly like consultant_id
    $newSignerId = DB::table('users')->where('legacy_id', 4242)->value('id');
    $this->assertSame((int) $newSignerId, (int) $a->signed_off_by);
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=test_preserved_consultations_relink_specialty_admission_and_signer
```

Expected: **FAIL** — `owning_specialty_id` still holds the pre-import id (the assertion comparing it to the
rebuilt id fails), because `relinkPreservedConsultations()` only re-points patient/consultant/entered_by.

- [ ] **Step 3: Extend the two helpers**

In `laravel/app/Console/Commands/LegacyImport.php`, replace `captureConsultationLinks()` with:

```php
    /**
     * Snapshot every id-bearing link on the consultations we are about to preserve, together with the
     * NATURAL key that survives a rebuild (MRN, users.legacy_id, specialty name, admissions.legacy_id).
     * The raw ids are worthless after the rebuild re-seeds those tables.
     */
    private function captureConsultationLinks(): array
    {
        return DB::table('consultations as c')
            ->leftJoin('users as cu', 'cu.id', '=', 'c.consultant_id')
            ->leftJoin('users as eu', 'eu.id', '=', 'c.entered_by')
            ->leftJoin('users as su', 'su.id', '=', 'c.signed_off_by')
            ->leftJoin('specialties as sp', 'sp.id', '=', 'c.owning_specialty_id')
            ->leftJoin('admissions as a', 'a.id', '=', 'c.admission_id')
            ->selectRaw('c.id, c.mrn, c.patient_id, c.consultant_id, c.entered_by, c.signed_off_by,
                         c.owning_specialty_id, c.admission_id,
                         cu.legacy_id consultant_legacy_id, eu.legacy_id entered_by_legacy_id,
                         su.legacy_id signed_off_by_legacy_id,
                         sp.name owning_specialty_name, a.legacy_id admission_legacy_id')
            ->get()->map(fn ($r) => (array) $r)->all();
    }
```

and replace `relinkPreservedConsultations()` with:

```php
    private function relinkPreservedConsultations(array $links, array $userMap, array $patientMap): void
    {
        $remapUser = function ($currentId, $legacyId) use ($userMap) {
            if ($currentId === null) { return null; }
            if ($legacyId === null) { return (int) $currentId; }   // app-created user — its id survived
            return $userMap[(int) $legacyId] ?? null;              // legacy user — new id, or gone
        };

        // Specialties are TRUNCATEd and re-inserted. Internal ones keep their legacy id, but external
        // services are inserted by NAME and take fresh auto-increment ids — so a stored id can point at a
        // DIFFERENT service after a rebuild. Name is the only key stable across the rebuild.
        $specialtyByName = [];
        foreach (DB::table('specialties')->get(['id', 'name']) as $s) {
            $specialtyByName[mb_strtolower(trim((string) $s->name))] = (int) $s->id;
        }

        // Admissions are rebuilt wholesale; only legacy-derived rows can be found again.
        $admissionByLegacy = DB::table('admissions')->whereNotNull('legacy_id')
            ->pluck('id', 'legacy_id')->all();

        $changed = 0;
        foreach ($links as $l) {
            $mrn = mb_substr(trim((string) ($l['mrn'] ?? '')), 0, 64);
            $specKey = mb_strtolower(trim((string) ($l['owning_specialty_name'] ?? '')));
            $admLegacy = $l['admission_legacy_id'];

            $new = [
                'patient_id' => $mrn === '' ? null : ($patientMap[$mrn] ?? null),
                'consultant_id' => $remapUser($l['consultant_id'], $l['consultant_legacy_id']),
                'entered_by' => $remapUser($l['entered_by'], $l['entered_by_legacy_id']),
                'signed_off_by' => $remapUser($l['signed_off_by'], $l['signed_off_by_legacy_id']),
                // no name match => Unassigned. Never guess a specialty for a clinical record.
                'owning_specialty_id' => $specKey === '' ? null : ($specialtyByName[$specKey] ?? null),
                // an app-created admission (no legacy_id) does not survive the rebuild. NULL is the honest
                // answer — pointing at a rebuilt row would attach the consult to the WRONG stay.
                'admission_id' => $admLegacy === null ? null : ($admissionByLegacy[(int) $admLegacy] ?? null),
            ];

            // Update unconditionally: the row count here is small (~1,300) and a six-field
            // "did anything change" comparison across nullable ints is pure bug surface.
            DB::table('consultations')->where('id', $l['id'])->update($new);
            $changed++;
        }

        $this->info("  preserved consultations re-linked: {$changed} row(s) re-pointed at the rebuilt ids");
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=LegacyImportTest
```

Expected: **PASS**, including every pre-existing test in the class.

- [ ] **Step 5: Commit**

```bash
git add laravel/app/Console/Commands/LegacyImport.php laravel/tests/Feature/LegacyImportTest.php
git commit -m "fix(import): re-link specialty, admission and signer on preserved consultations"
```

---

### Task 10b: Make the import safety flag flippable from Control

> Task 1 shipped `settings.consultations_source_of_truth` and the import behaviour, but no UI — so today the
> gate can only be flipped with a tinker one-liner. It is the switch that protects the whole ledger; the owner
> must be able to see and set it. `updateSettings()` already carries an identical boolean, `log_record_opens`,
> and every change made there is written to the append-only `setting_changes` history and the audit log — so
> flipping this flag is recorded automatically.

**Files:**
- Modify: `laravel/app/Http/Controllers/ControlController.php` (the `updateSettings()` validator)
- Modify: `laravel/resources/js/Pages/Control/Index.vue` (the Settings tab)
- Test: `laravel/tests/Feature/ConsultationImportSafetyTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_admin_can_toggle_consultations_source_of_truth_and_it_is_recorded(): void
{
    $admin = $this->admin();
    $payload = array_merge($this->settingsPayload(), ['consultations_source_of_truth' => true]);

    $this->actingAs($admin)->put('/control/settings', $payload)->assertRedirect();

    $this->assertTrue((bool) DB::table('settings')->value('consultations_source_of_truth'));
    $this->assertDatabaseHas('setting_changes', ['field' => 'consultations_source_of_truth']);
}

public function test_non_admin_cannot_toggle_consultations_source_of_truth(): void
{
    $consultant = $this->consultant();          // any non-admin clinical user
    $payload = array_merge($this->settingsPayload(), ['consultations_source_of_truth' => true]);

    $this->actingAs($consultant)->put('/control/settings', $payload)->assertForbidden();
    $this->assertFalse((bool) DB::table('settings')->value('consultations_source_of_truth'));
}
```

`settingsPayload()` must return a complete valid settings payload — every key in `updateSettings()`'s validator
is `required` except the booleans, so a partial payload fails validation for unrelated reasons. Copy the
payload helper already used by the existing Control settings tests; find it with:

```bash
cd laravel && grep -rn "control/settings" tests/Feature | head
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=test_admin_can_toggle_consultations_source_of_truth_and_it_is_recorded
```

Expected: **FAIL** — the value stays `false`, because `updateSettings()` never validates or persists the key,
so it is dropped from `$data`.

- [ ] **Step 3: Add the rule and the control**

In `laravel/app/Http/Controllers/ControlController.php`, inside `updateSettings()`'s `$request->validate([...])`
array, directly beneath the `'log_record_opens' => ['sometimes', 'boolean'],` line, add:

```php
            // The consultation-ledger cutover gate. When true, `legacy:import` preserves the
            // consultations table instead of truncating it (see LegacyImport::handle()). Flipping this
            // ON is what makes the new system the source of truth for consultations.
            'consultations_source_of_truth' => ['sometimes', 'boolean'],
```

In `laravel/resources/js/Pages/Control/Index.vue`, find the existing `log_record_opens` checkbox — it is the
one boolean already on this form, so it is the exact markup to duplicate:

```bash
cd laravel && grep -n "log_record_opens" resources/js/Pages/Control/Index.vue
```

Duplicate that entire checkbox block immediately after it, changing only these three things:
1. the bound field `log_record_opens` → `consultations_source_of_truth`
2. the label text → `Consultations: this system is the source of truth`
3. the helper/description text → `When on, importing legacy data preserves the consultation ledger instead of rebuilding it. Turn this on at cutover.`

Do not restyle it or introduce new utility classes — reuse the neighbour's classes verbatim so the allowlist
and contrast checks stay green.

- [ ] **Step 4: Run the tests and rebuild the bundle**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationImportSafetyTest
cd laravel && npx vite build && node scripts/check-source-allowlist.mjs --write && node scripts/check-source-allowlist.mjs && node scripts/contrast.mjs
cd laravel && npx vitest run
```

Expected: PHPUnit **PASS**; both scripts print **PASS**; Vitest **PASS** with no drop from the baseline.

- [ ] **Step 5: Commit**

```bash
git add laravel/app/Http/Controllers/ControlController.php laravel/resources/js/Pages/Control/Index.vue laravel/tests/Feature/ConsultationImportSafetyTest.php laravel/public/build
git commit -m "feat(control): expose the consultation source-of-truth cutover gate"
```

---
## Wave W2, part A — the four-state model and the sign-off response

This wave turns the consultation ledger's binary "open / signed off" flag into the real four-state
machine (`new → active ⇄ ongoing → signed_off`) and makes sign-off record *what the team actually
said*: a required structured disposition, a follow-up-needed flag, an optional note, plus the real
actor and timestamp. It adds the transition endpoint, reworks sign-off and reverse-sign-off, ships
four status tabs with live counts, and shows how long each open consult has been sitting.

It deliberately leaves to later waves: the daily follow-up log and the "seen 8 of 12 today" worklist
(W2B), patient lookup on create and the coordinator notification (W2B), the service handover view
(W3), and every dashboard metric (W4). It **assumes W1 has already landed** — the new columns, the
`Consultation::STATUS_*` constants, `scopeVisibleTo` / `scopeOpen`, `User::canModifyConsultation()`,
`User::canCoordinateConsultations()` and the `users.can_coordinate_consultations` column all come
from W1 and are used here as existing API.

---

### Task 11: Status-transition endpoint `POST /consultations/{consultation}/status`

**Files:**
- Create: `laravel/tests/Feature/ConsultationLedgerW2aTest.php`
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Modify: `laravel/routes/web.php`
- Test: `laravel/tests/Feature/ConsultationLedgerW2aTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationLedgerW2aTest.php` with exactly this content. The fixture
helpers mirror `tests/Feature/Round5J1Test.php` — no factories, explicit `::create()`, and every user
carries `mfa_secret` + `mfa_enrolled_at` + `email_verified_at` because MFA is mandatory and an
un-enrolled user cannot authenticate.

```php
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave W2A — the four-state consultation model and the sign-off response.
 *
 *  11. POST /consultations/{consultation}/status: legal moves only (new→active, new→ongoing,
 *      active⇄ongoing); sign-off is NOT reachable here; a signed-off consult is frozen; the gate is
 *      User::canModifyConsultation (observers refused first, coordinators allowed).
 *  12. signoff() records status/actor/time + the required structured response.
 *  13. reverseSignoff() returns the consult to `ongoing` and clears the whole response block.
 *  14. The workspace filters by the four statuses, ships live per-status counts, and derives the
 *      ageing of an open consult from requested_at, falling back to consultation_date.
 */
class ConsultationLedgerW2aTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'w2a_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W2a User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    /** A coordinator: the new capability, WITHOUT can_manage and without owning the consult. */
    private function coordinator(): User
    {
        return $this->user(User::ROLE_REGISTRAR, [
            'can_coordinate_consultations' => true, 'can_manage' => false,
        ]);
    }

    private function consultation(array $overrides = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'W2a Patient',
            'age' => 55,
            'bed' => 'W-12',
            'current_location' => 'Ward',
            'consultation_from' => 'ER',
            'to_service' => 'Cardiology',
            'consultation_date' => now()->subDay()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $overrides));
    }

    // ---- 11. status-transition endpoint --------------------------------------------------------

    public function test_admin_moves_a_new_consult_to_active_and_the_change_is_audited(): void
    {
        $c = $this->consultation();

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
        $row = AuditLog::where('action', 'consultation.status_change')
            ->where('entity_id', (string) $c->id)->firstOrFail();
        $this->assertSame(Consultation::STATUS_NEW, $row->details['from']);
        $this->assertSame(Consultation::STATUS_ACTIVE, $row->details['to']);
    }

    public function test_new_can_also_go_straight_to_ongoing(): void
    {
        $c = $this->consultation();

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ONGOING])
            ->assertRedirect();

        $this->assertSame(Consultation::STATUS_ONGOING, $c->fresh()->status);
    }

    public function test_active_and_ongoing_move_both_ways(): void
    {
        $admin = $this->admin();
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($admin)->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ONGOING])
            ->assertRedirect();
        $this->assertSame(Consultation::STATUS_ONGOING, $c->fresh()->status);

        $this->actingAs($admin)->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertRedirect();
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
    }

    public function test_a_no_op_transition_is_rejected_with_422(): void
    {
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A consultation cannot move from active to active.');

        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
    }

    public function test_sign_off_is_not_reachable_through_the_status_endpoint(): void
    {
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_SIGNED_OFF])
            ->assertStatus(422);

        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
        $this->assertNull($c->fresh()->signoff_date, 'the sign-off action is the ONLY way to sign off');
    }

    public function test_a_signed_off_consult_is_frozen_for_this_endpoint(): void
    {
        $c = $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF, 'signoff_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ONGOING])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A signed-off consultation cannot be moved. An admin must reverse the sign-off first.');

        $this->assertSame(Consultation::STATUS_SIGNED_OFF, $c->fresh()->status);
    }

    public function test_observers_can_never_change_a_status_even_with_capability_flags(): void
    {
        $c = $this->consultation();

        $this->actingAs($this->user(User::ROLE_OBSERVER, ['can_manage' => true, 'can_modify' => true,
            'can_coordinate_consultations' => true]))
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertForbidden();

        $this->assertSame(Consultation::STATUS_NEW, $c->fresh()->status);
    }

    public function test_a_coordinator_may_change_the_status_of_any_specialtys_consult(): void
    {
        $c = $this->consultation();

        $this->actingAs($this->coordinator())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertRedirect();

        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerW2aTest
```

Expected failure: every method errors with
`Symfony\Component\Routing\Exception\RouteNotFoundException` / a 404 —
`Expected response status code [302] but received 404.` — because `POST /consultations/{id}/status`
does not exist yet.

- [ ] **Step 3: Write the implementation**

In `laravel/routes/web.php`, find this line:

```php
    Route::post('/consultations/{consultation}/reverse-signoff', [ConsultationsController::class, 'reverseSignoff'])->name('consultations.reverseSignoff');
```

and insert directly after it:

```php
    // Wave W2A: explicit four-state moves (new→active, new→ongoing, active⇄ongoing). Sign-off is
    // deliberately NOT reachable here — it carries a required response payload and its own gate.
    Route::post('/consultations/{consultation}/status', [ConsultationsController::class, 'status'])->name('consultations.status');
```

In `laravel/app/Http/Controllers/ConsultationsController.php`, add the `Rule` import next to the
existing imports (after `use Illuminate\Support\Facades\Auth;`):

```php
use Illuminate\Validation\Rule;
```

Then add this constant immediately after the class opening line
`class ConsultationsController extends Controller` + `{`:

```php
    /**
     * The ONLY moves POST /consultations/{consultation}/status will make. Sign-off is absent on
     * purpose: it is a clinical assertion carrying a required response payload, so it goes through
     * signoff() and its own canManageConsultation gate. `signed_off` maps to an empty list — a
     * signed-off consult is frozen until an admin reverses the sign-off (same-day only).
     */
    private const STATUS_MOVES = [
        Consultation::STATUS_NEW => [Consultation::STATUS_ACTIVE, Consultation::STATUS_ONGOING],
        Consultation::STATUS_ACTIVE => [Consultation::STATUS_ONGOING],
        Consultation::STATUS_ONGOING => [Consultation::STATUS_ACTIVE],
        Consultation::STATUS_SIGNED_OFF => [],
    ];
```

And add this method immediately after `store()` (before `signoff()`):

```php
    /**
     * Move a consultation between the three OPEN states. Authorization is the same predicate that
     * guards editing — own specialty / coordinator / admin, observers refused first — so anyone who
     * may work the consult may say where it stands. Illegal moves answer 422 JSON (jsonFail): this
     * route is outside api/*, so a plain ValidationException would redirect instead, and the tabs
     * only ever offer legal moves, making a 422 a genuine client/API error rather than user input.
     */
    public function status(Request $request, Consultation $consultation): RedirectResponse
    {
        if (! Auth::user()->canModifyConsultation($consultation)) {
            throw new AccessDeniedHttpException('You may not change this consultation.');
        }

        $data = $this->jsonValidate($request, [
            'status' => ['required', 'string', Rule::in([
                Consultation::STATUS_NEW, Consultation::STATUS_ACTIVE, Consultation::STATUS_ONGOING,
            ])],
        ]);

        $from = (string) $consultation->status;
        $to = $data['status'];

        if (! in_array($to, self::STATUS_MOVES[$from] ?? [], true)) {
            $this->jsonFail(['status' => $from === Consultation::STATUS_SIGNED_OFF
                ? 'A signed-off consultation cannot be moved. An admin must reverse the sign-off first.'
                : "A consultation cannot move from {$from} to {$to}."]);
        }

        $consultation->update(['status' => $to]);
        Audit::log('consultation.status_change', 'consultation', (string) $consultation->id,
            ['from' => $from, 'to' => $to]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation status updated.']);
    }
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerW2aTest
```

Expected: `OK (8 tests, … assertions)` — all eight status-endpoint methods PASS.

- [ ] **Step 5: Commit**

```
git add laravel/app/Http/Controllers/ConsultationsController.php laravel/routes/web.php laravel/tests/Feature/ConsultationLedgerW2aTest.php
git commit -m "feat(consultations): explicit status-transition endpoint with audited from/to (W2A)"
```

---

### Task 12: Sign-off records status, actor, time and a required structured response

**Files:**
- Create: `laravel/app/Http/Requests/ConsultationSignoffRequest.php`
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Modify: `laravel/app/Models/Consultation.php`
- Modify: `laravel/tests/Feature/Round9L1Test.php` (deliberate update — sign-off now needs a payload)
- Test: `laravel/tests/Feature/ConsultationLedgerW2aTest.php`

- [ ] **Step 1: Write the failing test**

Insert these methods into `laravel/tests/Feature/ConsultationLedgerW2aTest.php`, immediately before
the final closing brace of the class:

```php
    // ---- 12. sign-off writes the response --------------------------------------------------------

    public function test_signoff_records_status_actor_time_and_the_structured_response(): void
    {
        $owner = $this->user();
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE, 'consultant_id' => $owner->id]);

        $this->actingAs($owner)->post("/consultations/{$c->id}/signoff", [
            'response_disposition' => 'advice_given',
            'response_followup_needed' => true,
            'response_note' => 'Continue beta blocker, repeat echo in 6 weeks.',
        ])->assertRedirect()->assertSessionHas('flash.type', 'success');

        $c->refresh();
        $this->assertSame(Consultation::STATUS_SIGNED_OFF, $c->status);
        $this->assertSame(now()->toDateString(), $c->signoff_date?->toDateString());
        $this->assertNotNull($c->signed_off_at);
        $this->assertSame($owner->id, (int) $c->signed_off_by);
        $this->assertSame('advice_given', $c->response_disposition);
        $this->assertTrue((bool) $c->response_followup_needed);
        $this->assertSame('Continue beta blocker, repeat echo in 6 weeks.', $c->response_note);
        $this->assertTrue(AuditLog::where('action', 'consultation.signoff')
            ->where('entity_id', (string) $c->id)->exists());
    }

    public function test_signoff_requires_a_disposition(): void
    {
        $owner = $this->user();
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE, 'consultant_id' => $owner->id]);

        $this->actingAs($owner)->from('/consultations')
            ->post("/consultations/{$c->id}/signoff", ['response_note' => 'no disposition given'])
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('response_disposition');

        $c->refresh();
        $this->assertNull($c->signoff_date, 'a consult must never be signed off without a recorded response');
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->status);
    }

    public function test_signoff_rejects_a_disposition_outside_the_vocabulary(): void
    {
        $owner = $this->user();
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE, 'consultant_id' => $owner->id]);

        $this->actingAs($owner)->from('/consultations')
            ->post("/consultations/{$c->id}/signoff", ['response_disposition' => 'handed_to_surgery'])
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('response_disposition');

        $this->assertNull($c->fresh()->signoff_date);
    }

    public function test_a_coordinator_is_refused_sign_off(): void
    {
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE, 'consultant_id' => $this->user()->id]);

        $this->actingAs($this->coordinator())
            ->post("/consultations/{$c->id}/signoff", ['response_disposition' => 'advice_given'])
            ->assertForbidden();

        $c->refresh();
        $this->assertNull($c->signoff_date, 'coordinating is booking work, not asserting it is done');
        $this->assertNull($c->response_disposition);
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->status);
    }

    public function test_an_already_signed_off_consult_is_refused_without_overwriting_the_response(): void
    {
        $owner = $this->user();
        $c = $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF, 'consultant_id' => $owner->id,
            'signoff_date' => now()->subDay()->toDateString(), 'response_disposition' => 'no_further_input',
        ]);

        $this->actingAs($owner)->post("/consultations/{$c->id}/signoff", [
            'response_disposition' => 'taking_over',
        ])->assertRedirect()->assertSessionHas('flash.type', 'error');

        $this->assertSame('no_further_input', $c->fresh()->response_disposition);
    }
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerW2aTest
```

Expected failure: `test_signoff_records_status_actor_time_and_the_structured_response` fails with
`Failed asserting that 'active' is identical to 'signed_off'` (the current `signoff()` writes only
`signoff_date`), and `test_signoff_requires_a_disposition` fails with
`Session is missing expected key [errors]` because nothing validates the payload yet.

- [ ] **Step 3: Write the implementation**

Create `laravel/app/Http/Requests/ConsultationSignoffRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The sign-off payload. Sign-off is the moment the consulting team asserts its work is done, so it
 * now carries a structured response instead of being a bare click.
 *
 * authorize() keeps the EXISTING sign-off gate byte-for-byte: User::canManageConsultation(), which
 * refuses observers FIRST and then allows admin / can_manage / the receiving consultant. It lives
 * here rather than in the controller so the 403 still precedes any 422 — an unauthorized caller
 * learns nothing from validation. Coordinators (can_coordinate_consultations) are NOT part of that
 * predicate and are therefore refused: booking work into a team is not asserting the work is done.
 */
class ConsultationSignoffRequest extends FormRequest
{
    /** The agreed disposition vocabulary — the one definition, mirrored by the sign-off modal. */
    public const DISPOSITIONS = ['advice_given', 'taking_over', 'follow_up_arranged', 'no_further_input'];

    public function authorize(): bool
    {
        $consultation = $this->route('consultation');

        return $consultation instanceof Consultation
            && $this->user()->canManageConsultation($consultation);
    }

    public function rules(): array
    {
        return [
            'response_disposition' => ['required', 'string', Rule::in(self::DISPOSITIONS)],
            'response_followup_needed' => ['nullable', 'boolean'],
            'response_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'response_disposition.required' => 'Record what the team advised before signing off.',
            'response_disposition.in' => 'Choose one of the listed responses.',
        ];
    }
}
```

In `laravel/app/Models/Consultation.php`, replace the whole `casts()` method:

```php
    protected function casts(): array
    {
        return [
            'consultation_date' => 'date',
            'signoff_date' => 'date',
            'indication' => 'array',
        ];
    }
```

with:

```php
    protected function casts(): array
    {
        return [
            'consultation_date' => 'date',
            'signoff_date' => 'date',
            'indication' => 'array',
            // W2A: the real (datetime) clinical timestamps and the structured sign-off response.
            'requested_at' => 'datetime',
            'signed_off_at' => 'datetime',
            'response_followup_needed' => 'boolean',
        ];
    }
```

In `laravel/app/Http/Controllers/ConsultationsController.php`, replace the entire existing
`signoff()` method:

```php
    public function signoff(Request $request, Consultation $consultation): RedirectResponse
    {
        // observers are read-only EVEN with a Manage flag (J1-9 global guarantee); the manage rule
        // (admin / can_manage / receiving consultant) lives on User::canManageConsultation now
        if (! Auth::user()->canManageConsultation($consultation)) {
            throw new AccessDeniedHttpException('Only the receiving consultant or a manager may sign off.');
        }
        if ($consultation->signoff_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Already signed off.']);
        }
        $consultation->update(['signoff_date' => now()->toDateString()]);
        Audit::log('consultation.signoff', 'consultation', (string) $consultation->id);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation signed off.']);
    }
```

with:

```php
    /**
     * Sign off — now the ledger's closing entry rather than a date stamp. The gate is UNCHANGED
     * (User::canManageConsultation: observers refused first, then admin / can_manage / the receiving
     * consultant) and simply moved into ConsultationSignoffRequest::authorize() so the 403 fires
     * before validation. Coordinators are not in that predicate and stay refused by design.
     */
    public function signoff(\App\Http\Requests\ConsultationSignoffRequest $request, Consultation $consultation): RedirectResponse
    {
        if ($consultation->signoff_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Already signed off.']);
        }
        $data = $request->validated();
        $consultation->update([
            'status' => Consultation::STATUS_SIGNED_OFF,
            'signoff_date' => now()->toDateString(),   // retained: every legacy report reads this
            'signed_off_at' => now(),                   // real time — cutover onward
            'signed_off_by' => Auth::id(),              // session-sourced, never from the payload
            'response_disposition' => $data['response_disposition'],
            'response_followup_needed' => (bool) ($data['response_followup_needed'] ?? false),
            'response_note' => $data['response_note'] ?? null,
        ]);
        Audit::log('consultation.signoff', 'consultation', (string) $consultation->id, [
            'disposition' => $data['response_disposition'],
            'followup_needed' => (bool) ($data['response_followup_needed'] ?? false),
        ]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation signed off.']);
    }
```

Finally, deliberately update the one existing test that signs off with an empty body. In
`laravel/tests/Feature/Round9L1Test.php`, replace:

```php
        $this->actingAs($owner)->post("/consultations/{$c->id}/signoff")
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $this->assertSame(now()->toDateString(), $c->fresh()->signoff_date?->toDateString());
```

with:

```php
        // W2A: sign-off now carries a REQUIRED structured response (disposition), so this pin posts
        // the payload. The gate and the audited outcome it exists to protect are unchanged.
        $this->actingAs($owner)->post("/consultations/{$c->id}/signoff", ['response_disposition' => 'advice_given'])
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $this->assertSame(now()->toDateString(), $c->fresh()->signoff_date?->toDateString());
        $this->assertSame('advice_given', $c->fresh()->response_disposition);
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerW2aTest
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=Round9L1Test
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=Round5J2Test
```

Expected: `OK (13 tests, …)` for `ConsultationLedgerW2aTest`, and `OK` for both existing suites —
`Round5J2Test`'s "observer with can_manage is forbidden from sign-off" still returns 403 because
`authorize()` runs before validation.

- [ ] **Step 5: Commit**

```
git add laravel/app/Http/Requests/ConsultationSignoffRequest.php laravel/app/Http/Controllers/ConsultationsController.php laravel/app/Models/Consultation.php laravel/tests/Feature/ConsultationLedgerW2aTest.php laravel/tests/Feature/Round9L1Test.php
git commit -m "feat(consultations): sign-off records status, actor, time and a required response (W2A)"
```

---

### Task 13: Reverse-sign-off returns the consult to `ongoing` and clears the response

**Files:**
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Test: `laravel/tests/Feature/ConsultationLedgerW2aTest.php`

- [ ] **Step 1: Write the failing test**

Insert these methods into `laravel/tests/Feature/ConsultationLedgerW2aTest.php`, immediately before
the final closing brace of the class:

```php
    // ---- 13. reverse sign-off ---------------------------------------------------------------------

    public function test_reverse_signoff_returns_the_consult_to_ongoing_and_clears_the_response(): void
    {
        $owner = $this->user();
        $c = $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF,
            'consultant_id' => $owner->id,
            'signoff_date' => now()->toDateString(),
            'signed_off_at' => now(),
            'signed_off_by' => $owner->id,
            'response_disposition' => 'taking_over',
            'response_followup_needed' => true,
            'response_note' => 'signed off in error',
        ]);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/reverse-signoff")
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $c->refresh();
        $this->assertSame(Consultation::STATUS_ONGOING, $c->status, 'a reopened consult is on the books, not a daily commitment');
        $this->assertNull($c->signoff_date);
        $this->assertNull($c->signed_off_at);
        $this->assertNull($c->signed_off_by);
        $this->assertNull($c->response_disposition);
        $this->assertNull($c->response_followup_needed);
        $this->assertNull($c->response_note);
        $this->assertTrue(AuditLog::where('action', 'consultation.reverse_signoff')
            ->where('entity_id', (string) $c->id)->exists());
    }

    public function test_reverse_signoff_of_an_older_signoff_changes_nothing_at_all(): void
    {
        $c = $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF,
            'signoff_date' => now()->subDays(3)->toDateString(),
            'signed_off_at' => now()->subDays(3),
            'response_disposition' => 'advice_given',
        ]);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/reverse-signoff")
            ->assertRedirect()->assertSessionHas('flash.type', 'error');

        $c->refresh();
        $this->assertSame(Consultation::STATUS_SIGNED_OFF, $c->status);
        $this->assertNotNull($c->signoff_date, 'older sign-offs are history, not mistakes');
        $this->assertSame('advice_given', $c->response_disposition);
    }

    public function test_a_non_admin_cannot_reverse_a_sign_off(): void
    {
        $owner = $this->user();
        $c = $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF, 'consultant_id' => $owner->id,
            'signoff_date' => now()->toDateString(), 'response_disposition' => 'advice_given',
        ]);

        $this->actingAs($owner)->post("/consultations/{$c->id}/reverse-signoff")->assertForbidden();
        $this->actingAs($this->coordinator())->post("/consultations/{$c->id}/reverse-signoff")->assertForbidden();

        $this->assertSame(Consultation::STATUS_SIGNED_OFF, $c->fresh()->status);
        $this->assertNotNull($c->fresh()->signoff_date);
    }
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerW2aTest
```

Expected failure: `test_reverse_signoff_returns_the_consult_to_ongoing_and_clears_the_response` fails
with `Failed asserting that 'signed_off' is identical to 'ongoing'` — the current `reverseSignoff()`
nulls only `signoff_date`.

- [ ] **Step 3: Write the implementation**

In `laravel/app/Http/Controllers/ConsultationsController.php`, replace the entire existing
`reverseSignoff()` method:

```php
    /** Undo a same-day sign-off (admin only) — clears the sign-off date. */
    public function reverseSignoff(Request $request, Consultation $consultation): RedirectResponse
    {
        if (! Auth::user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admin only.');
        }
        // Undo corrects a SAME-DAY mistake (legacy 48consultation.php undo) — mirrors the
        // same-day reverse-discharge guard; older sign-offs are history.
        if (! $consultation->signoff_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This consultation is not signed off.']);
        }
        if (! $consultation->signoff_date->isToday()) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Only same-day sign-offs can be reversed.']);
        }
        $consultation->update(['signoff_date' => null]);
        Audit::log('consultation.reverse_signoff', 'consultation', (string) $consultation->id);

        return back()->with('flash', ['type' => 'success', 'message' => 'Sign-off reversed.']);
    }
```

with:

```php
    /**
     * Undo a same-day sign-off (admin only) — the ONE path back out of `signed_off`. Both guards are
     * unchanged (admin only; same-day only). W2A: the whole closing entry is withdrawn, not just the
     * date — status returns to `ongoing` (on the books, no daily follow-up commitment asserted, per
     * the same reasoning as the legacy backfill) and every response field is cleared so a reopened
     * consult never displays a recommendation nobody stands behind.
     */
    public function reverseSignoff(Request $request, Consultation $consultation): RedirectResponse
    {
        if (! Auth::user()->isAdmin()) {
            throw new AccessDeniedHttpException('Admin only.');
        }
        // Undo corrects a SAME-DAY mistake (legacy 48consultation.php undo) — mirrors the
        // same-day reverse-discharge guard; older sign-offs are history.
        if (! $consultation->signoff_date) {
            return back()->with('flash', ['type' => 'error', 'message' => 'This consultation is not signed off.']);
        }
        if (! $consultation->signoff_date->isToday()) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Only same-day sign-offs can be reversed.']);
        }
        $consultation->update([
            'status' => Consultation::STATUS_ONGOING,
            'signoff_date' => null,
            'signed_off_at' => null,
            'signed_off_by' => null,
            'response_disposition' => null,
            'response_followup_needed' => null,
            'response_note' => null,
        ]);
        Audit::log('consultation.reverse_signoff', 'consultation', (string) $consultation->id,
            ['to' => Consultation::STATUS_ONGOING]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Sign-off reversed.']);
    }
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerW2aTest
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ResidualR1Test
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=RecentActivityTest
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=Round4H2Test
```

Expected: `OK (16 tests, …)` for `ConsultationLedgerW2aTest`, and `OK` for all three existing suites
(their same-day / not-same-day reverse pins assert `signoff_date` only, which still behaves exactly
as before).

- [ ] **Step 5: Commit**

```
git add laravel/app/Http/Controllers/ConsultationsController.php laravel/tests/Feature/ConsultationLedgerW2aTest.php
git commit -m "feat(consultations): reverse-signoff reopens to ongoing and withdraws the response (W2A)"
```

---

### Task 14: Workspace filters the four statuses, ships live counts and the ageing of an open consult

**Files:**
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Modify: `laravel/tests/Feature/TermOutOfUrlTest.php` (deliberate update — `status` now names a state)
- Test: `laravel/tests/Feature/ConsultationLedgerW2aTest.php`

- [ ] **Step 1: Write the failing test**

Insert these methods into `laravel/tests/Feature/ConsultationLedgerW2aTest.php`, immediately before
the final closing brace of the class. Add the `AssertableInertia` import to the file's `use` block
(directly after `use Illuminate\Foundation\Testing\RefreshDatabase;`):

```php
use Inertia\Testing\AssertableInertia;
```

```php
    // ---- 14. four status tabs, live counts, ageing -----------------------------------------------

    public function test_the_workspace_filters_by_status_and_ships_a_live_count_per_tab(): void
    {
        $this->consultation(['status' => Consultation::STATUS_NEW]);
        $this->consultation(['status' => Consultation::STATUS_ACTIVE]);
        $this->consultation(['status' => Consultation::STATUS_ACTIVE]);
        $this->consultation(['status' => Consultation::STATUS_ONGOING]);
        $this->consultation(['status' => Consultation::STATUS_SIGNED_OFF, 'signoff_date' => now()->toDateString()]);

        $this->actingAs($this->admin())->get('/consultations?status=active')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Consultations/Index')
                ->where('filters.status', 'active')
                ->where('consultations.total', 2)
                ->where('stats.new', 1)
                ->where('stats.active', 2)
                ->where('stats.ongoing', 1)
                ->where('stats.signed_off', 1)
                ->where('stats.total', 5));

        $this->actingAs($this->admin())->get('/consultations?status=ongoing')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.total', 1));

        $this->actingAs($this->admin())->get('/consultations?status=signed_off')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultations.total', 1)
                ->where('consultations.data.0.open_days', null));
    }

    public function test_an_unknown_status_falls_back_to_the_new_tab(): void
    {
        $this->consultation(['status' => Consultation::STATUS_NEW]);
        $this->consultation(['status' => Consultation::STATUS_ONGOING]);

        $this->actingAs($this->admin())->get('/consultations?status=signed')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('filters.status', 'new')
                ->where('consultations.total', 1));
    }

    public function test_open_days_uses_requested_at_when_it_is_present(): void
    {
        $this->consultation([
            'status' => Consultation::STATUS_ONGOING,
            'requested_at' => now()->subDays(6),
            'consultation_date' => now()->subDays(30)->toDateString(),   // must be ignored
        ]);

        $this->actingAs($this->admin())->get('/consultations?status=ongoing')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.data.0.open_days', 6));
    }

    public function test_open_days_falls_back_to_consultation_date_for_historical_rows(): void
    {
        $this->consultation([
            'status' => Consultation::STATUS_ONGOING,
            'requested_at' => null,                                       // every legacy row
            'consultation_date' => now()->subDays(3)->toDateString(),
        ]);

        $this->actingAs($this->admin())->get('/consultations?status=ongoing')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.data.0.open_days', 3));
    }

    public function test_a_row_with_neither_timestamp_reports_no_ageing_rather_than_zero(): void
    {
        $this->consultation([
            'status' => Consultation::STATUS_ONGOING, 'requested_at' => null, 'consultation_date' => null,
        ]);

        $this->actingAs($this->admin())->get('/consultations?status=ongoing')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.data.0.open_days', null));
    }
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerW2aTest
```

Expected failure: `test_the_workspace_filters_by_status_and_ships_a_live_count_per_tab` fails with
`Inertia property [stats.new] does not exist.` (the page ships only `active` / `total` /
`mine_active`), and the ageing tests fail with `Inertia property [consultations.data.0.open_days]
does not exist.`

- [ ] **Step 3: Write the implementation**

In `laravel/app/Http/Controllers/ConsultationsController.php`, replace the entire body of `index()`
from the `$filters` line to the closing `);` of the `Inertia::render(...)` call:

```php
        $filters = $request->only('search', 'status', 'scope');
        $status = $filters['status'] ?? 'active';
        $mine = ($filters['scope'] ?? '') === 'mine';
        $reasons = ConsultationReason::pluck('name', 'id');

        $consultations = Consultation::query()
            ->with('consultant:id,full_name,name')
            ->when($status === 'active', fn ($q) => $q->whereNull('signoff_date'))
            ->when($status === 'signed', fn ($q) => $q->whereNotNull('signoff_date'))
            ->when($mine, fn ($q) => $q->where('consultant_id', Auth::id()))
```

…through to…

```php
            'reasons' => $reasons->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'consultants' => User::consultantOptions(),
        ]);
```

with this complete replacement:

```php
        $filters = $request->only('search', 'status', 'scope');
        // W2A: `status` now names one of the FOUR real states. Anything else — an absent param, or a
        // bookmarked legacy `?status=signed` — falls back to `new`, the tab that demands triage. The
        // per-tab counts ship on every render, so an empty default tab still points at the work.
        $status = in_array($filters['status'] ?? '', self::STATUS_TABS, true)
            ? $filters['status']
            : Consultation::STATUS_NEW;
        $mine = ($filters['scope'] ?? '') === 'mine';
        $reasons = ConsultationReason::pluck('name', 'id');

        // W1 specialty scoping: every list AND every count runs through the same visibility scope,
        // so a tab count can never advertise rows the viewer is not allowed to open.
        $scoped = fn () => Consultation::query()->visibleTo(Auth::user());

        $consultations = $scoped()
            ->with('consultant:id,full_name,name')
            ->where('status', $status)
            ->when($mine, fn ($q) => $q->where('consultant_id', Auth::id()))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) =>
                $w->where('patient_name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%")))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Consultation $c) => [
                'id' => $c->id,
                'name' => $c->patient_name ?? 'Unknown',
                'mrn' => $c->mrn,
                'age' => $c->age,
                'bed' => $c->bed,
                'location' => $c->current_location,
                'from' => $c->consultation_from,
                'to' => $c->to_service,
                'consultant' => $c->consultant?->full_name ?? $c->consultant?->name ?? '—',
                'consultant_id' => $c->consultant_id,
                'date' => optional($c->consultation_date)->toDateString(),
                'signoff' => optional($c->signoff_date)->toDateString(),
                'status' => $c->status,
                'open_days' => self::openDays($c),
                'disposition' => $c->response_disposition,
                'reasons' => collect($c->indication ?? [])->map(fn ($id) => $reasons[$id] ?? null)->filter()->values(),
                'indication_ids' => array_map('intval', $c->indication ?? []),
                'other' => $c->other_indication,
            ]);

        $counts = $scoped()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return Inertia::render('Consultations/Index', [
            'consultations' => $consultations,
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $status, 'scope' => $mine ? 'mine' : ''],
            // full objects (not just names): the form filters the consultant dropdown by
            // INTERNAL specialty when "to service" matches one
            'specialties' => Specialty::orderBy('name')->get(['id', 'name', 'is_external']),
            'stats' => [
                Consultation::STATUS_NEW => (int) ($counts[Consultation::STATUS_NEW] ?? 0),
                Consultation::STATUS_ACTIVE => (int) ($counts[Consultation::STATUS_ACTIVE] ?? 0),
                Consultation::STATUS_ONGOING => (int) ($counts[Consultation::STATUS_ONGOING] ?? 0),
                Consultation::STATUS_SIGNED_OFF => (int) ($counts[Consultation::STATUS_SIGNED_OFF] ?? 0),
                'total' => (int) $counts->sum(),
                // personal counter for consultant-role viewers (K1-13): own OPEN out of all open
                'mine_open' => $scoped()->open()->where('consultant_id', Auth::id())->count(),
                'open' => $scoped()->open()->count(),
            ],
            'reasons' => $reasons->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'consultants' => User::consultantOptions(),
        ]);
```

Then add this constant directly below the `STATUS_MOVES` constant added in Task 11:

```php
    /** The four tabs of the workspace, in display order — also the only accepted `?status=` values. */
    private const STATUS_TABS = [
        Consultation::STATUS_NEW, Consultation::STATUS_ACTIVE,
        Consultation::STATUS_ONGOING, Consultation::STATUS_SIGNED_OFF,
    ];
```

…and add this helper as the last method of the class, directly before the final closing brace:

```php
    /**
     * How long an OPEN consult has been waiting, in whole days.
     *
     * `requested_at` is the authoritative request time but is NULL for all 1,283 historical rows —
     * §4.4 refuses to fabricate a time that never existed — so ageing falls back to the date-only
     * `consultation_date` those rows do have. Both are compared at day granularity, which is the
     * only precision the fallback can honestly claim. A signed-off consult is not open and has no
     * ageing; a row with neither timestamp reports NULL ("—") rather than a misleading 0.
     */
    private static function openDays(Consultation $c): ?int
    {
        if ($c->status === Consultation::STATUS_SIGNED_OFF) {
            return null;
        }
        $start = $c->requested_at ?? $c->consultation_date;
        if ($start === null) {
            return null;
        }

        return max(0, (int) $start->copy()->startOfDay()->diffInDays(now()->startOfDay()));
    }
```

Finally, deliberately update the one existing test whose `?status=` value now names a state. In
`laravel/tests/Feature/TermOutOfUrlTest.php`, replace:

```php
            ->post('/consultations/search?status=active', ['search' => 'Findme'])
```

with:

```php
            // W2A: `status` names one of the four real states; a freshly created consult is `new`.
            // What this test pins — the TERM travels in the POST body, never the URL — is unchanged.
            ->post('/consultations/search?status=new', ['search' => 'Findme'])
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationLedgerW2aTest
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=TermOutOfUrlTest
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ResidualR3Test
```

Expected: `OK (21 tests, …)` for `ConsultationLedgerW2aTest`, and `OK` for both existing suites.

- [ ] **Step 5: Commit**

```
git add laravel/app/Http/Controllers/ConsultationsController.php laravel/tests/Feature/ConsultationLedgerW2aTest.php laravel/tests/Feature/TermOutOfUrlTest.php
git commit -m "feat(consultations): four status tabs with live counts + open-days ageing (W2A)"
```

---

### Task 15: Four status tabs and a sign-off response modal in the workspace UI

**Files:**
- Modify: `laravel/resources/js/Pages/Consultations/Index.vue`
- Modify: `laravel/resources/js/__tests__/ConsultationsIndex.wave3.test.js`
- Modify: `laravel/resources/js/__tests__/ConsultationsIndex.wave2.test.js` (deliberate update — sign-off is no longer one click)
- Modify: `laravel/public/build/**` (committed build output)
- Test: `laravel/resources/js/__tests__/ConsultationsIndex.wave3.test.js`

- [ ] **Step 1: Write the failing test**

First, deliberately update the stale one-click pin. In
`laravel/resources/js/__tests__/ConsultationsIndex.wave2.test.js`, replace this whole block:

```js
describe('Consultations/Index — Wave 2 Item 4', () => {
    it('signoff posts immediately and never calls ask()', () => {
        const vm = mountWith(admin).vm;
        vm.signoff({ id: 7, name: 'X', mrn: '1' });
        expect(post).toHaveBeenCalledWith('/consultations/7/signoff', {}, { preserveScroll: true });
        expect(ask).not.toHaveBeenCalled();
    });
```

with:

```js
describe('Consultations/Index — Wave 2 Item 4', () => {
    // W2A: sign-off carries a REQUIRED structured response, so it opens a modal instead of posting
    // on click. What this case still pins is the Item-4 decision it was written for: sign-off never
    // routes through the destructive-confirm dialog (deleteConsult, below, still does).
    it('openSignoff opens the response modal and never calls ask()', () => {
        const vm = mountWith(admin).vm;
        vm.openSignoff({ id: 7, name: 'X', mrn: '1', status: 'active' });
        expect(vm.signingOff.id).toBe(7);
        expect(post).not.toHaveBeenCalled();
        expect(ask).not.toHaveBeenCalled();
    });
```

and, in the same file, replace the props fixture:

```js
    filters: {}, stats: { active: 0, total: 0, mine_active: 0 }, reasons: [], consultants: [], specialties: [],
```

with:

```js
    filters: {}, stats: { new: 0, active: 0, ongoing: 0, signed_off: 0, total: 0, open: 0, mine_open: 0 },
    reasons: [], consultants: [], specialties: [],
```

Then, in `laravel/resources/js/__tests__/ConsultationsIndex.wave3.test.js`, replace the props fixture:

```js
    filters: {}, stats: { active: 0, total: 0, mine_active: 0 }, reasons: [], consultants: [], specialties: [],
```

with:

```js
    filters: {}, stats: { new: 2, active: 3, ongoing: 4, signed_off: 5, total: 14, open: 9, mine_open: 1 },
    reasons: [], consultants: [], specialties: [],
```

and append these two `describe` blocks to the end of the same file:

```js
describe('Consultations/Index — W2A: four status tabs with live counts', () => {
    it('renders exactly the four states, each carrying its count', () => {
        const w = mountWith();
        const labels = w.findAll('[data-status-tab]').map((b) => b.text());
        expect(labels).toEqual(['New 2', 'Active 3', 'Ongoing 4', 'Signed off 5']);
    });

    it('clicking a tab re-queries with that status', () => {
        const w = mountWith();
        w.findAll('[data-status-tab]')[2].trigger('click');
        expect(w.vm.status).toBe('ongoing');
    });

    it('shows the ageing of an open consult and nothing for a signed-off one', () => {
        const w = mount(ConsultationsIndex, {
            props: {
                ...props,
                consultations: {
                    data: [
                        { id: 1, name: 'A', mrn: '1', reasons: [], status: 'ongoing', open_days: 6, signoff: null, indication_ids: [] },
                        { id: 2, name: 'B', mrn: '2', reasons: [], status: 'signed_off', open_days: null, signoff: '2026-08-20', indication_ids: [] },
                    ],
                    total: 2, last_page: 1, links: [],
                },
            },
        });
        const cells = w.findAll('[data-open-days]').map((c) => c.text());
        expect(cells).toEqual(['open 6 days', '—']);
    });
});

describe('Consultations/Index — W2A: sign-off response modal', () => {
    const row = { id: 7, name: 'Ali', mrn: '111', status: 'active', open_days: 2, signoff: null, reasons: [], indication_ids: [] };

    it('submitting posts the disposition, follow-up flag and note to the sign-off route', async () => {
        const w = mountWith();
        w.vm.openSignoff(row);
        await w.vm.$nextTick();
        w.vm.sForm.response_disposition = 'advice_given';
        w.vm.sForm.response_followup_needed = true;
        w.vm.sForm.response_note = 'Repeat echo in 6 weeks.';
        w.vm.submitSignoff();
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/consultations/7/signoff');
    });

    it('double-submit guard: submitSignoff no-ops while processing', async () => {
        const w = mountWith();
        w.vm.openSignoff(row);
        w.vm.sForm.processing = true;
        await w.vm.$nextTick();
        w.vm.submitSignoff();
        expect(post).not.toHaveBeenCalled();
    });

    it('dirty form: Cancel asks (danger) before discarding the response', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith();
        w.vm.openSignoff(row);
        w.vm.sForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.closeSignoff();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(w.vm.signingOff).toBe(null);
    });

    it('maps sForm.errors onto an id that resolves to a real control', async () => {
        const w = mountWith();
        w.vm.openSignoff(row);
        w.vm.sForm.errors = { response_disposition: 'Record what the team advised before signing off.' };
        await w.vm.$nextTick();
        const alert = w.get('[role="alert"]');
        const href = alert.get('a').attributes('href').slice(1);
        expect(w.get(`#${href}`).element.tagName).toBe('SELECT');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && npx vitest run resources/js/__tests__/ConsultationsIndex.wave3.test.js
```

Expected failure: `expected [] to deeply equal [ 'New 2', 'Active 3', 'Ongoing 4', 'Signed off 5' ]`
(no `data-status-tab` elements exist) and `TypeError: w.vm.openSignoff is not a function`.

- [ ] **Step 3: Write the implementation**

In `laravel/resources/js/Pages/Consultations/Index.vue`:

**(3a)** Replace this line in `<script setup>`:

```js
const canSignoff = (row) => me.value.is_admin || me.value.can.manage || row.consultant_id === me.value.id;
```

with:

```js
// mirrors User::canManageConsultation server-side (observers are excluded by canAdd/role checks and
// by the server regardless). Coordinators are deliberately NOT here: they book work, they do not
// assert it is finished — the server refuses them with a 403.
const canSignoff = (row) => me.value.is_admin || me.value.can.manage || row.consultant_id === me.value.id;

// the four states, in workflow order, each rendered as a tab with its live count
const STATUS_TABS = [
    ['new', 'New'],
    ['active', 'Active'],
    ['ongoing', 'Ongoing'],
    ['signed_off', 'Signed off'],
];
const STATUS_BADGE = {
    new: 'bg-tint-info text-on-info',
    active: 'bg-tint-accent text-on-accent',
    ongoing: 'bg-tint-warning text-on-warning',
    signed_off: 'bg-tint-success text-on-success',
};
const statusLabel = (s) => (STATUS_TABS.find((t) => t[0] === s) || [null, 'Unknown'])[1];
// the moves POST /consultations/{id}/status accepts — the UI offers only legal ones, so the
// server's 422 stays a genuine API-misuse guard rather than routine user feedback
const STATUS_MOVES = { new: ['active', 'ongoing'], active: ['ongoing'], ongoing: ['active'], signed_off: [] };
const moveTo = (row, next) => router.post(`/consultations/${row.id}/status`, { status: next }, { preserveScroll: true });

// the agreed sign-off vocabulary — kept identical to ConsultationSignoffRequest::DISPOSITIONS
const DISPOSITIONS = [
    ['advice_given', 'Advice given'],
    ['taking_over', 'Taking over'],
    ['follow_up_arranged', 'Follow-up arranged'],
    ['no_further_input', 'No further input'],
];
const dispositionLabel = (v) => (DISPOSITIONS.find((d) => d[0] === v) || [null, ''])[1];
```

**(3b)** Replace this line:

```js
const status = ref(props.filters.status || 'active');
```

with:

```js
const status = ref(props.filters.status || 'new');
```

**(3c)** Replace the whole sign-off block at the end of `<script setup>`:

```js
// sign off — Wave 2, Item 4: no confirm. A single sign-off is reversible (the reverse-signoff
// button, already instant) and low-stakes; the server flash is the feedback. deleteConsult keeps
// its danger confirm (irreversible). Sign-all stays confirmed on the Handovers page (bulk).
const signoff = (row) => router.post(`/consultations/${row.id}/signoff`, {}, { preserveScroll: true });
```

with:

```js
// sign off — W2A: no longer one click, because sign-off now records WHAT the team advised. It opens
// the same modal idiom as add/edit: BaseModal + useForm + the unsaved-changes guard + ErrorSummary +
// guardSubmit. Still no destructive confirm (Wave 2, Item 4): a sign-off is admin-reversible on the
// same day, and the modal itself is the deliberate step. deleteConsult keeps its danger confirm.
const signingOff = ref(null);
const sForm = useForm({ response_disposition: '', response_followup_needed: false, response_note: '' });
const sUid = useId();
const sfid = (fieldName) => `consult-signoff-${sUid}-${fieldName}`;
const sErrors = computed(() => Object.fromEntries(Object.entries(sForm.errors || {}).map(([k, v]) => [sfid(k), v])));
const { guardedClose: guardedCloseSignoff } = useUnsavedGuard(() => sForm.isDirty, ask);
const openSignoff = (row) => {
    signingOff.value = row;
    sForm.response_disposition = ''; sForm.response_followup_needed = false; sForm.response_note = '';
    sForm.clearErrors?.();
    sForm.defaults?.();   // re-anchor Inertia's isDirty baseline to the empty response
};
const doCloseSignoff = () => { signingOff.value = null; };
const closeSignoff = () => guardedCloseSignoff(doCloseSignoff);
const submitSignoff = guardSubmit(sForm, () => sForm.post(`/consultations/${signingOff.value.id}/signoff`, {
    preserveScroll: true,
    onSuccess: () => { doCloseSignoff(); sForm.reset(); },
}));
```

**(3d)** In the template, replace the stats chips + the old status control. Replace this block:

```html
            <div class="flex gap-2">
                <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Active <span class="nums ms-1 text-on-accent">{{ stats.active }}</span></span>
                <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Total <span class="nums ms-1 text-ink-600">{{ stats.total }}</span></span>
                <!-- personal counter for consultant viewers (K1-13): own active out of total active -->
                <span v-if="me.role === 3" class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Mine <span class="nums ms-1 text-brand-700">{{ stats.mine_active }} of {{ stats.active }} active</span></span>
            </div>
```

with:

```html
            <div class="flex gap-2">
                <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Open <span class="nums ms-1 text-on-accent">{{ stats.open ?? 0 }}</span></span>
                <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Total <span class="nums ms-1 text-ink-600">{{ stats.total }}</span></span>
                <!-- personal counter for consultant viewers (K1-13): own open out of all open -->
                <span v-if="me.role === 3" class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Mine <span class="nums ms-1 text-brand-700">{{ stats.mine_open ?? 0 }} of {{ stats.open ?? 0 }} open</span></span>
            </div>
```

and replace the old three-way control:

```html
            <div class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line">
                <button v-for="s in [['active','Active'],['signed','Signed off'],['all','All']]" :key="s[0]" @click="setStatus(s[0])"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition" :class="status === s[0] ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">{{ s[1] }}</button>
            </div>
```

with:

```html
            <div role="tablist" aria-label="Consultation status" class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line">
                <button v-for="s in STATUS_TABS" :key="s[0]" data-status-tab role="tab" :aria-selected="status === s[0]" @click="setStatus(s[0])"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition" :class="status === s[0] ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">
                    {{ s[1] }} <span class="nums ms-1">{{ stats[s[0]] ?? 0 }}</span>
                </button>
            </div>
```

**(3e)** Add the ageing column. Replace the header row:

```html
                        <th scope="col" class="px-3 py-3">Consultant</th><th scope="col" class="px-3 py-3">Date</th><th scope="col" class="px-5 py-3">Status</th>
```

with:

```html
                        <th scope="col" class="px-3 py-3">Consultant</th><th scope="col" class="px-3 py-3">Date</th>
                        <th scope="col" class="px-3 py-3">Open</th><th scope="col" class="px-5 py-3">Status</th>
```

and replace the date cell:

```html
                        <td class="nums px-3 py-3 text-ink-500">{{ formatDate(c.date) || '—' }}</td>
```

with:

```html
                        <td class="nums px-3 py-3 text-ink-500">{{ formatDate(c.date) || '—' }}</td>
                        <!-- ageing: whole days since requested_at, or since consultation_date for the
                             historical rows that have no request time. Signed-off rows show nothing. -->
                        <td data-open-days class="nums px-3 py-3 text-ink-500">{{ c.open_days === null || c.open_days === undefined ? '—' : `open ${c.open_days} days` }}</td>
```

Bump the empty-state colspan to match the new column count — replace:

```html
                    <tr v-if="!consultations.data.length"><td colspan="7" class="px-5 py-10 text-center text-ink-400">No consultations match your filters.</td></tr>
```

with:

```html
                    <tr v-if="!consultations.data.length"><td colspan="8" class="px-5 py-10 text-center text-ink-400">No consultations match your filters.</td></tr>
```

**(3f)** Replace the actions cell so the badge reflects the four states, legal moves are offered, and
Sign off opens the modal. Replace:

```html
                            <div class="flex items-center gap-2">
                                <span v-if="c.signoff" class="rounded-full bg-tint-success px-2.5 py-0.5 text-xs font-semibold text-on-success">Signed {{ formatDate(c.signoff) }}</span>
                                <span v-else class="rounded-full bg-tint-accent px-2.5 py-0.5 text-xs font-semibold text-on-accent">Active</span>
                                <button v-if="!c.signoff && canSignoff(c)" @click="signoff(c)" title="Sign off" class="rounded-lg px-2 py-1 text-xs font-semibold text-on-success hover:bg-tint-success">Sign off</button>
```

with:

```html
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="STATUS_BADGE[c.status] || 'bg-tint-accent text-on-accent'"
                                    :title="c.status === 'signed_off' && c.disposition ? dispositionLabel(c.disposition) : undefined">
                                    {{ statusLabel(c.status) }}<template v-if="c.status === 'signed_off' && c.signoff"> {{ formatDate(c.signoff) }}</template>
                                </span>
                                <button v-for="next in (canEdit(c) ? (STATUS_MOVES[c.status] || []) : [])" :key="next" @click="moveTo(c, next)"
                                    :title="`Move to ${statusLabel(next)}`" class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-700 hover:bg-brand-50">→ {{ statusLabel(next) }}</button>
                                <button v-if="c.status !== 'signed_off' && canSignoff(c)" @click="openSignoff(c)" title="Sign off" class="rounded-lg px-2 py-1 text-xs font-semibold text-on-success hover:bg-tint-success">Sign off</button>
```

**(3g)** Add the sign-off modal. Insert it immediately before the closing `</AppLayout>` tag, after
the new-consultation `BaseModal`:

```html
        <!-- sign-off response modal (W2A): a sign-off now records WHAT the team advised -->
        <BaseModal :open="!!signingOff" title="Sign off consultation" size="lg" field-first :dirty="!!sForm.isDirty" @close="closeSignoff">
                <ErrorSummary :errors="sErrors" />
                <p v-if="signingOff" class="mb-4 text-sm text-ink-500">
                    {{ signingOff.name }} · <span class="nums">MRN {{ signingOff.mrn }}</span>
                </p>
                <form @submit.prevent="submitSignoff" class="space-y-4">
                    <div>
                        <label :for="sfid('response_disposition')" class="mb-1 block text-sm font-semibold text-ink-700">Response <span class="text-danger-500">*</span></label>
                        <select :id="sfid('response_disposition')" v-model="sForm.response_disposition"
                            :aria-describedby="sForm.errors.response_disposition ? sfid('response_disposition') + '-err' : undefined"
                            :class="[field, sForm.errors.response_disposition && 'border-danger-500']">
                            <option value="">Select a response…</option>
                            <option v-for="d in DISPOSITIONS" :key="d[0]" :value="d[0]">{{ d[1] }}</option>
                        </select>
                        <p v-if="sForm.errors.response_disposition" :id="sfid('response_disposition') + '-err'" class="mt-1 text-xs text-on-danger">{{ sForm.errors.response_disposition }}</p>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-sm font-semibold text-ink-700">
                            <input :id="sfid('response_followup_needed')" type="checkbox" v-model="sForm.response_followup_needed" />
                            Follow-up still needed
                        </label>
                    </div>
                    <div>
                        <label :for="sfid('response_note')" class="mb-1 block text-sm font-semibold text-ink-700">Note</label>
                        <textarea :id="sfid('response_note')" v-model="sForm.response_note" rows="3"
                            :aria-describedby="sForm.errors.response_note ? sfid('response_note') + '-err' : undefined"
                            :class="[field, sForm.errors.response_note && 'border-danger-500']"
                            placeholder="Working note — the clinical note stays in the HIS"></textarea>
                        <p v-if="sForm.errors.response_note" :id="sfid('response_note') + '-err'" class="mt-1 text-xs text-on-danger">{{ sForm.errors.response_note }}</p>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeSignoff" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                        <button type="submit" :disabled="sForm.processing" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Sign off</button>
                    </div>
                </form>
        </BaseModal>
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && npx vitest run
cd laravel && npx vite build
cd laravel && node scripts/check-source-allowlist.mjs --write
cd laravel && node scripts/check-source-allowlist.mjs
cd laravel && node scripts/contrast.mjs
```

Expected: vitest reports all files passing (the two Consultations specs green, no other suite
touched); `check-source-allowlist.mjs` prints `PASS` after the `--write` refresh; `contrast.mjs`
prints `PASS`.

- [ ] **Step 5: Commit**

```
git add laravel/resources/js/Pages/Consultations/Index.vue laravel/resources/js/__tests__/ConsultationsIndex.wave3.test.js laravel/resources/js/__tests__/ConsultationsIndex.wave2.test.js laravel/resources/css/app.css laravel/scripts/check-source-allowlist.mjs laravel/public/build
git commit -m "feat(consultations): four status tabs, ageing column and a sign-off response modal (W2A)"
```
## Wave W2, part B — daily follow-up log, patient lookup on create, coordinator notification

This wave turns the consultation ledger into something a team actually *works*: a one-tap daily follow-up log with an exact "seen X of Y today" count, a real patient lookup on create so a mistyped MRN warns instead of silently filing an orphan row, one in-app notification when a coordinator books a consult into someone else's book, and a visible distinction between who *typed* a record (`entered_by`) and who *owns* it (`consultant_id` + `owning_specialty_id`). It deliberately leaves the printable service handover view to W3 and every ageing/turnaround chart to W4 — those need weeks of real transitions before they show anything true.

Two facts anchor everything below. First, `entered_by` is stamped from `Auth::id()` inside the controller and appears in **no** validation rule, so it can never ride the request payload — every task here preserves that. Second, `consultation_followups` carries `unique(consultation_id, followup_date)`; the endpoint treats a second tick on the same day as a **friendly 422, never a silent overwrite**, because the row is append-only (there is no `updated_at`) and the completeness count must stay exact.

> **Merge note for the assembler.** Waves W1, W2a and W2b all edit `app/Http/Controllers/ConsultationsController.php`, `app/Http/Requests/ConsultationRequest.php` and `resources/js/Pages/Consultations/Index.vue`. This wave only *adds* — a `followup()` action, a `todayWorklist()` private helper, one extra key in the `Inertia::render` payload of `index()`, extra rules in `ConsultationRequest`, and new blocks in the Vue page. Nothing here rewrites an existing line except the two clearly-marked edits inside `store()`/`update()`.

---

### Task 16: Record a daily follow-up (`POST /consultations/{consultation}/followup`)

The `consultation_followups` table, the `Consultation::followups()` relation, the four `STATUS_*` constants and `User::canSeeConsultation()` all land in Wave 1. This task adds the model class, the endpoint, its authorization, and the single automatic status transition in the whole design.

The endpoint answers **JSON**, not an Inertia redirect: the worklist ticks rows off in place with `fetch()`, so a refused tick must come back as a plain 422 the panel can render inline rather than as an Inertia error overlay. Non-`api/*` routes do not auto-render JSON validation errors in this app, which is exactly what `Controller::jsonValidate()` / `jsonFail()` exist for.

**Files:**
- Create: `laravel/app/Models/ConsultationFollowup.php`
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Modify: `laravel/routes/web.php`
- Test: `laravel/tests/Feature/ConsultationFollowupTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationFollowupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 2b — the daily follow-up log.
 *
 * Rules pinned here:
 *  - Observers are refused BEFORE any capability flag (the global read-only guarantee).
 *  - Only someone who can SEE the consult (own specialty / coordinator / admin) may tick it.
 *  - A signed-off consult is closed: no follow-up may be appended to it.
 *  - One tick per consult per day — a second tick is a friendly 422, never a silent overwrite.
 *  - The FIRST tick on a `new` consult promotes it to `active`. That is the ONLY automatic
 *    status transition in the design.
 */
class ConsultationFollowupTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cfu_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Followup User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function specialty(string $name): Specialty
    {
        return Specialty::firstOrCreate(['name' => $name], ['is_subspecialty' => true, 'is_external' => false]);
    }

    private function consult(Specialty $spec, array $extra = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999), 'patient_name' => 'Followup Pt', 'age' => 55,
            'bed' => 'W-3', 'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => $spec->name, 'indication' => [1],
            'owning_specialty_id' => $spec->id, 'status' => Consultation::STATUS_NEW,
        ], $extra));
    }

    public function test_first_followup_on_a_new_consult_records_a_row_and_promotes_it_to_active(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id]);

        $res = $this->actingAs($doc)
            ->postJson("/consultations/{$c->id}/followup", ['note' => 'Seen, stable, continue plan'])
            ->assertOk();

        $this->assertTrue($res->json('ok'));
        $this->assertTrue($res->json('promoted'));
        $this->assertSame(Consultation::STATUS_ACTIVE, $res->json('status'));
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);

        $f = ConsultationFollowup::where('consultation_id', $c->id)->firstOrFail();
        $this->assertSame(now()->toDateString(), $f->followup_date->toDateString());
        $this->assertSame('Seen, stable, continue plan', $f->note);
        $this->assertSame($doc->id, (int) $f->author_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'consultation.followup', 'entity_id' => (string) $c->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'consultation.status_change', 'entity_id' => (string) $c->id]);
    }

    public function test_a_followup_on_an_active_consult_stores_the_note_without_changing_status(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id, 'status' => Consultation::STATUS_ACTIVE]);

        $res = $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", [])->assertOk();

        $this->assertFalse($res->json('promoted'));
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
        $this->assertNull(ConsultationFollowup::where('consultation_id', $c->id)->firstOrFail()->note);
    }

    public function test_a_second_followup_the_same_day_is_rejected_and_leaves_exactly_one_row(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id, 'status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => 'first'])->assertOk();
        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => 'second'])
            ->assertStatus(422)
            ->assertJsonPath('errors.note.0', 'A follow-up is already recorded for this consultation today.');

        $this->assertSame(1, ConsultationFollowup::where('consultation_id', $c->id)->count());
        $this->assertSame('first', ConsultationFollowup::where('consultation_id', $c->id)->firstOrFail()->note);
    }

    public function test_a_followup_on_a_signed_off_consult_is_refused(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, [
            'consultant_id' => $doc->id, 'status' => Consultation::STATUS_SIGNED_OFF,
            'signoff_date' => now()->toDateString(),
        ]);

        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => 'late note'])
            ->assertStatus(422);

        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }

    public function test_an_observer_can_never_record_a_followup(): void
    {
        $cardio = $this->specialty('Cardiology');
        // capability flags set ON PURPOSE: read-only must win over every one of them
        $obs = $this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id, 'can_manage' => 1, 'can_modify' => 1]);
        $c = $this->consult($cardio, ['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($obs)->postJson("/consultations/{$c->id}/followup", [])->assertForbidden();
        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }

    public function test_a_user_from_another_specialty_cannot_record_a_followup(): void
    {
        $cardio = $this->specialty('Cardiology');
        $nephro = $this->specialty('Nephrology');
        $outsider = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $c = $this->consult($cardio, ['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($outsider)->postJson("/consultations/{$c->id}/followup", [])->assertForbidden();
        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }

    public function test_an_admin_may_record_a_followup_on_any_specialtys_consult(): void
    {
        $cardio = $this->specialty('Cardiology');
        $admin = $this->user(User::ROLE_ADMIN);
        $c = $this->consult($cardio, ['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($admin)->postJson("/consultations/{$c->id}/followup", ['note' => 'admin tick'])->assertOk();
        $this->assertSame(1, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }

    public function test_a_note_longer_than_500_characters_is_rejected(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, ['consultant_id' => $doc->id, 'status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($doc)->postJson("/consultations/{$c->id}/followup", ['note' => str_repeat('x', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
        $this->assertSame(0, ConsultationFollowup::where('consultation_id', $c->id)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationFollowupTest
```

Expected failure: `Error: Class "App\Models\ConsultationFollowup" not found` on the first test, and the remaining tests fail with `Expected response status code [200] but received 405` (`POST /consultations/{id}/followup` is not a registered route yet).

- [ ] **Step 3: Write the implementation**

Create `laravel/app/Models/ConsultationFollowup.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "seen today" tick against a consultation.
 *
 * Append-only, exactly like handover_revisions: `created_at` only (DB default useCurrent), no
 * `updated_at`, and a unique (consultation_id, followup_date) in the migration. That uniqueness is
 * the correctness guarantee behind the worklist's "seen 8 of 12 today" — a consult cannot be
 * double-counted, and a tick is never rewritten after the fact.
 */
class ConsultationFollowup extends Model
{
    public $timestamps = false;          // created_at only (DB default useCurrent)

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['followup_date' => 'date', 'created_at' => 'datetime'];
    }

    public function consultation(): BelongsTo { return $this->belongsTo(Consultation::class); }

    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
}
```

In `laravel/app/Http/Controllers/ConsultationsController.php`, add these imports next to the existing ones (`use Illuminate\Http\RedirectResponse;` … `use Illuminate\Support\Facades\Auth;`):

```php
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
```

Then append this method to the class, immediately after `signoff()` (i.e. before `reverseSignoff()`):

```php
    /**
     * POST /consultations/{consultation}/followup — record TODAY's "seen" tick.
     *
     * JSON in / JSON out: the worklist ticks rows off with fetch() and renders a refusal inline, so
     * this must answer a plain 422 rather than an Inertia redirect. Non-api/* routes do not get
     * Laravel's automatic exception-to-JSON rendering, which is what jsonValidate()/jsonFail() are for.
     *
     * Order of checks matters:
     *   1. Observers are read-only BEFORE any capability flag (the global guarantee).
     *   2. The actor must be able to SEE the consult (own specialty / coordinator / admin).
     *   3. A signed-off consult is closed — nothing may be appended to it.
     *   4. One tick per consult per day. A second tick the same day is REJECTED with a friendly 422,
     *      never a silent overwrite: the row is append-only and "seen X of Y" must stay exact.
     *   5. The FIRST tick on a `new` consult promotes it to `active` — the single automatic status
     *      transition in the whole design, because "not seen" has just become untrue.
     */
    public function followup(Request $request, Consultation $consultation): JsonResponse
    {
        $user = Auth::user();
        if ($user->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }
        if (! $user->canSeeConsultation($consultation)) {
            throw new AccessDeniedHttpException('This consultation belongs to another service.');
        }

        $data = $this->jsonValidate($request, ['note' => ['nullable', 'string', 'max:500']]);
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        if ($consultation->status === Consultation::STATUS_SIGNED_OFF) {
            $this->jsonFail(['note' => 'This consultation is signed off — no further follow-up can be recorded.']);
        }

        $today = now()->toDateString();
        if ($consultation->followups()->whereDate('followup_date', $today)->exists()) {
            $this->jsonFail(['note' => 'A follow-up is already recorded for this consultation today.']);
        }

        $promoted = false;
        try {
            DB::transaction(function () use ($consultation, $note, $today, &$promoted) {
                $consultation->followups()->create([
                    'followup_date' => $today,
                    'note' => $note,
                    'author_id' => Auth::id(),          // session-sourced, never from the payload
                ]);
                if ($consultation->status === Consultation::STATUS_NEW) {
                    $consultation->update(['status' => Consultation::STATUS_ACTIVE]);
                    $promoted = true;
                }
                Audit::log('consultation.followup', 'consultation', (string) $consultation->id, [
                    'followup_date' => $today,
                    'has_note' => $note !== null,
                ]);
                if ($promoted) {
                    Audit::log('consultation.status_change', 'consultation', (string) $consultation->id, [
                        'from' => Consultation::STATUS_NEW,
                        'to' => Consultation::STATUS_ACTIVE,
                        'reason' => 'first_followup',
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // lost a race with a concurrent tick (double-click / two devices) — same friendly answer
            // as the pre-check above rather than a 500 on a live clinical page
            $this->jsonFail(['note' => 'A follow-up is already recorded for this consultation today.']);
        }

        return response()->json([
            'ok' => true,
            'status' => $consultation->fresh()->status,
            'promoted' => $promoted,
            'followup_date' => $today,
        ]);
    }
```

In `laravel/routes/web.php`, add the route directly beneath the existing sign-off route (`Route::post('/consultations/{consultation}/signoff', …)`):

```php
    // Wave 2b: the daily "seen today" tick. JSON in/out (the worklist calls it with fetch()), one
    // row per consult per day enforced by a DB unique — a repeat tick answers 422, never overwrites.
    Route::post('/consultations/{consultation}/followup', [ConsultationsController::class, 'followup'])->name('consultations.followup');
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationFollowupTest
```

Expected: `OK (8 tests, ...)` — PASS, all 8 green.

Then confirm nothing else moved:

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test
```

Expected: the whole suite still PASSes.

- [ ] **Step 5: Commit**

```
cd laravel && git add app/Models/ConsultationFollowup.php app/Http/Controllers/ConsultationsController.php routes/web.php tests/Feature/ConsultationFollowupTest.php && git commit -m "feat(consultations): daily follow-up tick endpoint (one per consult per day, first tick promotes new -> active)"
```

---

### Task 17: Today's follow-up worklist on the consultations workspace

The `active` set is the only status that asserts a daily commitment, so the worklist shows exactly that set — scoped to what the viewer can see — with a one-tap check-off, an optional one-line note, and an exact "Seen X of Y today".

**Files:**
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Modify: `laravel/resources/js/Pages/Consultations/Index.vue`
- Test: `laravel/tests/Feature/ConsultationWorklistTest.php`
- Test: `laravel/resources/js/__tests__/ConsultationsIndex.worklist.test.js`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationWorklistTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Wave 2b — the `worklist` prop behind "Today's follow-up".
 * It carries ONLY the `active` set the viewer can see, each row flagged with whether it has
 * already been ticked today, plus the exact seen/total pair the completeness indicator renders.
 */
class ConsultationWorklistTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cwl_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Worklist User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function specialty(string $name): Specialty
    {
        return Specialty::firstOrCreate(['name' => $name], ['is_subspecialty' => true, 'is_external' => false]);
    }

    private function consult(Specialty $spec, string $status, array $extra = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999), 'patient_name' => 'Worklist Pt', 'age' => 44,
            'bed' => 'W-1', 'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => $spec->name, 'indication' => [1],
            'owning_specialty_id' => $spec->id, 'status' => $status,
        ], $extra));
    }

    public function test_worklist_holds_only_the_active_set_with_an_exact_seen_of_total(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $ticked = $this->consult($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => 'Aaa Ticked', 'consultant_id' => $doc->id]);
        $this->consult($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => 'Bbb Pending', 'consultant_id' => $doc->id]);
        $this->consult($cardio, Consultation::STATUS_ONGOING, ['patient_name' => 'Ccc Ongoing', 'consultant_id' => $doc->id]);
        $this->consult($cardio, Consultation::STATUS_NEW, ['patient_name' => 'Ddd New', 'consultant_id' => $doc->id]);

        $this->actingAs($doc)->postJson("/consultations/{$ticked->id}/followup", ['note' => 'seen'])->assertOk();

        $this->actingAs($doc)->get('/consultations')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('worklist.total', 2)
                ->where('worklist.seen', 1)
                ->where('worklist.date', now()->toDateString())
                ->where('worklist.items.0.name', 'Aaa Ticked')
                ->where('worklist.items.0.seen_today', true)
                ->where('worklist.items.1.name', 'Bbb Pending')
                ->where('worklist.items.1.seen_today', false)
        );
    }

    public function test_worklist_never_leaks_another_specialtys_consults(): void
    {
        $cardio = $this->specialty('Cardiology');
        $nephro = $this->specialty('Nephrology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => 'Mine', 'consultant_id' => $doc->id]);
        $this->consult($nephro, Consultation::STATUS_ACTIVE, ['patient_name' => 'Theirs']);

        $this->actingAs($doc)->get('/consultations')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('worklist.total', 1)
                ->where('worklist.items.0.name', 'Mine')
        );
    }

    public function test_yesterdays_tick_does_not_count_as_seen_today(): void
    {
        $cardio = $this->specialty('Cardiology');
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $c = $this->consult($cardio, Consultation::STATUS_ACTIVE, ['consultant_id' => $doc->id]);
        $c->followups()->create([
            'followup_date' => now()->subDay()->toDateString(),
            'note' => 'yesterday', 'author_id' => $doc->id,
        ]);

        $this->actingAs($doc)->get('/consultations')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('worklist.total', 1)->where('worklist.seen', 0)
        );
    }
}
```

Create `laravel/resources/js/__tests__/ConsultationsIndex.worklist.test.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Wave 2b — "Today's follow-up" panel on Consultations/Index.vue: the completeness indicator, the
// one-tap check-off (a JSON fetch, not an Inertia visit) and the inline refusal message.

const { post, put, deleteFn, ask } = vi.hoisted(() => ({
    post: vi.fn(), put: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(),
}));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => reactive({
        ...obj, errors: {}, processing: false,
        post: vi.fn((...a) => post(...a)),
        put: vi.fn((...a) => put(...a)),
        reset: vi.fn(), clearErrors: vi.fn(),
    }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/BaseModal.vue', () => ({
    default: {
        props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable', 'dirty'],
        emits: ['close'],
        template: '<div v-if="open"><slot /></div>',
    },
}));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';

const doc = { role: 3, is_admin: false, id: 9, can: { manage: false } };
const baseProps = () => ({
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { active: 0, total: 0, mine_active: 0 },
    reasons: [], consultants: [], specialties: [],
    worklist: {
        date: '2026-08-21', seen: 1, total: 2,
        items: [
            { id: 11, name: 'Aaa Ticked', mrn: '1001', bed: 'W-1', location: 'Ward', consultant: 'Dr A', seen_today: true },
            { id: 12, name: 'Bbb Pending', mrn: '1002', bed: 'W-2', location: 'Ward', consultant: 'Dr A', seen_today: false },
        ],
    },
});
const mountWith = (over = {}) => { authUser = doc; return mount(ConsultationsIndex, { props: { ...baseProps(), ...over } }); };

beforeEach(() => {
    post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset();
    global.fetch = vi.fn();
});

describe("Consultations/Index — Today's follow-up worklist", () => {
    it('renders the completeness indicator from the worklist prop', () => {
        const w = mountWith();
        expect(w.text()).toContain('Seen 1 of 2 today');
    });

    it('is hidden entirely when the active set is empty', () => {
        const w = mountWith({ worklist: { date: '2026-08-21', seen: 0, total: 0, items: [] } });
        expect(w.find('[data-test="worklist"]').exists()).toBe(false);
    });

    it('markSeen POSTs the note to the followup endpoint and flips the row', async () => {
        global.fetch.mockResolvedValue({ ok: true, json: async () => ({ ok: true, status: 'active', promoted: false }) });
        const w = mountWith();
        w.vm.wlNotes[12] = '  Reviewed, no change  ';
        await w.vm.markSeen(w.vm.wl.items[1]);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe('/consultations/12/followup');
        expect(opts.method).toBe('POST');
        expect(JSON.parse(opts.body)).toEqual({ note: 'Reviewed, no change' });
        expect(w.vm.wl.items[1].seen_today).toBe(true);
        expect(w.vm.wlSeen).toBe(2);
    });

    it('sends a null note when the box is empty', async () => {
        global.fetch.mockResolvedValue({ ok: true, json: async () => ({ ok: true, status: 'active', promoted: false }) });
        const w = mountWith();
        await w.vm.markSeen(w.vm.wl.items[1]);
        expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({ note: null });
    });

    it('shows the server refusal inline and leaves the row unticked', async () => {
        global.fetch.mockResolvedValue({
            ok: false, status: 422,
            json: async () => ({ message: 'A follow-up is already recorded for this consultation today.' }),
        });
        const w = mountWith();
        await w.vm.markSeen(w.vm.wl.items[1]);
        await w.vm.$nextTick();

        expect(w.vm.wl.items[1].seen_today).toBe(false);
        expect(w.text()).toContain('A follow-up is already recorded for this consultation today.');
    });

    it('ignores a second click while a tick is already in flight', async () => {
        let release;
        global.fetch.mockReturnValue(new Promise((r) => { release = r; }));
        const w = mountWith();
        const first = w.vm.markSeen(w.vm.wl.items[1]);
        await w.vm.markSeen(w.vm.wl.items[0]);
        expect(global.fetch).toHaveBeenCalledTimes(1);
        release({ ok: true, json: async () => ({ ok: true }) });
        await first;
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationWorklistTest
```

Expected failure: `Inertia property [worklist] does not exist.`

```
cd laravel && npx vitest run resources/js/__tests__/ConsultationsIndex.worklist.test.js
```

Expected failure: `AssertionError: expected '…' to contain 'Seen 1 of 2 today'`, and `TypeError: Cannot read properties of undefined (reading 'items')` from `w.vm.wl`.

- [ ] **Step 3: Write the implementation**

In `laravel/app/Http/Controllers/ConsultationsController.php`, add the model import beside the existing ones:

```php
use App\Models\ConsultationFollowup;
```

Add `'worklist' => $this->todayWorklist(Auth::user()),` to the array passed to `Inertia::render('Consultations/Index', [...])` in `index()` — place it directly after the `'stats' => [...]` entry:

```php
            // Wave 2b: "Today's follow-up" — the ACTIVE set the viewer can see, with per-row
            // "already ticked today" and the exact seen/total pair behind "Seen X of Y today".
            'worklist' => $this->todayWorklist(Auth::user()),
```

Add this private method at the end of the class:

```php
    /**
     * Today's follow-up worklist.
     *
     * `active` is the ONLY status that asserts a daily commitment, so `new`, `ongoing` and
     * `signed_off` are deliberately excluded — that is exactly why the Wave 1 backfill parked open
     * legacy consults in `ongoing` rather than fabricating a launch-day worklist of 1,283 rows.
     * The count is exact because consultation_followups carries unique(consultation_id, followup_date).
     */
    private function todayWorklist(User $user): array
    {
        $today = now()->toDateString();

        $rows = Consultation::query()
            ->visibleTo($user)
            ->where('status', Consultation::STATUS_ACTIVE)
            ->with('consultant:id,full_name,name')
            ->withExists(['followups as seen_today' => fn ($q) => $q->whereDate('followup_date', $today)])
            ->orderBy('patient_name')
            ->orderBy('id')
            ->get();

        return [
            'date' => $today,
            'seen' => $rows->where('seen_today', true)->count(),
            'total' => $rows->count(),
            'items' => $rows->map(fn (Consultation $c) => [
                'id' => $c->id,
                'name' => $c->patient_name ?? 'Unknown',
                'mrn' => $c->mrn,
                'bed' => $c->bed,
                'location' => $c->current_location,
                'consultant' => $c->consultant?->full_name ?? $c->consultant?->name ?? '—',
                'seen_today' => (bool) $c->seen_today,
            ])->values()->all(),
        ];
    }
```

In `laravel/resources/js/Pages/Consultations/Index.vue`, extend the `defineProps` call (line 20) to accept the new prop:

```js
const props = defineProps({ consultations: Object, filters: Object, stats: Object, reasons: Array, consultants: Array, specialties: Array, worklist: { type: Object, default: () => ({ date: '', seen: 0, total: 0, items: [] }) } });
```

Add `xsrf` to the existing `@/lib/ui.js` import (line 9):

```js
import { localToday, vFocus, guardSubmit, formatDate, xsrf } from '@/lib/ui.js';
```

Add this block to the `<script setup>`, directly after the `toggleMine` definition (line 53):

```js
// ---- Wave 2b: Today's follow-up worklist ------------------------------------------------------
// A local mirror of the server prop so a tick flips instantly without a full Inertia round-trip.
// The tick is a JSON fetch (not an Inertia visit) because the server answers 422 on a repeat tick
// — one row per consult per day is a DB unique — and that refusal is rendered inline, right here.
const wl = ref({ ...props.worklist, items: [...(props.worklist?.items || [])] });
watch(() => props.worklist, (v) => { wl.value = { ...v, items: [...(v?.items || [])] }; });
const wlNotes = ref({});
const wlBusy = ref(null);
const wlError = ref('');
const wlSeen = computed(() => wl.value.items.filter((i) => i.seen_today).length);
const wlPct = computed(() => (wl.value.total ? Math.round((wlSeen.value / wl.value.total) * 100) : 0));
const markSeen = async (item) => {
    if (wlBusy.value || item.seen_today) return;
    wlBusy.value = item.id;
    wlError.value = '';
    try {
        const r = await fetch(`/consultations/${item.id}/followup`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ note: (wlNotes.value[item.id] || '').trim() || null }),
        });
        const body = await r.json().catch(() => ({}));
        if (!r.ok) { wlError.value = body.message || 'Could not record the follow-up.'; return; }
        item.seen_today = true;
        wlNotes.value[item.id] = '';
    } catch {
        wlError.value = 'Could not record the follow-up.';
    } finally {
        wlBusy.value = null;
    }
};
```

Add this panel to the template, immediately after the closing `</div>` of the filter bar (the `<div class="mb-5 flex flex-wrap items-center gap-3">` block that ends on line 144) and before the results card:

```html
        <!-- Today's follow-up: the ACTIVE set only — the one status that asserts a daily round -->
        <section v-if="wl.total" data-test="worklist" class="mb-5 overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
            <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                <h2 class="text-sm font-bold text-ink-800">Today's follow-up</h2>
                <span class="nums rounded-full bg-tint-accent px-2.5 py-0.5 text-xs font-semibold text-on-accent">Seen {{ wlSeen }} of {{ wl.total }} today</span>
                <div class="ms-auto h-2 w-40 overflow-hidden rounded-full bg-ink-100">
                    <div class="h-full rounded-full bg-brand-solid transition-all" :style="{ width: wlPct + '%' }" />
                </div>
            </header>
            <p v-if="wlError" role="alert" class="border-b border-line bg-tint-danger px-5 py-2 text-xs font-semibold text-on-danger">{{ wlError }}</p>
            <ul class="divide-y divide-line">
                <li v-for="item in wl.items" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <div class="min-w-48">
                        <div class="font-semibold text-ink-800">{{ item.name }}</div>
                        <div class="nums text-xs text-ink-400">MRN {{ item.mrn }} · Bed {{ item.bed || '—' }} · {{ item.location || '—' }} · {{ item.consultant }}</div>
                    </div>
                    <input v-if="!item.seen_today" v-model="wlNotes[item.id]" :aria-label="`Follow-up note for ${item.name}`"
                        placeholder="Optional one-line note…" maxlength="500"
                        class="ms-auto w-64 rounded-xl border border-ink-200 px-3 py-1.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
                    <span v-if="item.seen_today" class="ms-auto rounded-full bg-tint-success px-2.5 py-0.5 text-xs font-semibold text-on-success">Seen today</span>
                    <button v-else type="button" :disabled="wlBusy === item.id" @click="markSeen(item)"
                        class="rounded-xl bg-brand-solid px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-brand-solid-hover disabled:opacity-50">Mark seen</button>
                </li>
            </ul>
            <span class="sr-only" aria-live="polite" aria-atomic="true">Seen {{ wlSeen }} of {{ wl.total }} today</span>
        </section>
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationWorklistTest
```

Expected: `OK (3 tests, ...)` — PASS.

```
cd laravel && npx vitest run resources/js/__tests__/ConsultationsIndex.worklist.test.js
```

Expected: `Test Files  1 passed (1)` / `Tests  5 passed (5)`.

```
cd laravel && npx vitest run
```

Expected: all suites PASS (`ConsultationsIndex.wave2.test.js` and `.wave3.test.js` included — they mount without a `worklist` prop and the default keeps `wl.total === 0`, so the panel simply does not render).

Now rebuild the committed bundle, in this order:

```
cd laravel && npx vite build
cd laravel && node scripts/check-source-allowlist.mjs --write
cd laravel && node scripts/check-source-allowlist.mjs
cd laravel && node scripts/contrast.mjs
```

Expected: `vite build` writes `public/build`; the second allowlist run prints `PASS`; `contrast.mjs` prints `PASS`.

- [ ] **Step 5: Commit**

```
cd laravel && git add app/Http/Controllers/ConsultationsController.php resources/js/Pages/Consultations/Index.vue tests/Feature/ConsultationWorklistTest.php resources/js/__tests__/ConsultationsIndex.worklist.test.js public/build scripts/check-source-allowlist.mjs && git commit -m "feat(consultations): Today's follow-up worklist with one-tap check-off and exact seen-of-total"
```

---

### Task 18: Warn on an MRN that matches no patient, and link the picked patient + admission

Today `store()` runs `Patient::where('mrn', $data['mrn'])->first()` and, on no match, silently writes `patient_id = NULL` — an orphan consultation nobody can ever join back to a patient. From here the create form must either carry a `patient_id` chosen from the lookup, or an explicit `unmatched_mrn_ack` saying "yes, file it unlinked". **Edit is untouched**: 1,283 historical rows carry unmatched MRNs and must stay save-able.

Two existing tests post an unmatched MRN on create and expect success. They are **deliberately updated** (never deleted) to carry the acknowledgement, which is precisely the behaviour change this task ships.

**Files:**
- Modify: `laravel/app/Http/Requests/ConsultationRequest.php`
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Modify: `laravel/tests/Feature/ResidualR3Test.php`
- Modify: `laravel/tests/Feature/Round4H1Test.php`
- Test: `laravel/tests/Feature/ConsultationPatientLookupTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationPatientLookupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 2b — patient lookup on create.
 *
 * An MRN that matches no patient record used to store patient_id = NULL in silence. It now WARNS:
 * the user either picks the patient from POST /api/patients/quick-search (patient_id +
 * admission_id ride the body) or explicitly acknowledges the unmatched MRN. EDIT keeps the legacy
 * behaviour so historical rows stay editable. entered_by remains session-sourced and unspoofable.
 */
class ConsultationPatientLookupTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cpl_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Lookup User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function payload(array $overrides = []): array
    {
        $cardio = Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
        $reason = ConsultationReason::firstOrCreate(['name' => 'Lookup Reason']);
        $consultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);

        return array_merge([
            'mrn' => '70001111', 'patient_name' => 'Lookup Pt', 'age' => 52, 'bed' => 'W-4',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology',
            'consultant_id' => $consultant->id, 'indication' => [$reason->id],
        ], $overrides);
    }

    public function test_an_unmatched_mrn_is_refused_with_a_warning_instead_of_creating_an_orphan(): void
    {
        $this->actingAs($this->user(User::ROLE_REGISTRAR))->from('/consultations')
            ->post('/consultations', $this->payload())
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('mrn');

        $this->assertSame(0, Consultation::count(), 'an unmatched MRN must not silently create an orphan row');
    }

    public function test_acknowledging_the_warning_files_the_consultation_unlinked(): void
    {
        $this->actingAs($this->user(User::ROLE_REGISTRAR))
            ->post('/consultations', $this->payload(['unmatched_mrn_ack' => true]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::firstOrFail();
        $this->assertSame('70001111', (string) $c->mrn);
        $this->assertNull($c->patient_id);
        $this->assertNull($c->admission_id);
    }

    public function test_a_matching_mrn_links_the_patient_with_no_acknowledgement_needed(): void
    {
        $p = Patient::create(['mrn' => '70001111', 'name' => 'Lookup Pt', 'age' => 52]);

        $this->actingAs($this->user(User::ROLE_REGISTRAR))
            ->post('/consultations', $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($p->id, (int) Consultation::firstOrFail()->patient_id);
    }

    public function test_picking_a_patient_links_both_the_patient_and_the_admission(): void
    {
        $p = Patient::create(['mrn' => '70002222', 'name' => 'Picked Pt', 'age' => 61]);
        $a = Admission::create([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'current_location' => 'Ward', 'bed' => 'W-9', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);

        $this->actingAs($this->user(User::ROLE_REGISTRAR))
            ->post('/consultations', $this->payload([
                'mrn' => '70002222', 'patient_id' => $p->id, 'admission_id' => $a->id,
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::firstOrFail();
        $this->assertSame($p->id, (int) $c->patient_id);
        $this->assertSame($a->id, (int) $c->admission_id);
    }

    public function test_an_admission_belonging_to_someone_else_is_ignored(): void
    {
        $mine = Patient::create(['mrn' => '70003333', 'name' => 'Mine', 'age' => 40]);
        $other = Patient::create(['mrn' => '70004444', 'name' => 'Other', 'age' => 40]);
        $otherAdmission = Admission::create([
            'patient_id' => $other->id, 'admit_date' => now()->subDay()->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);

        $this->actingAs($this->user(User::ROLE_REGISTRAR))
            ->post('/consultations', $this->payload([
                'mrn' => '70003333', 'patient_id' => $mine->id, 'admission_id' => $otherAdmission->id,
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::firstOrFail();
        $this->assertSame($mine->id, (int) $c->patient_id);
        $this->assertNull($c->admission_id, 'an admission that is not this patient\'s must never be attached');
    }

    public function test_entered_by_is_session_sourced_and_cannot_be_set_from_the_payload(): void
    {
        Patient::create(['mrn' => '70001111', 'name' => 'Lookup Pt', 'age' => 52]);
        $actor = $this->user(User::ROLE_REGISTRAR);
        $victim = $this->user(User::ROLE_CONSULTANT);

        $this->actingAs($actor)
            ->post('/consultations', $this->payload(['entered_by' => $victim->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($actor->id, (int) Consultation::firstOrFail()->entered_by);
    }

    public function test_editing_a_legacy_consultation_with_an_unmatched_mrn_still_saves(): void
    {
        $cardio = Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
        $admin = $this->user(User::ROLE_ADMIN);
        $c = Consultation::create([
            'mrn' => '99999999', 'patient_name' => 'Legacy Pt', 'age' => 70, 'bed' => 'W-2',
            'current_location' => 'Ward', 'consultation_date' => '2019-05-01', 'consultation_from' => 'ER',
            'to_service' => 'Cardiology', 'consultant_id' => $admin->id, 'indication' => [1],
            'owning_specialty_id' => $cardio->id,
        ]);

        $this->actingAs($admin)->put("/consultations/{$c->id}", [
            'mrn' => '99999999', 'patient_name' => 'Legacy Pt Edited', 'age' => 70, 'bed' => 'W-2',
            'current_location' => 'Ward', 'consultation_date' => '2019-05-01', 'consultation_from' => 'ER',
            'to_service' => 'Cardiology', 'consultant_id' => $admin->id, 'indication' => [1],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Legacy Pt Edited', $c->fresh()->patient_name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationPatientLookupTest
```

Expected failure: `test_an_unmatched_mrn_is_refused_with_a_warning_instead_of_creating_an_orphan` fails with `Session is missing expected key [errors]` (the unmatched MRN is still accepted today), and `test_picking_a_patient_links_both_the_patient_and_the_admission` fails with `Failed asserting that null matches expected <admission id>`.

- [ ] **Step 3: Write the implementation**

In `laravel/app/Http/Requests/ConsultationRequest.php`, add these three rules to the `$rules` array in `rules()`, immediately after the `'other_indication' => [...]` entry:

```php
            // Wave 2b — patient lookup. The create form resolves the patient through
            // POST /api/patients/quick-search (term in the BODY — PHI never enters a URL) and posts
            // the chosen ids alongside the typed MRN. `unmatched_mrn_ack` is the user's explicit
            // "file it unlinked anyway" for an MRN that matches no patient record.
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'admission_id' => ['nullable', 'integer', 'exists:admissions,id'],
            'unmatched_mrn_ack' => ['nullable', 'boolean'],
```

Add this method to the same class, directly after `rules()`:

```php
    /**
     * CREATE-only guard against the silent orphan: an MRN matching no patient record used to store
     * `patient_id = NULL` without a word, producing a consultation nobody could ever join back to a
     * patient. The user must now either pick the patient from the lookup (`patient_id`) or tick the
     * acknowledgement. The point is the WARNING, not a block — acknowledging always succeeds.
     *
     * EDIT is deliberately exempt: 1,283 imported rows carry MRNs that match nothing and must stay
     * save-able for an unrelated edit (the same reasoning as consultationDateRules()).
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v) {
            if ($this->route('consultation') !== null) {
                return;                                  // update — legacy rows stay editable
            }
            if ($v->errors()->has('mrn')) {
                return;                                  // already failing on shape; don't pile on
            }
            if ($this->filled('patient_id') || $this->boolean('unmatched_mrn_ack')) {
                return;                                  // picked from the lookup, or acknowledged
            }
            $mrn = trim((string) $this->input('mrn'));
            if ($mrn !== '' && \App\Models\Patient::where('mrn', $mrn)->exists()) {
                return;                                  // the typed MRN does resolve
            }
            $v->errors()->add('mrn', "No patient record matches MRN {$mrn}. Pick the patient from the lookup, or tick 'Record anyway' to file this consultation without linking a patient record.");
        });
    }
```

In `laravel/app/Http/Controllers/ConsultationsController.php`, add the import:

```php
use App\Models\Admission;
```

Replace the body of `store()` (the current version reads `$patient = Patient::where('mrn', $data['mrn'])->first();` followed by `Consultation::create([...])` and one `Audit::log`) with:

```php
    public function store(\App\Http\Requests\ConsultationRequest $request): RedirectResponse
    {
        // Observer gate lives in ConsultationRequest::authorize() (403 before validation)
        $data = $request->validated();

        // Wave 2b — patient lookup. A patient PICKED from the lookup wins; otherwise fall back to
        // resolving the typed MRN. An MRN that resolves to nothing only reaches this line when the
        // user explicitly acknowledged it (ConsultationRequest::withValidator refuses it otherwise),
        // so `patient_id = NULL` is now always a deliberate choice, never a silent accident.
        $ack = (bool) ($data['unmatched_mrn_ack'] ?? false);
        $pickedPatientId = $data['patient_id'] ?? null;
        $pickedAdmissionId = $data['admission_id'] ?? null;
        // strip the lookup-control fields: `unmatched_mrn_ack` is not a column, and patient/admission
        // are re-derived below rather than mass-assigned from the payload
        unset($data['patient_id'], $data['admission_id'], $data['unmatched_mrn_ack']);

        $patient = $pickedPatientId
            ? Patient::find($pickedPatientId)
            : Patient::where('mrn', $data['mrn'])->first();

        // an admission only attaches when it really belongs to the resolved patient — a mismatched
        // id in the payload is dropped, never trusted
        $admission = ($pickedAdmissionId && $patient)
            ? Admission::where('id', $pickedAdmissionId)->where('patient_id', $patient->id)->first()
            : null;

        $c = Consultation::create([
            ...$data,
            'patient_id' => $patient?->id,
            'admission_id' => $admission?->id,
            'indication' => $data['indication'] ?? [],
            'entered_by' => Auth::id(),                  // session-sourced; never a validation rule
        ]);
        Audit::log('consultation.create', 'consultation', (string) $c->id, [
            'mrn' => $data['mrn'],
            'patient_id' => $patient?->id,
            'admission_id' => $admission?->id,
            'unmatched_mrn_ack' => $ack,
        ]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Consultation created.']);
    }
```

Now the two deliberate updates to existing tests.

In `laravel/tests/Feature/ResidualR3Test.php`, `test_consultation_store_accepts_a_full_legacy_payload()` posts MRN `30001234` with no matching `patients` row. Change its post call from `$this->consultationPayload()` to `$this->consultationPayload(['unmatched_mrn_ack' => true])`, so the line reads:

```php
        $this->actingAs($this->user(User::ROLE_REGISTRAR))
            ->post('/consultations', $this->consultationPayload(['unmatched_mrn_ack' => true]))
            ->assertRedirect()->assertSessionHasNoErrors();
```

In `laravel/tests/Feature/Round4H1Test.php`, `test_external_consultation_stores_without_consultant()` posts MRN `12341234` with no matching patient. Change its post call the same way:

```php
        $this->actingAs($this->admin())->post('/consultations', $this->consultPayload(['unmatched_mrn_ack' => true]))
            ->assertRedirect()->assertSessionHasNoErrors();
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationPatientLookupTest
```

Expected: `OK (7 tests, ...)` — PASS.

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ResidualR3Test
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=Round4H1Test
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test
```

Expected: each PASSes; the full suite is green.

- [ ] **Step 5: Commit**

```
cd laravel && git add app/Http/Requests/ConsultationRequest.php app/Http/Controllers/ConsultationsController.php tests/Feature/ConsultationPatientLookupTest.php tests/Feature/ResidualR3Test.php tests/Feature/Round4H1Test.php && git commit -m "feat(consultations): warn on an unmatched MRN instead of filing an orphan; link picked patient + admission"
```

---

### Task 19: Patient lookup in the New-consultation modal

The lookup reuses the existing `POST /api/patients/quick-search` verbatim — same endpoint, same PHI scoping (admins see full history; everyone else open episodes under the D1 consultant scope), **term in the POST body only**, exactly as `QuickJump.vue` already calls it. Selecting a result fills MRN, name, age, bed and location and pins `patient_id` + `admission_id`. Typing an MRN by hand clears that pin, and the server's warning (Task 18) turns into a visible "Record anyway" acknowledgement.

The response has no `bed` field today, so one additive line is added to `quickSearch()`; nothing else about that endpoint changes.

**Files:**
- Modify: `laravel/app/Http/Controllers/PatientsController.php`
- Modify: `laravel/resources/js/Pages/Consultations/Index.vue`
- Test: `laravel/tests/Feature/QuickSearchTest.php`
- Test: `laravel/resources/js/__tests__/ConsultationsIndex.lookup.test.js`

- [ ] **Step 1: Write the failing test**

Append this method to the `QuickSearchTest` class in `laravel/tests/Feature/QuickSearchTest.php` (just before the closing brace of the class):

```php
    /** Wave 2b: the consultation create form fills Bed from the lookup, so rows must carry it. */
    public function test_rows_carry_the_bed_for_the_consultation_lookup(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->admission($this->patient('Bedded Patient', '40020001'), ['bed' => 'W-12']);

        $res = $this->actingAs($admin)->postJson('/api/patients/quick-search', ['q' => 'Bedded'])->assertOk();

        $this->assertSame('W-12', collect($res->json())->firstWhere('mrn', '40020001')['bed']);
    }
```

Create `laravel/resources/js/__tests__/ConsultationsIndex.lookup.test.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Wave 2b — patient lookup inside the New-consultation modal. The term rides a POST body (PHI must
// never enter a URL); picking a result fills the identity fields and pins patient_id/admission_id;
// retyping the MRN clears the pin; the server's unmatched-MRN warning surfaces a "Record anyway" tick.

const { post, put, deleteFn, ask } = vi.hoisted(() => ({
    post: vi.fn(), put: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(),
}));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => reactive({
        ...obj, errors: {}, processing: false,
        post: vi.fn((...a) => post(...a)),
        put: vi.fn((...a) => put(...a)),
        reset: vi.fn(), clearErrors: vi.fn(),
    }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/BaseModal.vue', () => ({
    default: {
        props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable', 'dirty'],
        emits: ['close'],
        template: '<div v-if="open"><slot /></div>',
    },
}));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';

const admin = { role: 0, is_admin: true, id: 1, can: { manage: true } };
const props = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { active: 0, total: 0, mine_active: 0 },
    reasons: [], consultants: [], specialties: [],
    worklist: { date: '2026-08-21', seen: 0, total: 0, items: [] },
};
const row = {
    id: 501, mrn: '40020001', name: 'Bedded Patient', age: 63, gender: 'Male',
    location: 'Ward', bed: 'W-12', status: 'active', dest: 'board',
};
const mountWith = () => { authUser = admin; return mount(ConsultationsIndex, { props }); };

beforeEach(() => {
    post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset();
    global.fetch = vi.fn();
});

describe('Consultations/Index — patient lookup on create', () => {
    it('searches through a POST body, never a query string', async () => {
        global.fetch.mockResolvedValue({ ok: true, json: async () => [row] });
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupQuery = 'Bedded';
        await w.vm.runLookup();

        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe('/api/patients/quick-search');
        expect(url).not.toContain('?');
        expect(opts.method).toBe('POST');
        expect(JSON.parse(opts.body)).toEqual({ q: 'Bedded' });
        expect(w.vm.lookupResults).toHaveLength(1);
    });

    it('does not search on a one-character term', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupQuery = 'B';
        await w.vm.runLookup();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('picking a patient fills the identity fields and pins patient_id + admission_id', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.pickPatient(row);
        await w.vm.$nextTick();

        expect(w.vm.cForm.mrn).toBe('40020001');
        expect(w.vm.cForm.patient_name).toBe('Bedded Patient');
        expect(w.vm.cForm.age).toBe(63);
        expect(w.vm.cForm.bed).toBe('W-12');
        expect(w.vm.cForm.current_location).toBe('Ward');
        expect(w.vm.cForm.patient_id).toBe(501);
        expect(w.vm.cForm.admission_id).toBe(501);
        expect(w.vm.lookupResults).toEqual([]);
    });

    it('retyping the MRN by hand clears the pinned patient and any acknowledgement', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.pickPatient(row);
        await w.vm.$nextTick();
        w.vm.cForm.unmatched_mrn_ack = true;
        w.vm.cForm.mrn = '40029999';
        await w.vm.$nextTick();

        expect(w.vm.cForm.patient_id).toBe(null);
        expect(w.vm.cForm.admission_id).toBe(null);
        expect(w.vm.cForm.unmatched_mrn_ack).toBe(false);
    });

    it('surfaces the "record anyway" acknowledgement only after the server warns', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.mrn = '40029999';
        await w.vm.$nextTick();
        expect(w.vm.showUnmatchedAck).toBe(false);

        w.vm.cForm.errors = { mrn: 'No patient record matches MRN 40029999.' };
        await w.vm.$nextTick();
        expect(w.vm.showUnmatchedAck).toBe(true);
        expect(w.find('[data-test="unmatched-ack"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=test_rows_carry_the_bed_for_the_consultation_lookup
```

Expected failure: `Undefined array key "bed"`.

```
cd laravel && npx vitest run resources/js/__tests__/ConsultationsIndex.lookup.test.js
```

Expected failure: `TypeError: w.vm.runLookup is not a function`.

- [ ] **Step 3: Write the implementation**

In `laravel/app/Http/Controllers/PatientsController.php`, inside `quickSearch()`'s returned row map, add `bed` directly after the `'location' => $a->current_location,` line:

```php
                'bed' => $a->bed,                       // Wave 2b: the consultation create form fills Bed from the pick
```

In `laravel/resources/js/Pages/Consultations/Index.vue`, extend the create form's `useForm` initialiser (currently `const cForm = useForm({ mrn: '', patient_name: '', ... });`) with the three lookup-control fields:

```js
const cForm = useForm({
    mrn: '', patient_name: '', age: '', bed: '', current_location: 'Ward', consultation_date: today,
    consultation_from: '', to_service: '', consultant_id: '', indication: [], other_indication: '',
    // Wave 2b — patient lookup controls. patient_id/admission_id are pinned by picking a result;
    // unmatched_mrn_ack is the explicit "file it unlinked" the server asks for on a no-match MRN.
    patient_id: null, admission_id: null, unmatched_mrn_ack: false,
});
```

Add this block to `<script setup>` immediately after the `submitAdd` definition:

```js
// ---- Wave 2b: patient lookup on create --------------------------------------------------------
// Same endpoint and same PHI scoping as the global quick-jump: POST /api/patients/quick-search with
// the term in the BODY — a patient name or MRN must never enter a URL (it would land in history,
// proxy and access logs). Picking a row pins patient_id + admission_id so the consult attaches to a
// real stay; typing the MRN by hand unpins it, and the server then warns rather than filing an orphan.
const lookupQuery = ref('');
const lookupResults = ref([]);
const lookupBusy = ref(false);
const lookupError = ref('');
let lookupTimer = null;
let lookupSeq = 0;
const runLookup = async () => {
    const term = lookupQuery.value.trim();
    if (term.length < 2) { lookupResults.value = []; return; }
    const mySeq = ++lookupSeq;
    lookupBusy.value = true;
    lookupError.value = '';
    try {
        const r = await fetch('/api/patients/quick-search', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ q: term }),
        });
        if (mySeq !== lookupSeq) return;
        if (!r.ok) { lookupResults.value = []; lookupError.value = 'Patient search failed.'; return; }
        lookupResults.value = await r.json();
    } catch {
        if (mySeq === lookupSeq) { lookupResults.value = []; lookupError.value = 'Patient search failed.'; }
    } finally {
        if (mySeq === lookupSeq) lookupBusy.value = false;
    }
};
watch(lookupQuery, () => { clearTimeout(lookupTimer); lookupTimer = setTimeout(runLookup, 300); });
let pinning = false;
const pickPatient = (rowData) => {
    pinning = true;                       // the mrn watcher below must not unpin what we just pinned
    cForm.mrn = rowData.mrn || '';
    cForm.patient_name = rowData.name || '';
    cForm.age = rowData.age ?? '';
    cForm.bed = rowData.bed || '';
    cForm.current_location = rowData.location || 'Ward';
    cForm.patient_id = rowData.id ?? null;         // quick-search rows are ADMISSION rows…
    cForm.admission_id = rowData.id ?? null;       // …so the same id is the stay; the server re-checks ownership
    cForm.unmatched_mrn_ack = false;
    lookupResults.value = [];
    lookupQuery.value = '';
    nextTick(() => { pinning = false; });
};
watch(() => cForm.mrn, () => {
    if (pinning) return;
    cForm.patient_id = null;
    cForm.admission_id = null;
    cForm.unmatched_mrn_ack = false;
});
// the server is authoritative about "this MRN matches nothing"; the tick only appears once it says so
const showUnmatchedAck = computed(() => !!cForm.errors.mrn && !cForm.patient_id && String(cForm.mrn).trim() !== '');
```

`pickPatient` sets `patient_id` and `admission_id` from the same row id on purpose: `quickSearch()` returns **admission** rows, and the server (Task 18) resolves `patient_id` from the payload and then keeps `admission_id` only when that admission genuinely belongs to that patient — a mismatch is silently dropped rather than trusted.

Update the `@/lib/ui.js` import to also bring in `xsrf` if Task 17 has not already done so, and add `nextTick` to the Vue import on line 2:

```js
import { ref, watch, computed, useId, nextTick } from 'vue';
```

In the template, inside the **new-consultation** modal only, insert this lookup block as the first child of the `<form @submit.prevent="submitAdd" class="space-y-4">` element, before the `<div class="grid gap-3 sm:grid-cols-2">`:

```html
                    <!-- Patient lookup: POST body only (SPC-TM-011 — PHI never rides a URL) -->
                    <div class="rounded-xl bg-ink-50 p-3">
                        <label :for="cfid('lookup')" class="mb-1 block text-sm font-semibold text-ink-700">Find the patient</label>
                        <input :id="cfid('lookup')" v-model="lookupQuery" autocomplete="off"
                            placeholder="Type a name or MRN to look up an admitted patient…" :class="field" />
                        <p v-if="lookupBusy" class="mt-1 text-xs text-ink-400">Searching…</p>
                        <p v-else-if="lookupError" class="mt-1 text-xs text-on-danger">{{ lookupError }}</p>
                        <ul v-if="lookupResults.length" class="mt-2 divide-y divide-line overflow-hidden rounded-xl bg-card ring-1 ring-line">
                            <li v-for="r in lookupResults" :key="r.id">
                                <button type="button" @click="pickPatient(r)" class="w-full px-3 py-2 text-start transition hover:bg-brand-50/40">
                                    <span class="font-semibold text-ink-800">{{ r.name }}</span>
                                    <span class="nums ms-2 text-xs text-ink-400">MRN {{ r.mrn }} · {{ r.age ?? '—' }}y · Bed {{ r.bed || '—' }} · {{ r.location || '—' }}</span>
                                </button>
                            </li>
                        </ul>
                        <p v-if="cForm.patient_id" class="mt-2 text-xs font-semibold text-on-success">Linked to this patient's current admission.</p>
                    </div>
```

Then, directly beneath the MRN field inside that same modal (after the `<p v-if="cForm.errors.mrn" …>` message), add the acknowledgement:

```html
                            <label v-if="showUnmatchedAck" data-test="unmatched-ack" class="mt-2 flex items-start gap-2 rounded-xl bg-tint-warning p-2 text-xs font-semibold text-on-warning">
                                <input type="checkbox" v-model="cForm.unmatched_mrn_ack" class="mt-0.5" />
                                <span>Record anyway — file this consultation without linking a patient record.</span>
                            </label>
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=QuickSearchTest
```

Expected: `OK (...)` — PASS, including the new bed assertion.

```
cd laravel && npx vitest run resources/js/__tests__/ConsultationsIndex.lookup.test.js
cd laravel && npx vitest run
```

Expected: `Tests  5 passed (5)` for the new file, then every suite PASS.

```
cd laravel && npx vite build
cd laravel && node scripts/check-source-allowlist.mjs --write
cd laravel && node scripts/check-source-allowlist.mjs
cd laravel && node scripts/contrast.mjs
```

Expected: build succeeds, allowlist prints `PASS`, contrast prints `PASS`.

- [ ] **Step 5: Commit**

```
cd laravel && git add app/Http/Controllers/PatientsController.php resources/js/Pages/Consultations/Index.vue tests/Feature/QuickSearchTest.php resources/js/__tests__/ConsultationsIndex.lookup.test.js public/build scripts/check-source-allowlist.mjs && git commit -m "feat(consultations): patient lookup on create (POST-body quick-search, pinned patient + admission, unmatched-MRN acknowledgement)"
```

---

### Task 20: One notification when a coordinator books a consult into someone's book

Placing a consultation currently notifies nobody. The narrow fix: when a user holding the coordinator capability (or an admin, who holds it implicitly) creates a consult owned by **someone else**, or moves an existing consult to a **different** consultant, the new owner gets exactly one `consultation.assigned` bell entry. A consultant entering a consult for themselves raises none — the team already knows. Notification rows are never deleted; they are a retained audit trail.

**Files:**
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Test: `laravel/tests/Feature/ConsultationCoordinatorNotifyTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationCoordinatorNotifyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 2b — coordinator notification (§6.4).
 * A coordinator booking a consult into a team's book is the ONE case where the owning consultant
 * does not already know, so it raises exactly one `consultation.assigned` bell entry. Self-entered
 * consults and consults entered by non-coordinators raise none — no noise.
 */
class ConsultationCoordinatorNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ccn_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Notify User', 'full_name' => 'Dr Notify User',
            'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function cardiology(): Specialty
    {
        return Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
    }

    private function payload(User $owner, array $overrides = []): array
    {
        $reason = ConsultationReason::firstOrCreate(['name' => 'Notify Reason']);
        Patient::firstOrCreate(['mrn' => '71000001'], ['name' => 'Notify Pt', 'age' => 58]);

        return array_merge([
            'mrn' => '71000001', 'patient_name' => 'Notify Pt', 'age' => 58, 'bed' => 'W-6',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology',
            'owning_specialty_id' => $this->cardiology()->id,
            'consultant_id' => $owner->id, 'indication' => [$reason->id],
        ], $overrides);
    }

    public function test_a_coordinator_created_consult_notifies_the_owning_consultant_exactly_once(): void
    {
        $cardio = $this->cardiology();
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1, 'full_name' => 'Dr Owner']);
        $coord = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => 1, 'full_name' => 'Dr Coordinator']);

        $this->actingAs($coord)->post('/consultations', $this->payload($owner))
            ->assertRedirect()->assertSessionHasNoErrors();

        $n = Notification::where('user_id', $owner->id)->where('type', 'consultation.assigned')->get();
        $this->assertCount(1, $n);
        $this->assertSame('Notify Pt', $n[0]->payload['patient_name']);
        $this->assertSame('Dr Coordinator', $n[0]->payload['by_name']);
        $this->assertSame('created', $n[0]->payload['event']);
        $this->assertSame(Consultation::firstOrFail()->id, (int) $n[0]->payload['consultation_id']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'consultation.assign']);
    }

    public function test_a_self_entered_consult_notifies_nobody(): void
    {
        $cardio = $this->cardiology();
        $coord = $this->user(User::ROLE_CONSULTANT, [
            'specialty_id' => $cardio->id, 'on_service' => 1, 'can_coordinate_consultations' => 1,
        ]);

        $this->actingAs($coord)->post('/consultations', $this->payload($coord))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, Notification::where('type', 'consultation.assigned')->count());
    }

    public function test_a_non_coordinator_entering_for_a_colleague_notifies_nobody(): void
    {
        $cardio = $this->cardiology();
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);
        $plain = $this->user(User::ROLE_RESIDENT, ['specialty_id' => $cardio->id]);

        $this->actingAs($plain)->post('/consultations', $this->payload($owner))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, Notification::where('type', 'consultation.assigned')->count());
    }

    public function test_reassigning_to_a_new_consultant_notifies_the_new_owner_only(): void
    {
        $cardio = $this->cardiology();
        $first = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);
        $second = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);
        $coord = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => 1]);

        $c = Consultation::create([
            'mrn' => '71000002', 'patient_name' => 'Reassign Pt', 'age' => 49, 'bed' => 'W-7',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'owning_specialty_id' => $cardio->id, 'consultant_id' => $first->id,
        ]);

        $this->actingAs($coord)->put("/consultations/{$c->id}", [
            'mrn' => '71000002', 'patient_name' => 'Reassign Pt', 'age' => 49, 'bed' => 'W-7',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'consultant_id' => $second->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Notification::where('user_id', $second->id)->where('type', 'consultation.assigned')->count());
        $this->assertSame(0, Notification::where('user_id', $first->id)->where('type', 'consultation.assigned')->count());
        $this->assertSame('reassigned', Notification::where('user_id', $second->id)->firstOrFail()->payload['event']);
    }

    public function test_an_edit_that_does_not_change_the_consultant_raises_no_notification(): void
    {
        $cardio = $this->cardiology();
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);
        $coord = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => 1]);

        $c = Consultation::create([
            'mrn' => '71000003', 'patient_name' => 'Stable Pt', 'age' => 66, 'bed' => 'W-8',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'owning_specialty_id' => $cardio->id, 'consultant_id' => $owner->id,
        ]);

        $this->actingAs($coord)->put("/consultations/{$c->id}", [
            'mrn' => '71000003', 'patient_name' => 'Stable Pt', 'age' => 66, 'bed' => 'W-88',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'consultant_id' => $owner->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, Notification::where('type', 'consultation.assigned')->count());
        $this->assertSame('W-88', $c->fresh()->bed);
    }

    public function test_an_observer_never_reaches_the_notification_path(): void
    {
        $cardio = $this->cardiology();
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);
        // capability flags set ON PURPOSE: read-only wins over every one of them
        $obs = $this->user(User::ROLE_OBSERVER, ['can_coordinate_consultations' => 1, 'can_manage' => 1]);

        $this->actingAs($obs)->post('/consultations', $this->payload($owner))->assertForbidden();

        $this->assertSame(0, Consultation::count());
        $this->assertSame(0, Notification::where('type', 'consultation.assigned')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationCoordinatorNotifyTest
```

Expected failure: `test_a_coordinator_created_consult_notifies_the_owning_consultant_exactly_once` fails with `Failed asserting that actual size 0 matches expected size 1` (nothing writes a `consultation.assigned` row yet).

- [ ] **Step 3: Write the implementation**

In `laravel/app/Http/Controllers/ConsultationsController.php`, add the import:

```php
use App\Models\Notification;
```

Add this private method at the end of the class:

```php
    /**
     * Wave 2b (§6.4): placing a consultation used to notify nobody. The one case where the owning
     * consultant genuinely does not already know is a COORDINATOR (or an admin, who holds the
     * capability implicitly) booking the consult into that team's book — so raise exactly ONE in-app
     * notification there, and none anywhere else. A consultant entering a consult for themselves
     * raises none; a non-coordinator entering one raises none. That is the whole noise budget.
     *
     * Notification rows are never deleted — they are a retained clinical-audit trail, cleared only
     * by read-all (see HandoverController::readAll).
     *
     * @param string $event 'created' | 'reassigned'
     */
    private function notifyAssignedConsultant(Consultation $c, string $event): void
    {
        $actor = Auth::user();
        $consultantId = (int) ($c->consultant_id ?? 0);

        if ($consultantId === 0 || $consultantId === (int) Auth::id()) {
            return;                                     // no owner recorded, or self-entered
        }
        if (! $actor->canCoordinateConsultations()) {
            return;                                     // only coordinator/admin bookings notify
        }

        Notification::create([
            'user_id' => $consultantId,
            'type' => 'consultation.assigned',
            'created_at' => now(),
            'payload' => [
                'consultation_id' => $c->id,
                'patient_name' => $c->patient_name,
                'mrn' => $c->mrn,
                'service' => $c->to_service,
                'by_name' => $actor->full_name ?: $actor->name,
                'event' => $event,
            ],
        ]);
        Audit::log('consultation.assign', 'consultation', (string) $c->id, [
            'consultant_id' => $consultantId,
            'event' => $event,
            'notified' => true,
        ]);
    }
```

In `store()`, add the call directly after the `Audit::log('consultation.create', …)` line and before the `return back()`:

```php
        $this->notifyAssignedConsultant($c, 'created');
```

In `update()`, capture the previous owner before the write and notify after it. The method currently reads `$before = $consultation->only($fields);` then `$consultation->update(...)` then the diff + audit; add one line above `$before` and one line after the audit:

```php
        $previousConsultantId = (int) $consultation->consultant_id;   // Wave 2b: reassign detection
```

and, immediately after `Audit::log('consultation.modify', …);`:

```php
        // a coordinator moving the consult to a DIFFERENT consultant is a reassignment — tell the
        // new owner once. An edit that leaves consultant_id alone raises nothing.
        if ((int) $consultation->consultant_id !== $previousConsultantId) {
            $this->notifyAssignedConsultant($consultation->fresh(), 'reassigned');
        }
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationCoordinatorNotifyTest
```

Expected: `OK (6 tests, ...)` — PASS.

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test
```

Expected: the full suite PASSes.

- [ ] **Step 5: Commit**

```
cd laravel && git add app/Http/Controllers/ConsultationsController.php tests/Feature/ConsultationCoordinatorNotifyTest.php && git commit -m "feat(consultations): one consultation.assigned notification when a coordinator books or reassigns a consult"
```

---

### Task 21: Show who typed a record vs who owns it, and render the new bell entry

`entered_by` has always been the trustworthy field — session-sourced and unspoofable — and has never been shown anywhere. This task ships it to the list and the edit modal, plainly separated from the owning consultant, and teaches the bell to render `consultation.assigned` and route it to `/consultations` instead of the handover inbox.

**Files:**
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Modify: `laravel/resources/js/Pages/Consultations/Index.vue`
- Modify: `laravel/resources/js/Layouts/AppLayout.vue`
- Test: `laravel/tests/Feature/ConsultationEnteredByTest.php`
- Test: `laravel/resources/js/__tests__/ConsultationsIndex.enteredBy.test.js`
- Test: `laravel/resources/js/__tests__/AppLayout.consultationNotif.test.js`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationEnteredByTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Wave 2b — entered_by is shipped to the client, separately from the owning consultant, so the
 * workspace can show who TYPED a record versus who OWNS it. Ownership (owning_specialty_id +
 * consultant_id) is independent of entry.
 */
class ConsultationEnteredByTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ceb_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Entered User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    public function test_the_list_ships_the_typist_and_the_owner_as_separate_fields(): void
    {
        $cardio = Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Owner']);
        $typist = $this->user(User::ROLE_REGISTRAR, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Typist']);

        Consultation::create([
            'mrn' => '72000001', 'patient_name' => 'Attribution Pt', 'age' => 47, 'bed' => 'W-5',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'owning_specialty_id' => $cardio->id, 'consultant_id' => $owner->id,
            'entered_by' => $typist->id, 'status' => Consultation::STATUS_ACTIVE,
        ]);

        $this->actingAs($owner)->get('/consultations')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('consultations.data.0.consultant', 'Dr Owner')
                ->where('consultations.data.0.entered_by', 'Dr Typist')
                ->where('consultations.data.0.entered_by_id', $typist->id)
        );
    }

    public function test_a_consultation_with_no_recorded_typist_ships_an_em_dash(): void
    {
        $cardio = Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Owner']);

        Consultation::create([
            'mrn' => '72000002', 'patient_name' => 'Imported Pt', 'age' => 80, 'bed' => 'W-6',
            'current_location' => 'Ward', 'consultation_date' => '2019-01-01',
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'owning_specialty_id' => $cardio->id, 'consultant_id' => $owner->id,
            'entered_by' => null, 'status' => Consultation::STATUS_ACTIVE,
        ]);

        $this->actingAs($owner)->get('/consultations')->assertOk()->assertInertia(
            fn (AssertableInertia $p) => $p->where('consultations.data.0.entered_by', '—')
                ->where('consultations.data.0.entered_by_id', null)
        );
    }
}
```

Create `laravel/resources/js/__tests__/ConsultationsIndex.enteredBy.test.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Wave 2b — who TYPED the record vs who OWNS it. entered_by is the trustworthy field (session-
// sourced, never settable from a payload) and must be visible next to the owning consultant.

const { post, put, deleteFn, ask } = vi.hoisted(() => ({
    post: vi.fn(), put: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(),
}));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => reactive({
        ...obj, errors: {}, processing: false,
        post: vi.fn((...a) => post(...a)),
        put: vi.fn((...a) => put(...a)),
        reset: vi.fn(), clearErrors: vi.fn(),
    }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/BaseModal.vue', () => ({
    default: {
        props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable', 'dirty'],
        emits: ['close'],
        template: '<div v-if="open"><slot /></div>',
    },
}));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';

const admin = { role: 0, is_admin: true, id: 1, can: { manage: true } };
const consult = {
    id: 31, name: 'Attribution Pt', mrn: '72000001', age: 47, bed: 'W-5', location: 'Ward',
    from: 'ER', to: 'Cardiology', consultant: 'Dr Owner', consultant_id: 5,
    entered_by: 'Dr Typist', entered_by_id: 6, date: '2026-08-21', signoff: null,
    reasons: [], indication_ids: [], other: '',
};
const props = {
    consultations: { data: [consult], total: 1, last_page: 1, links: [] },
    filters: {}, stats: { active: 1, total: 1, mine_active: 0 },
    reasons: [], consultants: [], specialties: [],
    worklist: { date: '2026-08-21', seen: 0, total: 0, items: [] },
};
const mountWith = () => { authUser = admin; return mount(ConsultationsIndex, { props }); };

beforeEach(() => { post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset(); });

describe('Consultations/Index — entered_by is shown apart from the owner', () => {
    it('renders the typist under the owning consultant in the row', () => {
        const w = mountWith();
        const cell = w.get('[data-test="attribution-31"]');
        expect(cell.text()).toContain('Dr Owner');
        expect(cell.text()).toContain('Entered by Dr Typist');
    });

    it('repeats the attribution inside the edit modal', async () => {
        const w = mountWith();
        w.vm.openEdit(consult);
        await w.vm.$nextTick();
        const banner = w.get('[data-test="edit-attribution"]');
        expect(banner.text()).toContain('Entered by Dr Typist');
        expect(banner.text()).toContain('Dr Owner');
    });
});
```

Create `laravel/resources/js/__tests__/AppLayout.consultationNotif.test.js`:

```js
import { describe, it, expect } from 'vitest';

// Wave 2b — the bell must render a `consultation.assigned` entry in words and route it to the
// consultations workspace rather than the handover inbox. notifText/feedTarget are exported from
// AppLayout.vue's <script setup> via defineExpose, so they are testable without a full mount.

import { notifText, feedTarget } from '@/Layouts/notifText.js';

describe('bell rendering — consultation.assigned', () => {
    it('reads as a sentence naming the booker, the patient and the service', () => {
        const text = notifText({
            type: 'consultation.assigned',
            payload: { patient_name: 'Attribution Pt', mrn: '72000001', service: 'Cardiology', by_name: 'Dr Coordinator', event: 'created' },
        });
        expect(text).toContain('Dr Coordinator');
        expect(text).toContain('Attribution Pt');
        expect(text).toContain('Cardiology');
    });

    it('says "reassigned" when that is the event', () => {
        const text = notifText({
            type: 'consultation.assigned',
            payload: { patient_name: 'Reassign Pt', service: 'Cardiology', by_name: 'Dr Coordinator', event: 'reassigned' },
        });
        expect(text).toMatch(/reassigned/i);
    });

    it('routes a consultation notification to the consultations workspace', () => {
        expect(feedTarget({ type: 'consultation.assigned' })).toBe('/consultations');
    });

    it('leaves every other type routed to the handover inbox', () => {
        expect(feedTarget({ type: 'handover.signed' })).toBe('/handovers');
    });

    it('still renders the handover types unchanged', () => {
        expect(notifText({ type: 'handover.incomplete', payload: { patient_name: 'P', mrn: '1', from_name: 'X', to_name: 'Y' } }))
            .toContain('without a completed handover');
        expect(notifText({ type: 'security.failed_logins', payload: { count: 3, username: 'bob' } }))
            .toContain('failed login');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationEnteredByTest
```

Expected failure: `Inertia property [consultations.data.0.entered_by] does not exist.`

```
cd laravel && npx vitest run resources/js/__tests__/ConsultationsIndex.enteredBy.test.js resources/js/__tests__/AppLayout.consultationNotif.test.js
```

Expected failure: `Unable to get [data-test="attribution-31"] within: <div>` for the first file, and `Failed to resolve import "@/Layouts/notifText.js"` for the second.

- [ ] **Step 3: Write the implementation**

In `laravel/app/Http/Controllers/ConsultationsController.php`, in `index()`, extend the eager load on the paginated query — the line currently reads `->with('consultant:id,full_name,name')`:

```php
            ->with(['consultant:id,full_name,name', 'enteredBy:id,full_name,name'])
```

and add two keys to the `->through(fn (Consultation $c) => [...])` row map, directly after `'consultant_id' => $c->consultant_id,`:

```php
                // Wave 2b: who TYPED the record. entered_by is session-sourced and unspoofable —
                // the one attribution field in this table that can be trusted — and is deliberately
                // shown apart from the OWNER (owning_specialty_id + consultant_id), which is
                // independent of it.
                'entered_by' => $c->enteredBy?->full_name ?? $c->enteredBy?->name ?? '—',
                'entered_by_id' => $c->entered_by,
```

Create `laravel/resources/js/Layouts/notifText.js` — this lifts the existing bell-copy function out of `AppLayout.vue` so it is directly testable, and adds the new type:

```js
/**
 * Bell copy + click destination, one function per concern, extracted from AppLayout.vue so the
 * per-type rendering is unit-testable. Behaviour for every pre-existing type is unchanged.
 */
export function notifText(n) {
    const p = n.payload || {};
    // Phase 4 — Item 3: failed-login burst alert
    if (n.type === 'security.failed_logins') {
        return `Security: ${p.count || ''} failed login attempt(s) for "${p.username || 'unknown'}"${p.ip ? ` from ${p.ip}` : ''}`;
    }
    // Phase 4 — Item 6: daily data-quality digest
    if (n.type === 'dq.daily_report') {
        const total = (p.over_los || 0) + (p.no_dx || 0) + (p.bad_dates || 0) + (p.orphan_dx || 0) + (p.double_open || 0);
        return `Data quality: ${total} item(s) need review (see the Data Quality page)`;
    }
    // HO-T7: persistent "incomplete handover" reminder
    if (n.type === 'handover.incomplete') {
        return `${p.patient_name || 'A patient'}${p.mrn ? ` (MRN ${p.mrn})` : ''} was reassigned from Dr. ${p.from_name || '—'} without a completed handover — complete it.`;
    }
    // Wave 2b: a coordinator booked (or moved) a consult into your book
    if (n.type === 'consultation.assigned') {
        const verb = p.event === 'reassigned' ? 'reassigned' : 'booked';
        return `${p.by_name || 'A coordinator'} ${verb} a ${p.service || 'consultation'} consult for ${p.patient_name || 'a patient'} to you`;
    }
    if (p.count) return `Dr. ${p.from_name || 'A consultant'} handed over ${p.count} patient(s) to you`;
    return `Dr. ${p.from_name || 'A consultant'} handed over ${p.patient_name || 'a patient'}${p.mrn ? ` (MRN ${p.mrn})` : ''}`;
}

/** Where clicking a feed entry goes. Consultation entries belong to the consultations workspace. */
export function feedTarget(n) {
    return n.type === 'consultation.assigned' ? '/consultations' : '/handovers';
}
```

In `laravel/resources/js/Layouts/AppLayout.vue`, delete the whole inline `const notifText = (n) => { … };` block (it currently spans from the `// Phase 4 — Item 3: failed-login burst alert` comment through the closing `};`, ending on the line before `const relTime`) and replace the `goInbox` definition plus that block with:

```js
const goInbox = () => { bellOpen.value = false; router.visit('/handovers'); };
// Wave 2b: bell copy + per-type destination now live in a testable module.
const goFeed = (n) => { bellOpen.value = false; router.visit(feedTarget(n)); };
```

and add the import beside the other `@/` imports at the top of that `<script setup>`:

```js
import { notifText, feedTarget } from '@/Layouts/notifText.js';
```

In the same file's template, change the non-actionable feed button so it routes per type — replace `@click="goInbox"` on the feed `<button>` (inside `<ul v-if="feedNotifications.length">`) with:

```html
                                    <button @click="goFeed(n)" class="w-full px-4 py-3 text-start transition hover:bg-brand-50/40" :class="{ 'bg-brand-50/30': !n.read_at }">
```

In `laravel/resources/js/Pages/Consultations/Index.vue`, replace the consultant cell in the results table — the line currently reading `<td class="px-3 py-3 text-ink-600">{{ c.consultant }}</td>` — with:

```html
                        <td class="px-3 py-3" :data-test="`attribution-${c.id}`">
                            <div class="text-ink-600">{{ c.consultant }}</div>
                            <!-- entered_by is the session-stamped typist; ownership is separate -->
                            <div class="text-xs text-ink-400">Entered by {{ c.entered_by || '—' }}</div>
                        </td>
```

and change that column's header from `<th scope="col" class="px-3 py-3">Consultant</th>` to:

```html
                        <th scope="col" class="px-3 py-3">Consultant / entered by</th>
```

In the **edit** modal, add this banner as the first child of `<form @submit.prevent="submitEdit" class="space-y-4">`:

```html
                    <p v-if="editing" data-test="edit-attribution" class="rounded-xl bg-ink-50 px-3 py-2 text-xs text-ink-500">
                        Entered by {{ editing.entered_by || '—' }} · owned by {{ editing.consultant || '—' }}.
                        Editing never changes who entered a record.
                    </p>
```

- [ ] **Step 4: Run test to verify it passes**

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationEnteredByTest
```

Expected: `OK (2 tests, ...)` — PASS.

```
cd laravel && npx vitest run resources/js/__tests__/ConsultationsIndex.enteredBy.test.js resources/js/__tests__/AppLayout.consultationNotif.test.js
cd laravel && npx vitest run
```

Expected: `Tests  7 passed (7)` across the two new files, then every suite PASS (`AppLayout.notifications.test.js` included — it never asserted on `notifText`/`goInbox`).

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test
cd laravel && npx vite build
cd laravel && node scripts/check-source-allowlist.mjs --write
cd laravel && node scripts/check-source-allowlist.mjs
cd laravel && node scripts/contrast.mjs
```

Expected: full backend suite PASS; build succeeds; allowlist prints `PASS`; contrast prints `PASS`.

- [ ] **Step 5: Commit**

```
cd laravel && git add app/Http/Controllers/ConsultationsController.php resources/js/Layouts/notifText.js resources/js/Layouts/AppLayout.vue resources/js/Pages/Consultations/Index.vue tests/Feature/ConsultationEnteredByTest.php resources/js/__tests__/ConsultationsIndex.enteredBy.test.js resources/js/__tests__/AppLayout.consultationNotif.test.js public/build scripts/check-source-allowlist.mjs && git commit -m "feat(consultations): show entered_by apart from the owning consultant; render the consultation.assigned bell entry"
```
## Wave W3 — The service handover view

This wave delivers one new read-only screen: `GET /consultations/handover`, the sheet an IM subspecialty team reads at shift change and prints. It lists the viewer's service's **active** and **ongoing** consults — never `new`, never `signed_off` — each with its **latest follow-up note**, its age in days, and whether it has already been ticked off today. Scoping is *not* re-implemented here: it delegates entirely to `Consultation::scopeVisibleTo()` from W1, so a Cardiology consultant sees Cardiology, and admins/coordinators see every service grouped, with a screen-only service picker for printing one team's sheet.

Deliberately left to later waves: recording a follow-up or changing status *from* this page (W2 owns those write endpoints — the handover sheet is read-only by design, so it can be printed and carried), and every metric/chart (W4). Nothing in this wave writes to `consultations` or `consultation_followups`.

Wave prerequisites, all delivered by W1/W2 and used verbatim here: the `status`, `owning_specialty_id`, `requested_at` columns; the `Consultation::STATUS_*` constants; `Consultation::scopeVisibleTo(Builder $q, User $u)`; the `owningSpecialty()` relation; the `App\Models\ConsultationFollowup` model and its `consultation_followups` table with `unique([consultation_id, followup_date])`.

---

### Task 22: Handover controller action, route, and the single-query latest-follow-up load

The whole point of this task is that the latest follow-up per consult is fetched in **exactly one** extra query — a grouped `MAX(followup_date)` sub-select joined back to its own row — not one lookup per row. The test pins that with a query counter, so a future `->each(fn ($c) => $c->followups()->latest()->first())` regression fails loudly.

**Files:**
- Modify: `laravel/app/Http/Controllers/ConsultationsController.php`
- Modify: `laravel/routes/web.php`
- Test: `laravel/tests/Feature/ConsultationHandoverTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationHandoverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Models\ConsultationReason;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * W3 — the service handover view (GET /consultations/handover).
 *
 * The sheet a subspecialty team reads at shift change: its ACTIVE + ONGOING consults, each with
 * its LATEST follow-up note. Scoping is NOT re-implemented in the controller — it delegates to
 * Consultation::scopeVisibleTo() (W1), and these tests exist partly to prove that delegation
 * (a Cardiology user must not see Nephrology here any more than on /consultations).
 */
class ConsultationHandoverTest extends TestCase
{
    use RefreshDatabase;

    /** Users need mfa_secret + mfa_enrolled_at + email_verified_at or they cannot authenticate. */
    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'hv_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'HV User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(), 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function consultation(Specialty $s, string $status, array $extra = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'HV Patient', 'age' => 61, 'bed' => 'W-12',
            'current_location' => 'Ward', 'consultation_from' => 'ER',
            'consultation_date' => now()->subDays(4)->toDateString(),
            'requested_at' => now()->subDays(4),
            'indication' => [], 'other_indication' => null,
            'to_service' => $s->name, 'owning_specialty_id' => $s->id, 'status' => $status,
        ], $extra));
    }

    private function rowsOf(AssertableInertia $page): array
    {
        return collect($page->toArray()['props']['groups'])
            ->flatMap(fn ($g) => $g['consultations'])->all();
    }

    // ---- scoping (delegated to scopeVisibleTo) --------------------------------------------------

    public function test_handover_lists_only_active_and_ongoing_consults_of_the_viewers_specialty(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $active = $this->consultation($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => 'Cardio Active']);
        $ongoing = $this->consultation($cardio, Consultation::STATUS_ONGOING, ['patient_name' => 'Cardio Ongoing']);
        $this->consultation($cardio, Consultation::STATUS_NEW, ['patient_name' => 'Cardio New']);
        $this->consultation($cardio, Consultation::STATUS_SIGNED_OFF, ['patient_name' => 'Cardio Signed']);
        $this->consultation($nephro, Consultation::STATUS_ACTIVE, ['patient_name' => 'Nephro Active']);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($active, $ongoing) {
                $page->component('Consultations/Handover');
                $rows = $this->rowsOf($page);
                $this->assertEqualsCanonicalizing([$active->id, $ongoing->id], array_column($rows, 'id'));
                $names = array_column($rows, 'name');
                $this->assertNotContains('Cardio New', $names, 'a not-yet-seen consult is not handover material');
                $this->assertNotContains('Cardio Signed', $names, 'a signed-off consult is off the books');
                $this->assertNotContains('Nephro Active', $names, 'specialty scoping must hold on the handover sheet');
            });
    }

    public function test_admin_sees_every_service_grouped_including_unassigned(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $this->consultation($cardio, Consultation::STATUS_ACTIVE);
        $this->consultation($nephro, Consultation::STATUS_ONGOING);
        Consultation::create([
            'mrn' => '90000099', 'patient_name' => 'Orphan Pt', 'indication' => [],
            'consultation_date' => now()->subDay()->toDateString(), 'to_service' => 'Some Outside Clinic',
            'owning_specialty_id' => null, 'status' => Consultation::STATUS_ONGOING,
        ]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $groups = $page->toArray()['props']['groups'];
                $this->assertEqualsCanonicalizing(
                    ['Cardiology', 'Nephrology', 'Unassigned'],
                    array_column($groups, 'name')
                );
            });
    }

    public function test_observer_is_refused_the_handover_sheet(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $this->consultation($cardio, Consultation::STATUS_ACTIVE);

        $this->actingAs($this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id]))
            ->get('/consultations/handover')->assertForbidden();
    }

    // ---- latest follow-up ----------------------------------------------------------------------

    public function test_each_consult_carries_only_its_latest_followup_note(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Cardio']);
        $c = $this->consultation($cardio, Consultation::STATUS_ACTIVE);

        ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => now()->subDays(2)->toDateString(),
            'note' => 'Older note that must not surface', 'author_id' => $viewer->id]);
        ConsultationFollowup::create(['consultation_id' => $c->id, 'followup_date' => now()->toDateString(),
            'note' => 'Rate controlled, continue beta blocker', 'author_id' => $viewer->id]);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $row = $this->rowsOf($page)[0];
                $this->assertSame('Rate controlled, continue beta blocker', $row['last_followup']['note']);
                $this->assertSame(now()->toDateString(), $row['last_followup']['date']);
                $this->assertSame('Dr Cardio', $row['last_followup']['author']);
                $this->assertTrue($row['last_followup']['is_today']);
            });
    }

    public function test_a_consult_with_no_followup_reports_null_rather_than_being_dropped(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $this->consultation($cardio, Consultation::STATUS_ONGOING, ['patient_name' => 'Never Ticked']);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $row = $this->rowsOf($page)[0];
                $this->assertSame('Never Ticked', $row['name']);
                $this->assertNull($row['last_followup']);
            });
    }

    public function test_latest_followup_loads_in_one_query_not_one_per_row(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        foreach (range(1, 6) as $i) {
            $c = $this->consultation($cardio, Consultation::STATUS_ACTIVE, ['patient_name' => "Pt {$i}"]);
            ConsultationFollowup::create(['consultation_id' => $c->id,
                'followup_date' => now()->subDay()->toDateString(), 'note' => "note {$i}", 'author_id' => $viewer->id]);
            ConsultationFollowup::create(['consultation_id' => $c->id,
                'followup_date' => now()->toDateString(), 'note' => "latest {$i}", 'author_id' => $viewer->id]);
        }

        $followupQueries = 0;
        DB::listen(function ($q) use (&$followupQueries) {
            if (str_contains($q->sql, 'consultation_followups')) {
                $followupQueries++;
            }
        });

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $notes = array_map(fn ($r) => $r['last_followup']['note'], $this->rowsOf($page));
                sort($notes);
                $this->assertSame(['latest 1', 'latest 2', 'latest 3', 'latest 4', 'latest 5', 'latest 6'], $notes);
            });

        $this->assertSame(1, $followupQueries,
            'the latest follow-up must load in ONE grouped query — an N+1 across the handover list is the defect this pins');
    }

    // ---- presentation payload -------------------------------------------------------------------

    public function test_row_carries_ageing_entered_by_and_indication_names(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $reason = ConsultationReason::create(['name' => 'Chest pain']);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['full_name' => 'Dr Coordinator']);
        $consultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Cardio']);
        $this->consultation($cardio, Consultation::STATUS_ACTIVE, [
            'requested_at' => now()->subDays(6), 'consultation_date' => now()->subDays(6)->toDateString(),
            'indication' => [$reason->id], 'other_indication' => 'second opinion',
            'consultant_id' => $consultant->id, 'entered_by' => $coordinator->id,
        ]);

        $this->actingAs($consultant)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $row = $this->rowsOf($page)[0];
                $this->assertSame(6, $row['open_days']);
                $this->assertSame('Dr Cardio', $row['consultant']);
                $this->assertSame('Dr Coordinator', $row['entered_by'], 'entered_by is displayed distinctly from the owner');
                $this->assertSame(['Chest pain'], $row['reasons']);
                $this->assertSame('second opinion', $row['other']);
            });
    }

    public function test_group_counts_report_todays_followup_completeness(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $viewer = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $seen = $this->consultation($cardio, Consultation::STATUS_ACTIVE);
        $this->consultation($cardio, Consultation::STATUS_ACTIVE);
        $this->consultation($cardio, Consultation::STATUS_ONGOING);
        ConsultationFollowup::create(['consultation_id' => $seen->id,
            'followup_date' => now()->toDateString(), 'note' => 'seen', 'author_id' => $viewer->id]);

        $this->actingAs($viewer)->get('/consultations/handover')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $counts = $page->toArray()['props']['groups'][0]['counts'];
                $this->assertSame(3, $counts['total']);
                $this->assertSame(2, $counts['active']);
                $this->assertSame(1, $counts['ongoing']);
                $this->assertSame(1, $counts['seen_today']);
            });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationHandoverTest
```

Expected failure — the route does not exist yet, so every test reports a 404 instead of the expected status, e.g.:

```
FAILED  Tests\Feature\ConsultationHandoverTest > handover lists only active and ongoing consults of the viewers specialty
Expected response status code [200] but received 404.
```

- [ ] **Step 3: Write the implementation**

First, add the `DB` facade import to `laravel/app/Http/Controllers/ConsultationsController.php`. Locate the import block that currently reads:

```php
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
```

and change it to:

```php
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
```

Then add the `handover()` action to the same class, immediately after `index()` (i.e. after the closing brace of `index()` and before `public function store(...)`):

```php
    /**
     * W3 — the SERVICE HANDOVER SHEET: the viewer's service's ACTIVE + ONGOING consults, each with
     * its LATEST follow-up note. Read at shift change and printed (Consultations/Handover.vue).
     *
     * Read-only by design: nothing here writes. Recording a follow-up or moving a status happens on
     * the workspace (/consultations), so this sheet can be printed and carried without being stale
     * in a way that matters.
     *
     * Scoping is DELEGATED to Consultation::scopeVisibleTo (W1) — there is exactly ONE consultation
     * scoping rule in this codebase and this action must never grow a second one. `new` consults are
     * excluded (nothing has been seen yet, so there is nothing to hand over) and `signed_off` ones
     * are off the books.
     *
     * Not audited: this is a read of rows the same user already sees on /consultations, mirroring the
     * printable Active List census (PatientsController::activeList), which is likewise unaudited.
     */
    public function handover(Request $request): Response
    {
        // Observer read-only guarantee, enforced BEFORE any capability flag — same gate as index().
        if (Auth::user()->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }

        $reasons = ConsultationReason::pluck('name', 'id');

        $consultations = Consultation::query()
            ->visibleTo(Auth::user())
            ->whereIn('status', [Consultation::STATUS_ACTIVE, Consultation::STATUS_ONGOING])
            ->with(['consultant:id,full_name,name', 'enteredBy:id,full_name,name', 'owningSpecialty:id,name'])
            // oldest request first: the sheet is read top-down and the stalest consult must lead.
            // Historical rows have a NULL requested_at, so fall back to the date-only column.
            ->orderByRaw('COALESCE(requested_at, consultation_date) asc')
            ->orderBy('id')
            ->get();

        // ONE query for every latest follow-up — never one per row. The inner grouped select picks
        // each consult's MAX(followup_date); the outer self-join fetches that exact row's note and
        // author. consultation_followups.unique([consultation_id, followup_date]) guarantees the
        // join matches exactly one row per consult, so no tie-break is needed.
        $latest = $consultations->isEmpty() ? collect() : DB::table('consultation_followups as f')
            ->joinSub(
                DB::table('consultation_followups')
                    ->selectRaw('consultation_id, MAX(followup_date) as followup_date')
                    ->whereIn('consultation_id', $consultations->pluck('id'))
                    ->groupBy('consultation_id'),
                'm',
                fn ($join) => $join->on('m.consultation_id', '=', 'f.consultation_id')
                    ->on('m.followup_date', '=', 'f.followup_date')
            )
            ->leftJoin('users as u', 'u.id', '=', 'f.author_id')
            ->select('f.consultation_id', 'f.followup_date', 'f.note',
                'u.full_name as author_full_name', 'u.name as author_name')
            ->get()
            ->keyBy('consultation_id');

        $today = now()->toDateString();

        $rows = $consultations->map(function (Consultation $c) use ($latest, $reasons, $today) {
            $f = $latest->get($c->id);
            // requested_at is the authoritative request time going forward; historical rows have
            // only the date-only consultation_date. Parse defensively so this works whether or not
            // the model casts requested_at.
            $since = $c->requested_at
                ? \Illuminate\Support\Carbon::parse($c->requested_at)
                : ($c->consultation_date ? \Illuminate\Support\Carbon::parse($c->consultation_date) : null);
            $fDate = $f ? substr((string) $f->followup_date, 0, 10) : null;

            return [
                'id' => $c->id,
                'name' => $c->patient_name ?? 'Unknown',
                'mrn' => $c->mrn,
                'age' => $c->age,
                'bed' => $c->bed,
                'location' => $c->current_location,
                'from' => $c->consultation_from,
                'status' => $c->status,
                'specialty' => $c->owningSpecialty?->name ?? 'Unassigned',
                'consultant' => $c->consultant?->full_name ?: ($c->consultant?->name ?? '—'),
                // entered_by is immutable and session-sourced; the sheet shows it DISTINCTLY from the
                // owning consultant so "who booked this in" is never confused with "whose consult it is".
                'entered_by' => $c->enteredBy?->full_name ?: ($c->enteredBy?->name ?? '—'),
                'reasons' => collect($c->indication ?? [])->map(fn ($id) => $reasons[$id] ?? null)->filter()->values(),
                'other' => $c->other_indication,
                'requested_on' => $since?->toDateString(),
                'open_days' => $since ? (int) $since->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
                'last_followup' => $f ? [
                    'date' => $fDate,
                    'note' => $f->note,
                    'author' => $f->author_full_name ?: ($f->author_name ?? '—'),
                    'is_today' => $fDate === $today,
                ] : null,
            ];
        });

        // Grouped per owning service so admins/coordinators (who see every service) can print one
        // team's sheet; an ordinary clinical viewer simply receives a single group.
        $groups = $rows->groupBy('specialty')->sortKeys()->map(fn ($items, $name) => [
            'key' => (string) $name,
            'name' => (string) $name,
            'consultations' => $items->values(),
            'counts' => [
                'total' => $items->count(),
                'active' => $items->where('status', Consultation::STATUS_ACTIVE)->count(),
                'ongoing' => $items->where('status', Consultation::STATUS_ONGOING)->count(),
                'seen_today' => $items->filter(fn ($r) => $r['last_followup']['is_today'] ?? false)->count(),
            ],
        ])->values();

        return Inertia::render('Consultations/Handover', [
            'groups' => $groups,
            'generatedAt' => now()->format('D, d M Y · H:i'),
            'today' => $today,
        ]);
    }
```

Then register the route in `laravel/routes/web.php`. Find the two search routes inside the authenticated group:

```php
    Route::post('/consultations/search', [ConsultationsController::class, 'index'])->name('consultations.search');
    Route::get('/consultations/search', fn () => redirect()->route('consultations.index'));
```

and insert the handover route directly beneath them:

```php
    Route::post('/consultations/search', [ConsultationsController::class, 'index'])->name('consultations.search');
    Route::get('/consultations/search', fn () => redirect()->route('consultations.index'));
    // W3 — printable shift-handover sheet: the viewer's service's active + ongoing consults with
    // each one's latest follow-up note. A literal segment, so it can never collide with the
    // {consultation} model-bound routes below (which are POST/PUT/DELETE only anyway).
    Route::get('/consultations/handover', [ConsultationsController::class, 'handover'])->name('consultations.handover');
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationHandoverTest
```

Expected: `OK` — 8 passed. Then confirm nothing else moved, in particular the ~37 existing consultation authorization methods:

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test
```

Expected: the whole backend suite green (no failures, no errors).

- [ ] **Step 5: Commit**

```bash
cd laravel && git add app/Http/Controllers/ConsultationsController.php routes/web.php tests/Feature/ConsultationHandoverTest.php && git commit -m "feat(consultations): service handover action — scoped active+ongoing with latest follow-up

GET /consultations/handover renders the viewer's service's active and ongoing
consults, each carrying its latest follow-up note, author and date. Scoping is
delegated to Consultation::scopeVisibleTo (W1) rather than duplicated. The
latest follow-up loads in ONE grouped MAX(followup_date) self-join, pinned by a
query-count test so an N+1 cannot creep back in. Observers refused, read-only,
no writes and no audit (mirrors the printable Active List census)."
```

---

### Task 23: The handover Vue page

**Files:**
- Create: `laravel/resources/js/Pages/Consultations/Handover.vue`
- Test: `laravel/resources/js/Pages/Consultations/__tests__/Handover.spec.js` (create)

- [ ] **Step 1: Write the failing test**

Create `laravel/resources/js/Pages/Consultations/__tests__/Handover.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Stub the layout chrome — this spec is about the sheet's content, and Handover.vue uses no
// Inertia primitives of its own (the page is read-only, so it needs no router/form).
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', template: '<div><slot /></div>' } }));

import Handover from '@/Pages/Consultations/Handover.vue';

const consult = (over = {}) => ({
    id: 1, name: 'Ward Patient', mrn: '90000001', age: 61, bed: 'W-12', location: 'Ward',
    from: 'ER', status: 'active', specialty: 'Cardiology',
    consultant: 'Dr Cardio', entered_by: 'Dr Coordinator',
    reasons: ['Chest pain'], other: null, requested_on: '2026-08-15', open_days: 6,
    last_followup: { date: '2026-08-21', note: 'Rate controlled, continue beta blocker.', author: 'Dr Cardio', is_today: true },
    ...over,
});

const group = (key, name, consultations) => ({
    key, name, consultations,
    counts: {
        total: consultations.length,
        active: consultations.filter((c) => c.status === 'active').length,
        ongoing: consultations.filter((c) => c.status === 'ongoing').length,
        seen_today: consultations.filter((c) => c.last_followup && c.last_followup.is_today).length,
    },
});

const cardiology = () => group('Cardiology', 'Cardiology', [
    consult(),
    consult({ id: 2, name: 'Second Patient', mrn: '90000002', bed: 'W-20', status: 'ongoing', open_days: 12, last_followup: null }),
]);

const props = (over = {}) => ({
    groups: [cardiology()], generatedAt: 'Fri, 21 Aug 2026 · 07:00', today: '2026-08-21', ...over,
});

const mountPage = (p = props()) => mount(Handover, { props: p });

describe('Consultations/Handover — the shift sheet', () => {
    it('names the service and lists every consult in it', () => {
        const report = mountPage().find('.report').text();
        expect(report).toContain('Cardiology');
        expect(report).toContain('Ward Patient');
        expect(report).toContain('90000001');
        expect(report).toContain('W-12');
        expect(report).toContain('Second Patient');
    });

    it('shows the owning consultant separately from whoever entered the consult', () => {
        const report = mountPage().find('.report').text();
        expect(report).toContain('Dr Cardio');
        expect(report).toContain('Dr Coordinator');
    });

    it('renders the latest follow-up note, and says so when there is none', () => {
        const report = mountPage().find('.report').text();
        expect(report).toContain('Rate controlled, continue beta blocker.');
        expect(report).toContain('No follow-up recorded');
    });

    it('marks a consult already followed up today', () => {
        expect(mountPage().find('.report').text()).toContain('seen today');
    });

    it('labels active and ongoing distinctly', () => {
        const report = mountPage().find('.report').text();
        expect(report).toContain('Active · daily F/U');
        expect(report).toContain('Ongoing');
    });

    it('shows how long each consult has been open', () => {
        const report = mountPage().find('.report').text();
        expect(report).toContain('open 6d');
        expect(report).toContain('open 12d');
    });

    it('heads the sheet with the open total and today\u2019s follow-up completeness', () => {
        const text = mountPage().text();
        expect(text).toContain('2 open consult(s)');
        expect(text).toContain('1 of 1 active seen today');
        expect(text).toContain('Fri, 21 Aug 2026 · 07:00');
    });

    it('says so when there is nothing to hand over', () => {
        expect(mountPage(props({ groups: [] })).text()).toContain('No active or ongoing consultations');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd laravel && npx vitest run resources/js/Pages/Consultations/__tests__/Handover.spec.js
```

Expected failure — the page file does not exist yet:

```
Error: Failed to load url /resources/js/Pages/Consultations/Handover.vue
```

- [ ] **Step 3: Write the implementation**

Create `laravel/resources/js/Pages/Consultations/Handover.vue`:

```vue
<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { locTone } from '@/lib/ui.js';

// Service handover sheet (W3). The team's ACTIVE + ONGOING consults with each one's latest
// follow-up note — read at shift change, then printed. Read-only on purpose: ticking a follow-up
// off or moving a status happens on the workspace, so a printed sheet is never a stale write form.
//
// Scoping already happened server-side (ConsultationsController::handover delegates to
// Consultation::scopeVisibleTo). This page renders exactly what it is handed. An ordinary clinical
// viewer receives one group (their own service); admins and coordinators receive every service.
const props = defineProps({ groups: Array, generatedAt: String, today: String });

// Screen-only service picker: every group is already on the page, so narrowing is a pure
// client-side filter — the same idiom as the Active List census consultant picker.
const selected = ref('all');
const visibleGroups = computed(() => selected.value === 'all' ? props.groups : props.groups.filter((g) => g.key === selected.value));
const selectedName = computed(() => visibleGroups.value[0]?.name ?? '');
const sum = (field) => visibleGroups.value.reduce((t, g) => t + g.counts[field], 0);
const totals = computed(() => sum('total'));
const activeTotal = computed(() => sum('active'));
const seenToday = computed(() => sum('seen_today'));

// Ageing tone — a consult open past a week must read as overdue on a printed page, not blend in.
// All four tokens are existing AA-verified pairs (see lib/ui.js locTone / deltaChipClass).
const ageTone = (days) => days === null ? 'bg-brand-100 text-brand-700'
    : days > 7 ? 'bg-tint-danger text-on-danger'
    : days > 2 ? 'bg-tint-warning text-on-warning'
    : 'bg-tint-success text-on-success';
const statusTone = (s) => s === 'active' ? 'bg-tint-success text-on-success' : 'bg-tint-warning text-on-warning';
const statusLabel = (s) => s === 'active' ? 'Active · daily F/U' : 'Ongoing';

const print = () => window.print();
</script>

<template>
    <AppLayout title="Service Handover">
        <!-- toolbar (screen only) -->
        <div class="no-print mb-5 flex flex-wrap items-center gap-3">
            <button @click="print" class="inline-flex items-center gap-2 rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-solid-hover">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.4 42.4 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m11.32 0 .55-6.171M6.34 18l-.55-6.171m0 0a42.4 42.4 0 0 1 12.42 0M5.79 11.829V6.75A2.25 2.25 0 0 1 8.04 4.5h7.92a2.25 2.25 0 0 1 2.25 2.25v5.079" /></svg>
                Print
            </button>
            <label v-if="groups.length > 1" class="inline-flex items-center gap-2 text-sm font-semibold text-ink-600">
                Service
                <select v-model="selected" aria-label="Choose which service to print" class="rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm font-normal text-ink-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
                    <option value="all">All services</option>
                    <option v-for="g in groups" :key="g.key" :value="g.key">{{ g.name }} ({{ g.counts.total }})</option>
                </select>
            </label>
            <span class="text-sm text-ink-400">Shift-handover sheet — active and ongoing consults with the latest follow-up note.</span>
        </div>

        <!-- printable document -->
        <div class="report mx-auto max-w-full rounded-2xl bg-card p-8 shadow-card ring-1 ring-line print:rounded-none print:shadow-none print:ring-0">
            <header class="mb-5 flex items-start justify-between border-b-2 border-brand-600 pb-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-navy-900">DMC <span class="text-brand-600">Internal Medicine</span></h1>
                    <p class="text-sm text-ink-500">Consultation Service Handover<span v-if="selected !== 'all'"> — {{ selectedName }}</span></p>
                </div>
                <div class="text-right text-xs text-ink-400">
                    <p>Eastern Health Cluster</p><p class="text-brand-600">تجمع الشرقية الصحي</p>
                    <p class="mt-1">Generated {{ generatedAt }}</p>
                    <p class="nums font-semibold text-ink-600">{{ totals }} open consult(s)</p>
                    <p class="nums font-semibold text-ink-600">{{ seenToday }} of {{ activeTotal }} active seen today</p>
                </div>
            </header>

            <section v-for="g in visibleGroups" :key="g.key" class="group-block mb-5">
                <div class="mb-1.5 flex items-baseline justify-between border-b border-line pb-1">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-navy-800">{{ g.name }}</h2>
                    <span class="nums text-xs text-ink-400">{{ g.counts.total }} open · Active {{ g.counts.active }} · Ongoing {{ g.counts.ongoing }} · Seen today {{ g.counts.seen_today }}</span>
                </div>
                <table class="w-full border-collapse text-xs">
                    <thead>
                        <tr class="bg-app/80 text-left font-semibold uppercase tracking-wide text-ink-500 print:bg-ink-100">
                            <th scope="col" class="px-2 py-1.5">Bed</th><th scope="col" class="px-2 py-1.5">MRN</th>
                            <th scope="col" class="px-2 py-1.5">Patient</th><th scope="col" class="px-2 py-1.5">Age</th>
                            <th scope="col" class="px-2 py-1.5">Loc</th><th scope="col" class="px-2 py-1.5">Status</th>
                            <th scope="col" class="px-2 py-1.5">Ageing</th><th scope="col" class="px-2 py-1.5">Consultant</th>
                            <th scope="col" class="px-2 py-1.5">Indication</th><th scope="col" class="px-2 py-1.5">Latest follow-up</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!g.consultations.length"><td colspan="10" class="px-2 py-2 text-ink-300">Nothing on the books.</td></tr>
                        <tr v-for="c in g.consultations" :key="c.id" class="border-b border-ink-50 align-top">
                            <td class="nums px-2 py-1.5 font-semibold text-ink-700">{{ c.bed || '—' }}</td>
                            <td class="nums px-2 py-1.5">{{ c.mrn || '—' }}</td>
                            <td class="px-2 py-1.5 font-semibold text-ink-800">{{ c.name }}</td>
                            <td class="nums px-2 py-1.5">{{ c.age ?? '—' }}</td>
                            <td class="px-2 py-1.5"><span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold print:p-0 print:text-xs" :class="locTone(c.location)">{{ c.location || '—' }}</span></td>
                            <td class="px-2 py-1.5"><span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold print:p-0 print:text-xs" :class="statusTone(c.status)">{{ statusLabel(c.status) }}</span></td>
                            <td class="px-2 py-1.5">
                                <span class="nums rounded-full px-1.5 py-0.5 text-[10px] font-semibold print:p-0 print:text-xs" :class="ageTone(c.open_days)">{{ c.open_days === null ? 'unknown' : 'open ' + c.open_days + 'd' }}</span>
                                <div class="nums mt-0.5 text-ink-400">{{ c.requested_on || '—' }}</div>
                            </td>
                            <td class="px-2 py-1.5">
                                <div class="font-semibold text-ink-700">{{ c.consultant }}</div>
                                <div class="text-ink-400">entered by {{ c.entered_by }}</div>
                            </td>
                            <td class="px-2 py-1.5 text-ink-600">
                                <div v-for="r in c.reasons" :key="r">{{ r }}</div>
                                <div v-if="c.other" class="text-ink-500">{{ c.other }}</div>
                                <span v-if="!c.reasons.length && !c.other" class="text-ink-300">—</span>
                            </td>
                            <td class="px-2 py-1.5 text-ink-600">
                                <template v-if="c.last_followup">
                                    <div>
                                        <span class="nums font-semibold text-brand-700">{{ c.last_followup.date }}</span>
                                        · {{ c.last_followup.author }}
                                        <span v-if="c.last_followup.is_today" class="ml-1 rounded-full bg-tint-success px-1.5 py-0.5 text-[10px] font-semibold text-on-success print:p-0 print:text-xs">seen today</span>
                                    </div>
                                    <div v-if="c.last_followup.note">{{ c.last_followup.note }}</div>
                                </template>
                                <span v-else class="text-ink-300">No follow-up recorded</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <p v-if="!groups.length" class="py-10 text-center text-sm text-ink-400">No active or ongoing consultations to hand over.</p>

            <footer class="mt-6 border-t border-line pt-3 text-center text-[11px] text-ink-400">
                DMC Internal Medicine · Patient-Flow Hub · Coordination record — the clinical note lives in the HIS · Confidential — contains patient-identifiable data
            </footer>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd laravel && npx vitest run resources/js/Pages/Consultations/__tests__/Handover.spec.js
```

Expected: `Test Files  1 passed (1)` / `Tests  8 passed (8)`.

Then the whole frontend suite, and the mandatory build + gate sequence (this task changed `resources/`):

```bash
cd laravel && npx vitest run
cd laravel && npx vite build
cd laravel && node scripts/check-source-allowlist.mjs --write
cd laravel && node scripts/check-source-allowlist.mjs
cd laravel && node scripts/contrast.mjs
```

Expected: vitest all green; `vite build` completes; the second allowlist run prints `PASS`; `contrast.mjs` prints `PASS`.

- [ ] **Step 5: Commit**

```bash
cd laravel && git add resources/js/Pages/Consultations/Handover.vue resources/js/Pages/Consultations/__tests__/Handover.spec.js public/build scripts && git commit -m "feat(consultations): handover sheet page

Renders the service's active and ongoing consults with each one's latest
follow-up note, author and date, plus ageing, owning consultant and the
distinctly-shown entered_by. Read-only: no writes from this page. Group header
carries today's follow-up completeness (seen N of M active). Reuses the existing
AA-verified tint/on token pairs. Rebuilt public/build; allowlist and contrast PASS."
```

---

### Task 24: Print styling, the service print filter, and the sidebar entry point

The sheet is meant to be printed at shift change, so this task gives it the same print contract as the Active List census: A4 page box, chrome suppressed, no page break inside a service block, and a repeating table header across pages. It also wires the screen-only service filter (already scaffolded in Task 23's script block) into an assertion, and puts the page in the Clinical nav so it is reachable.

**Files:**
- Modify: `laravel/resources/js/Pages/Consultations/Handover.vue`
- Modify: `laravel/resources/js/Layouts/AppLayout.vue`
- Test: `laravel/resources/js/Pages/Consultations/__tests__/Handover.spec.js`

- [ ] **Step 1: Write the failing test**

Append to `laravel/resources/js/Pages/Consultations/__tests__/Handover.spec.js` — add the `readFileSync`/`resolve` imports at the top of the file, then add the two new `describe` blocks at the bottom.

Change the first two lines of the file from:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
```

to:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
```

Then append these blocks to the end of the file:

```js
const nephrology = () => group('Nephrology', 'Nephrology', [
    consult({ id: 3, name: 'Renal Patient', mrn: '90000003', specialty: 'Nephrology', consultant: 'Dr Nephro', status: 'ongoing', last_followup: null }),
]);

describe('Consultations/Handover — printing', () => {
    it('keeps the toolbar off the printout', () => {
        expect(mountPage().find('.no-print').exists()).toBe(true);
    });

    it('wraps each service in a block the print stylesheet can keep whole', () => {
        expect(mountPage().findAll('.group-block').length).toBe(1);
    });

    it('ships a print stylesheet that sets an A4 page box and hides the app chrome', () => {
        const src = readFileSync(resolve(__dirname, '../Handover.vue'), 'utf8');
        expect(src).toContain('@page { size: A4; margin: 12mm; }');
        expect(src).toContain('@media print');
        expect(src).toContain('break-inside: avoid');
        expect(src).toContain('table-header-group');
    });

    it('renders the sheet at full page width so columns fit their text', () => {
        expect(mountPage().find('.report').classes()).toContain('max-w-full');
    });

    it('hides the service picker for a single-service viewer', () => {
        expect(mountPage().find('select').exists()).toBe(false);
    });

    it('offers "All services" plus one option per service when several are visible', () => {
        const opts = mountPage(props({ groups: [cardiology(), nephrology()] })).findAll('option').map((o) => o.text());
        expect(opts[0]).toContain('All services');
        expect(opts.some((t) => t.includes('Cardiology'))).toBe(true);
        expect(opts.some((t) => t.includes('Nephrology'))).toBe(true);
    });

    it('selecting one service drops the others from the printout and labels the header', async () => {
        const w = mountPage(props({ groups: [cardiology(), nephrology()] }));
        await w.find('select').setValue('Nephrology');
        const report = w.find('.report').text();
        expect(report).toContain('Renal Patient');
        expect(report).not.toContain('Ward Patient');
        expect(report).toContain('— Nephrology');
    });

    it('the header totals track the selection', async () => {
        const w = mountPage(props({ groups: [cardiology(), nephrology()] }));
        expect(w.text()).toContain('3 open consult(s)');
        await w.find('select').setValue('Nephrology');
        expect(w.text()).toContain('1 open consult(s)');
    });
});

describe('Handover is reachable from the Clinical nav', () => {
    // Asserted at source level: mounting AppLayout.vue would mean stubbing Inertia's Head/Link/
    // router/usePage plus the tour, session-timeout and recent-patient modules for what is a
    // one-line nav registration. The route string is the thing that must not silently disappear.
    it('registers the handover route in AppLayout', () => {
        const src = readFileSync(resolve(__dirname, '../../../Layouts/AppLayout.vue'), 'utf8');
        expect(src).toContain("href: '/consultations/handover'");
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd laravel && npx vitest run resources/js/Pages/Consultations/__tests__/Handover.spec.js
```

Expected failures — the page has no `<style>` block yet and the nav has no entry:

```
FAILED  ships a print stylesheet that sets an A4 page box and hides the app chrome
  expected '<script setup>…' to contain '@page { size: A4; margin: 12mm; }'

FAILED  registers the handover route in AppLayout
  expected '<script setup>…' to contain "href: '/consultations/handover'"
```

- [ ] **Step 3: Write the implementation**

Append the print stylesheet to `laravel/resources/js/Pages/Consultations/Handover.vue`, after the closing `</template>` tag (the style block is intentionally **not** scoped: it has to reach the layout chrome rendered by `AppLayout`, exactly as `Pages/ActiveList.vue` does):

```vue
<style>
@page { size: A4; margin: 12mm; }
@media print {
    aside, header.sticky { display: none !important; }
    [class*="pl-64"] { padding-left: 0 !important; }
    main { padding: 0 !important; }
    body { background: #fff !important; }
    .no-print { display: none !important; }
    /* a service's block must not be split across a page break — the sheet is read per team */
    .group-block { break-inside: avoid; }
    /* a service with many open consults WILL span pages; repeat the column header on each one */
    thead { display: table-header-group; }
    tr { break-inside: avoid; }
}
</style>
```

Then add the nav entry in `laravel/resources/js/Layouts/AppLayout.vue`. Find the Clinical section inside `clinicalNavSections`, which currently ends:

```js
            { label: 'Active List', href: '/active-list', icon: 'list', can: true },
            { label: 'Consultations', href: '/consultations', icon: 'chat', can: !observer },
        ] },
```

and change it to:

```js
            { label: 'Active List', href: '/active-list', icon: 'list', can: true },
            { label: 'Consultations', href: '/consultations', icon: 'chat', can: !observer },
            // W3: the printable per-service shift-handover sheet. Same reasoning as Active List —
            // it is read at every handover, so it gets its own row rather than hiding behind an
            // icon on the workspace. Server-side gate is identical (observers are refused).
            { label: 'Consult Handover', href: '/consultations/handover', icon: 'clipboard', can: !observer },
        ] },
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd laravel && npx vitest run resources/js/Pages/Consultations/__tests__/Handover.spec.js
```

Expected: `Tests  17 passed (17)`.

Then the full frontend suite and the mandatory build + gate sequence (this task changed `resources/`):

```bash
cd laravel && npx vitest run
cd laravel && npx vite build
cd laravel && node scripts/check-source-allowlist.mjs --write
cd laravel && node scripts/check-source-allowlist.mjs
cd laravel && node scripts/contrast.mjs
```

Expected: vitest all green; `vite build` completes; the second allowlist run prints `PASS`; `contrast.mjs` prints `PASS`.

Finally re-run the backend suite to confirm the wave is closed with nothing red:

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test
```

Expected: whole backend suite green.

- [ ] **Step 5: Commit**

```bash
cd laravel && git add resources/js/Pages/Consultations/Handover.vue resources/js/Pages/Consultations/__tests__/Handover.spec.js resources/js/Layouts/AppLayout.vue public/build scripts && git commit -m "feat(consultations): handover print styling, service print filter, nav entry

A4 page box, app chrome suppressed, no page break inside a service block, and a
repeating table header for services that span pages — the same print contract as
the Active List census. Admins and coordinators get a screen-only service picker
that narrows the printout to one team and relabels the header; totals track the
selection. Clinical nav gains a Consult Handover row (observers excluded, matching
the server gate). Rebuilt public/build; allowlist and contrast PASS."
```
## Wave W4 — The physician-scoped consultation dashboard

This wave gives clinical users their first look at consultation analytics without touching the admin-only Statistics, Registry or Reports routes: one new page, `GET /consultations/dashboard`, living inside the ordinary `auth` group and scoped entirely by `Consultation::scopeVisibleTo()`, so a consultant sees their own specialty's book while admins and coordinators may pick a specialty. It delivers six metric families — open counts by status, ageing buckets, today's follow-up completeness, the two turnaround medians, volume trend + top indications, and per-consultant load — each computed with an explicitly documented SQL predicate, each excluding soft-deleted rows, and the two medians deliberately excluding every historical row whose `requested_at` is NULL rather than fabricating precision that never existed.

W4 adds **no migration and no schema change**: every column it reads (`status`, `owning_specialty_id`, `requested_at`, `signed_off_at`) and the `consultation_followups` table come from W1/W2, and `can_coordinate_consultations` / `User::canCoordinateConsultations()` come from W1. It deliberately leaves to earlier waves: the `legacy:import` safety gate and the two defect fixes (W0), the schema + backfill + coordinator capability (W1), the state machine, sign-off form and follow-up log that *produce* these numbers (W2), and the handover view (W3). It also deliberately ships **no export and no PDF** — the admin Reports/Statistics exports remain the only PHI-bearing download paths, unchanged.

Tasks 25–28 build the controller metric by metric (the page renders through Inertia and is asserted with `assertInertia`, which never needs the Vue component to exist); Task 29 builds the Vue page, wires the nav entry, and rebuilds `public/build`.

---

### Task 25: Physician-scoped dashboard route, scoping gate, open counts and ageing buckets

**Files:**
- Create: `laravel/app/Http/Controllers/ConsultationDashboardController.php`
- Modify: `laravel/routes/web.php`
- Test: `laravel/tests/Feature/ConsultationDashboardScopeTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationDashboardScopeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * W4 — Task 25. GET /consultations/dashboard is a PHYSICIAN route (ordinary auth group), not an
 * admin one: Statistics / Registry / Reports stay admin-only and are untouched. Scoping is
 * Consultation::scopeVisibleTo(); admins + coordinators may narrow to one specialty with
 * ?specialty_id=N. Observers are refused BEFORE any capability flag is consulted.
 *
 * Metrics asserted here:
 *   openCounts — COUNT(*) GROUP BY status over rows with status <> 'signed_off'
 *   ageing     — DATEDIFF(CURDATE(), DATE(COALESCE(requested_at, consultation_date))) bucketed
 *                0-2d / 3-7d / >7d, with rows lacking BOTH dates reported as 'unknown'
 */
class ConsultationDashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cds_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'CDS User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function consult(array $attrs = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'CDS Patient',
            'consultation_date' => now()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $attrs));
    }

    public function test_observer_is_refused_before_any_capability_flag(): void
    {
        // can_manage AND the coordinator flag are both ON — the observer gate still wins.
        $observer = $this->user(User::ROLE_OBSERVER, [
            'can_manage' => 1, 'can_coordinate_consultations' => 1,
        ]);

        $this->actingAs($observer)->get('/consultations/dashboard')->assertForbidden();
    }

    public function test_consultant_sees_only_their_own_specialty_counts(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_NEW]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_SIGNED_OFF]);
        $this->consult(['owning_specialty_id' => $nephro->id, 'status' => Consultation::STATUS_ONGOING]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Consultations/Dashboard')
                ->where('openCounts.new', 1)
                ->where('openCounts.active', 2)
                ->where('openCounts.ongoing', 0)          // the ongoing row belongs to Nephrology
                ->where('openCounts.total', 3)            // signed_off is never an open count
                ->where('canPick', false)
                ->where('scopeLabel', 'Cardiology'));
    }

    public function test_admin_sees_all_specialties_and_can_narrow_to_one(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_NEW]);
        $this->consult(['owning_specialty_id' => $nephro->id, 'status' => Consultation::STATUS_ONGOING]);

        $this->actingAs($admin)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('openCounts.total', 2)
                ->where('canPick', true)
                ->where('scopeLabel', 'All specialties')
                ->where('specialties', fn ($s) => collect($s)->pluck('name')->sort()->values()->all()
                    === ['Cardiology', 'Nephrology']));

        $this->actingAs($admin)->get("/consultations/dashboard?specialty_id={$nephro->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('openCounts.total', 1)
                ->where('openCounts.ongoing', 1)
                ->where('scopeLabel', 'Nephrology')
                ->where('filters.specialty_id', $nephro->id));
    }

    public function test_coordinator_may_pick_a_specialty_but_a_plain_consultant_may_not(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $coordinator = $this->user(User::ROLE_REGISTRAR, [
            'specialty_id' => $cardio->id, 'can_coordinate_consultations' => 1,
        ]);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_NEW]);
        $this->consult(['owning_specialty_id' => $nephro->id, 'status' => Consultation::STATUS_NEW]);

        $this->actingAs($coordinator)->get("/consultations/dashboard?specialty_id={$nephro->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('canPick', true)
                ->where('openCounts.total', 1)
                ->where('scopeLabel', 'Nephrology'));

        // a plain consultant's specialty_id parameter is IGNORED — scopeVisibleTo still rules
        $this->actingAs($doc)->get("/consultations/dashboard?specialty_id={$nephro->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('canPick', false)
                ->where('filters.specialty_id', null)
                ->where('openCounts.total', 1)
                ->where('scopeLabel', 'Cardiology'));
    }

    public function test_ageing_buckets_use_requested_at_falling_back_to_consultation_date(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $base = ['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE];

        // 0-2d: requested today, and requested 2 days ago
        $this->consult([...$base, 'requested_at' => now()]);
        $this->consult([...$base, 'requested_at' => now()->subDays(2)]);
        // 3-7d: requested 5 days ago
        $this->consult([...$base, 'requested_at' => now()->subDays(5)]);
        // >7d: a HISTORICAL row — requested_at NULL, so consultation_date is the fallback
        $this->consult([...$base, 'requested_at' => null,
            'consultation_date' => now()->subDays(30)->toDateString()]);
        // unknown: neither date (a legacy row with no consultation_date at all)
        $this->consult([...$base, 'requested_at' => null, 'consultation_date' => null]);
        // signed-off rows never age
        $this->consult([...$base, 'status' => Consultation::STATUS_SIGNED_OFF,
            'requested_at' => now()->subDays(40)]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('ageing.b0_2', 2)
                ->where('ageing.b3_7', 1)
                ->where('ageing.b8_plus', 1)
                ->where('ageing.unknown', 1));
    }

    public function test_soft_deleted_consultations_are_excluded_from_every_count(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE,
            'requested_at' => now()]);
        $gone = $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE,
            'requested_at' => now()]);
        $gone->delete();

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('openCounts.active', 1)
                ->where('openCounts.total', 1)
                ->where('ageing.b0_2', 1));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationDashboardScopeTest
```

Expected failure — the route does not exist yet, so every method fails with a 404:

```
FAILED  Tests\Feature\ConsultationDashboardScopeTest > observer is refused before any capability flag
Expected response status code [403] but received 404.
```

- [ ] **Step 3: Write the implementation**

Create `laravel/app/Http/Controllers/ConsultationDashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * W4 — the PHYSICIAN-scoped consultation dashboard.
 *
 * Statistics, Registry and Reports are admin-only by design (PHI exposure control) and stay that
 * way. This controller is the clinical alternative: it lives in the ordinary auth group and is
 * scoped by Consultation::scopeVisibleTo($user) — a consultant sees their own specialty's book,
 * an admin or a coordinator sees everything and may narrow to one specialty with ?specialty_id=N.
 *
 * Observers are refused FIRST, before any capability flag is consulted (the J1-9 global read-only
 * guarantee — a capability flag must never open a door the role closes).
 *
 * Every query goes through baseQuery(), which pins the three invariants in one place:
 *   1. scopeVisibleTo — the authorization scope, never re-implemented per metric;
 *   2. an EXPLICIT whereNull('consultations.deleted_at') — soft-deleted rows are excluded from
 *      every analytic in this app (see Concerns\MetricQueries). The SoftDeletes global scope
 *      already adds this; it is repeated deliberately so the invariant is visible at the call
 *      site and survives a future switch to a raw DB::table() builder;
 *   3. the optional specialty narrowing, which only a picker can set.
 */
class ConsultationDashboardController extends Controller
{
    /** null = no narrowing (the viewer's full visible scope). */
    private ?int $scopeSpecialtyId = null;

    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }

        $data = $request->validate([
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
        ]);

        // Coordinators and admins choose a specialty; everyone else is pinned by scopeVisibleTo,
        // so their ?specialty_id is ignored rather than honoured (it must never widen a scope).
        $canPick = $user->isAdmin() || $user->canCoordinateConsultations();
        $this->scopeSpecialtyId = $canPick && isset($data['specialty_id']) ? (int) $data['specialty_id'] : null;

        return Inertia::render('Consultations/Dashboard', [
            'canPick' => $canPick,
            'filters' => ['specialty_id' => $this->scopeSpecialtyId],
            'specialties' => $canPick ? Specialty::orderBy('name')->get(['id', 'name']) : [],
            'scopeLabel' => $this->scopeLabel($user, $canPick),
            'openCounts' => $this->openCounts(),
            'ageing' => $this->ageing(),
            'generatedAt' => now()->format('H:i'),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** The ONE scoped query builder — see the class docblock for the three invariants it pins. */
    private function baseQuery(): Builder
    {
        return Consultation::query()
            ->visibleTo($this->currentUser())
            ->whereNull('consultations.deleted_at')
            ->when($this->scopeSpecialtyId !== null,
                fn (Builder $q) => $q->where('consultations.owning_specialty_id', $this->scopeSpecialtyId));
    }

    private function scopeLabel(User $user, bool $canPick): string
    {
        if ($this->scopeSpecialtyId !== null) {
            return (string) (Specialty::whereKey($this->scopeSpecialtyId)->value('name') ?? 'Unknown specialty');
        }
        if ($canPick) {
            return 'All specialties';
        }

        return (string) (Specialty::whereKey($user->specialty_id)->value('name') ?? 'Unassigned');
    }

    /**
     * Open counts by status:
     *   SELECT status, COUNT(*) FROM consultations
     *   WHERE deleted_at IS NULL AND <scope> AND status <> 'signed_off'
     *   GROUP BY status
     * Signed-off rows are never an "open" count, so the three keys always sum to `total`.
     */
    private function openCounts(): array
    {
        $rows = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->selectRaw('consultations.status k, COUNT(*) c')
            ->groupBy('k')->pluck('c', 'k')->all();

        $new = (int) ($rows[Consultation::STATUS_NEW] ?? 0);
        $active = (int) ($rows[Consultation::STATUS_ACTIVE] ?? 0);
        $ongoing = (int) ($rows[Consultation::STATUS_ONGOING] ?? 0);

        return ['new' => $new, 'active' => $active, 'ongoing' => $ongoing,
            'total' => $new + $active + $ongoing];
    }

    /**
     * Ageing of OPEN consults, in whole days:
     *   DATEDIFF(CURDATE(), DATE(COALESCE(requested_at, consultation_date)))
     * `requested_at` is authoritative from cutover onward; `consultation_date` is the historical
     * fallback (all 1,283 legacy rows have requested_at NULL — see the design §4.4). A row with
     * NEITHER date is reported as `unknown` rather than silently bucketed: this dashboard never
     * invents a date it does not have.
     */
    private function ageing(): array
    {
        $age = 'DATEDIFF(CURDATE(), DATE(COALESCE(consultations.requested_at, consultations.consultation_date)))';
        $rows = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->selectRaw("CASE
                    WHEN COALESCE(consultations.requested_at, consultations.consultation_date) IS NULL THEN 'unknown'
                    WHEN {$age} <= 2 THEN 'b0_2'
                    WHEN {$age} <= 7 THEN 'b3_7'
                    ELSE 'b8_plus'
                END k, COUNT(*) c")
            ->groupBy('k')->pluck('c', 'k')->all();

        return [
            'b0_2' => (int) ($rows['b0_2'] ?? 0),
            'b3_7' => (int) ($rows['b3_7'] ?? 0),
            'b8_plus' => (int) ($rows['b8_plus'] ?? 0),
            'unknown' => (int) ($rows['unknown'] ?? 0),
        ];
    }
}
```

Modify `laravel/routes/web.php` — add the import next to the existing `ConsultationsController` import (the `use` block is alphabetical):

```php
use App\Http\Controllers\ConsultationDashboardController;
use App\Http\Controllers\ConsultationsController;
```

and add the route immediately after the existing `Route::get('/consultations/search', ...)` line inside the `auth` group (the literal `dashboard` segment cannot collide — there is no `GET /consultations/{consultation}` route):

```php
    Route::get('/consultations/search', fn () => redirect()->route('consultations.index'));
    // W4 — physician-scoped consultation analytics. Statistics / Registry / Reports stay ADMIN-ONLY
    // (PHI exposure control); this page is the clinical alternative and is scoped by
    // Consultation::scopeVisibleTo() instead of by an admin middleware.
    Route::get('/consultations/dashboard', [ConsultationDashboardController::class, 'index'])->name('consultations.dashboard');
    Route::post('/consultations/{consultation}/signoff', [ConsultationsController::class, 'signoff'])->name('consultations.signoff');
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationDashboardScopeTest
```

Expected:

```
PASS  Tests\Feature\ConsultationDashboardScopeTest
  ✓ observer is refused before any capability flag
  ✓ consultant sees only their own specialty counts
  ✓ admin sees all specialties and can narrow to one
  ✓ coordinator may pick a specialty but a plain consultant may not
  ✓ ageing buckets use requested at falling back to consultation date
  ✓ soft deleted consultations are excluded from every count

Tests:  6 passed
```

- [ ] **Step 5: Commit**

```bash
cd laravel && git add app/Http/Controllers/ConsultationDashboardController.php routes/web.php tests/Feature/ConsultationDashboardScopeTest.php && git commit -m "feat(consultations): physician-scoped dashboard route with open counts + ageing buckets (W4)

GET /consultations/dashboard lives in the ordinary auth group and is scoped by
Consultation::scopeVisibleTo(); admins and coordinators may narrow with ?specialty_id.
Observers are refused before any capability flag. Statistics/Registry/Reports remain
admin-only and untouched. Ageing reads COALESCE(requested_at, consultation_date) and
reports date-less rows as 'unknown' rather than bucketing them.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 26: Today's follow-up completeness ("seen X of Y")

**Files:**
- Modify: `laravel/app/Http/Controllers/ConsultationDashboardController.php`
- Test: `laravel/tests/Feature/ConsultationDashboardFollowupTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationDashboardFollowupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * W4 — Task 26. Today's follow-up completeness.
 *
 * Denominator Y = consults in scope with status = 'active' (the ONLY status that asserts a daily
 * follow-up obligation — 'ongoing' is explicitly "on the books, no daily commitment", design §4.4).
 * Numerator X = those with a consultation_followups row whose followup_date = CURDATE().
 * The unique([consultation_id, followup_date]) constraint from W2 makes X exact by construction.
 *
 * Follow-ups are inserted with DB::table() so the append-only created_at can be set explicitly
 * regardless of how the ConsultationFollowup model configures timestamps.
 */
class ConsultationDashboardFollowupTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cdf_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'CDF User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function consult(array $attrs = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'CDF Patient',
            'consultation_date' => now()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $attrs));
    }

    private function followup(Consultation $c, string $date, ?int $authorId = null): void
    {
        DB::table('consultation_followups')->insert([
            'consultation_id' => $c->id,
            'followup_date' => $date,
            'note' => 'seen on rounds',
            'author_id' => $authorId,
            'created_at' => now(),
        ]);
    }

    public function test_today_completeness_counts_only_active_consults_ticked_today(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $base = ['owning_specialty_id' => $cardio->id, 'requested_at' => now()];

        $seen1 = $this->consult([...$base, 'status' => Consultation::STATUS_ACTIVE]);
        $seen2 = $this->consult([...$base, 'status' => Consultation::STATUS_ACTIVE]);
        $notSeen = $this->consult([...$base, 'status' => Consultation::STATUS_ACTIVE]);
        $staleTick = $this->consult([...$base, 'status' => Consultation::STATUS_ACTIVE]);
        // 'ongoing' carries NO daily obligation, so it is in neither X nor Y
        $ongoing = $this->consult([...$base, 'status' => Consultation::STATUS_ONGOING]);
        // 'new' has not been picked up yet — also outside the daily worklist
        $this->consult([...$base, 'status' => Consultation::STATUS_NEW]);

        $this->followup($seen1, now()->toDateString(), $doc->id);
        $this->followup($seen2, now()->toDateString(), $doc->id);
        $this->followup($staleTick, now()->subDay()->toDateString(), $doc->id);   // yesterday ≠ today
        $this->followup($ongoing, now()->toDateString(), $doc->id);               // not in the denominator

        $this->assertNotNull($notSeen->id);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('today.due', 4)
                ->where('today.seen', 2));
    }

    public function test_today_completeness_respects_the_specialty_scope(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $mine = $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE]);
        $theirs = $this->consult(['owning_specialty_id' => $nephro->id, 'status' => Consultation::STATUS_ACTIVE]);
        $this->followup($mine, now()->toDateString());
        $this->followup($theirs, now()->toDateString());

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('today.due', 1)
                ->where('today.seen', 1));
    }

    public function test_soft_deleted_active_consults_drop_out_of_the_denominator(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $live = $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE]);
        $gone = $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE]);
        $this->followup($live, now()->toDateString());
        $this->followup($gone, now()->toDateString());
        $gone->delete();

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('today.due', 1)
                ->where('today.seen', 1));
    }

    public function test_an_empty_worklist_reports_zero_of_zero_not_a_division(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('today.due', 0)
                ->where('today.seen', 0));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationDashboardFollowupTest
```

Expected failure — the `today` prop is not in the payload yet:

```
FAILED  Tests\Feature\ConsultationDashboardFollowupTest > today completeness counts only active consults ticked today
Inertia property [today.due] does not exist.
```

- [ ] **Step 3: Write the implementation**

Replace `laravel/app/Http/Controllers/ConsultationDashboardController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * W4 — the PHYSICIAN-scoped consultation dashboard.
 *
 * Statistics, Registry and Reports are admin-only by design (PHI exposure control) and stay that
 * way. This controller is the clinical alternative: it lives in the ordinary auth group and is
 * scoped by Consultation::scopeVisibleTo($user) — a consultant sees their own specialty's book,
 * an admin or a coordinator sees everything and may narrow to one specialty with ?specialty_id=N.
 *
 * Observers are refused FIRST, before any capability flag is consulted (the J1-9 global read-only
 * guarantee — a capability flag must never open a door the role closes).
 *
 * Every query goes through baseQuery(), which pins the three invariants in one place:
 *   1. scopeVisibleTo — the authorization scope, never re-implemented per metric;
 *   2. an EXPLICIT whereNull('consultations.deleted_at') — soft-deleted rows are excluded from
 *      every analytic in this app (see Concerns\MetricQueries). The SoftDeletes global scope
 *      already adds this; it is repeated deliberately so the invariant is visible at the call
 *      site and survives a future switch to a raw DB::table() builder;
 *   3. the optional specialty narrowing, which only a picker can set.
 */
class ConsultationDashboardController extends Controller
{
    /** null = no narrowing (the viewer's full visible scope). */
    private ?int $scopeSpecialtyId = null;

    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }

        $data = $request->validate([
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
        ]);

        // Coordinators and admins choose a specialty; everyone else is pinned by scopeVisibleTo,
        // so their ?specialty_id is ignored rather than honoured (it must never widen a scope).
        $canPick = $user->isAdmin() || $user->canCoordinateConsultations();
        $this->scopeSpecialtyId = $canPick && isset($data['specialty_id']) ? (int) $data['specialty_id'] : null;

        return Inertia::render('Consultations/Dashboard', [
            'canPick' => $canPick,
            'filters' => ['specialty_id' => $this->scopeSpecialtyId],
            'specialties' => $canPick ? Specialty::orderBy('name')->get(['id', 'name']) : [],
            'scopeLabel' => $this->scopeLabel($user, $canPick),
            'openCounts' => $this->openCounts(),
            'ageing' => $this->ageing(),
            'today' => $this->todayCompleteness(now()->toDateString()),
            'generatedAt' => now()->format('H:i'),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** The ONE scoped query builder — see the class docblock for the three invariants it pins. */
    private function baseQuery(): Builder
    {
        return Consultation::query()
            ->visibleTo($this->currentUser())
            ->whereNull('consultations.deleted_at')
            ->when($this->scopeSpecialtyId !== null,
                fn (Builder $q) => $q->where('consultations.owning_specialty_id', $this->scopeSpecialtyId));
    }

    private function scopeLabel(User $user, bool $canPick): string
    {
        if ($this->scopeSpecialtyId !== null) {
            return (string) (Specialty::whereKey($this->scopeSpecialtyId)->value('name') ?? 'Unknown specialty');
        }
        if ($canPick) {
            return 'All specialties';
        }

        return (string) (Specialty::whereKey($user->specialty_id)->value('name') ?? 'Unassigned');
    }

    /**
     * Open counts by status:
     *   SELECT status, COUNT(*) FROM consultations
     *   WHERE deleted_at IS NULL AND <scope> AND status <> 'signed_off'
     *   GROUP BY status
     * Signed-off rows are never an "open" count, so the three keys always sum to `total`.
     */
    private function openCounts(): array
    {
        $rows = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->selectRaw('consultations.status k, COUNT(*) c')
            ->groupBy('k')->pluck('c', 'k')->all();

        $new = (int) ($rows[Consultation::STATUS_NEW] ?? 0);
        $active = (int) ($rows[Consultation::STATUS_ACTIVE] ?? 0);
        $ongoing = (int) ($rows[Consultation::STATUS_ONGOING] ?? 0);

        return ['new' => $new, 'active' => $active, 'ongoing' => $ongoing,
            'total' => $new + $active + $ongoing];
    }

    /**
     * Ageing of OPEN consults, in whole days:
     *   DATEDIFF(CURDATE(), DATE(COALESCE(requested_at, consultation_date)))
     * `requested_at` is authoritative from cutover onward; `consultation_date` is the historical
     * fallback (all 1,283 legacy rows have requested_at NULL — see the design §4.4). A row with
     * NEITHER date is reported as `unknown` rather than silently bucketed: this dashboard never
     * invents a date it does not have.
     */
    private function ageing(): array
    {
        $age = 'DATEDIFF(CURDATE(), DATE(COALESCE(consultations.requested_at, consultations.consultation_date)))';
        $rows = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->selectRaw("CASE
                    WHEN COALESCE(consultations.requested_at, consultations.consultation_date) IS NULL THEN 'unknown'
                    WHEN {$age} <= 2 THEN 'b0_2'
                    WHEN {$age} <= 7 THEN 'b3_7'
                    ELSE 'b8_plus'
                END k, COUNT(*) c")
            ->groupBy('k')->pluck('c', 'k')->all();

        return [
            'b0_2' => (int) ($rows['b0_2'] ?? 0),
            'b3_7' => (int) ($rows['b3_7'] ?? 0),
            'b8_plus' => (int) ($rows['b8_plus'] ?? 0),
            'unknown' => (int) ($rows['unknown'] ?? 0),
        ];
    }

    /**
     * Today's follow-up completeness — "seen X of Y".
     *
     *   Y (due)  = COUNT(*) over the scope WHERE status = 'active'
     *              ('active' is the only status that asserts a DAILY follow-up obligation;
     *               'ongoing' is deliberately on-the-books-without-a-daily-commitment, and
     *               'new' has not been picked up yet — design §2 / §4.4.)
     *   X (seen) = the same set, restricted with EXISTS (
     *                  SELECT 1 FROM consultation_followups f
     *                  WHERE f.consultation_id = consultations.id AND f.followup_date = CURDATE())
     *
     * EXISTS rather than a JOIN so a (hypothetically) duplicated tick could never double-count;
     * W2's unique([consultation_id, followup_date]) already makes that impossible, and this keeps
     * the count exact even if that constraint were ever relaxed. Both figures come back as plain
     * integers — the ratio is formatted in the UI, so an empty worklist is "0 of 0", never a
     * division by zero.
     */
    private function todayCompleteness(string $today): array
    {
        $due = (int) $this->baseQuery()
            ->where('consultations.status', Consultation::STATUS_ACTIVE)
            ->count();

        $seen = (int) $this->baseQuery()
            ->where('consultations.status', Consultation::STATUS_ACTIVE)
            ->whereExists(function ($q) use ($today) {
                $q->select(DB::raw(1))
                    ->from('consultation_followups')
                    ->whereColumn('consultation_followups.consultation_id', 'consultations.id')
                    ->where('consultation_followups.followup_date', $today);
            })
            ->count();

        return ['due' => $due, 'seen' => $seen];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter="ConsultationDashboardFollowupTest|ConsultationDashboardScopeTest"
```

Expected:

```
PASS  Tests\Feature\ConsultationDashboardFollowupTest
  ✓ today completeness counts only active consults ticked today
  ✓ today completeness respects the specialty scope
  ✓ soft deleted active consults drop out of the denominator
  ✓ an empty worklist reports zero of zero not a division

PASS  Tests\Feature\ConsultationDashboardScopeTest
  ✓ observer is refused before any capability flag
  ✓ consultant sees only their own specialty counts
  ✓ admin sees all specialties and can narrow to one
  ✓ coordinator may pick a specialty but a plain consultant may not
  ✓ ageing buckets use requested at falling back to consultation date
  ✓ soft deleted consultations are excluded from every count

Tests:  10 passed
```

- [ ] **Step 5: Commit**

```bash
cd laravel && git add app/Http/Controllers/ConsultationDashboardController.php tests/Feature/ConsultationDashboardFollowupTest.php && git commit -m "feat(consultations): today's follow-up completeness on the physician dashboard (W4)

'Seen X of Y' over the 'active' worklist only — 'ongoing' asserts no daily obligation by
design, so it is in neither numerator nor denominator. X uses EXISTS against
consultation_followups on CURDATE(), so a tick can never be double-counted; soft-deleted
consults drop out of both figures.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 27: Turnaround medians, excluding every historical NULL `requested_at` row

**Files:**
- Modify: `laravel/app/Http/Controllers/ConsultationDashboardController.php`
- Test: `laravel/tests/Feature/ConsultationDashboardTurnaroundTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationDashboardTurnaroundTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * W4 — Task 27. The two turnaround medians.
 *
 * THE LOAD-BEARING RULE: every historical row has requested_at NULL (design §4.4 — fabricating a
 * time from a date-only legacy column would manufacture precision that never existed). Both
 * medians therefore filter `requested_at IS NOT NULL`, and the payload carries `legacy_excluded`
 * so the UI can state "from cutover — N historical consultations excluded" instead of silently
 * averaging in invented numbers.
 *
 * Follow-ups are inserted with DB::table() so created_at can be set to a real past instant
 * regardless of how the ConsultationFollowup model configures its append-only timestamp.
 */
class ConsultationDashboardTurnaroundTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cdt_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'CDT User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function consult(array $attrs = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'CDT Patient',
            'consultation_date' => now()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $attrs));
    }

    private function followupAt(Consultation $c, string $date, string $createdAt): void
    {
        DB::table('consultation_followups')->insert([
            'consultation_id' => $c->id,
            'followup_date' => $date,
            'note' => null,
            'author_id' => null,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Hand-computed fixture (Cardiology):
     *   A  requested 10h ago, signed off 4h ago            -> sign-off  6h
     *   B  requested 20h ago, signed off 18h ago           -> sign-off  2h
     *   C  HISTORICAL: requested_at NULL, signoff_date set -> EXCLUDED (would have been ~24h)
     * median sign-off over [2, 6] = 4.0h. If C leaked in, [2, 6, 1440] would give 6.0h.
     */
    public function test_median_time_to_signoff_excludes_historical_null_requested_at_rows(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $base = ['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_SIGNED_OFF];

        $this->consult([...$base,
            'requested_at' => now()->subHours(10), 'signed_off_at' => now()->subHours(4),
            'signoff_date' => now()->toDateString()]);
        $this->consult([...$base,
            'requested_at' => now()->subHours(20), 'signed_off_at' => now()->subHours(18),
            'signoff_date' => now()->toDateString()]);
        // the legacy shape: date-only sign-off, no real timestamps at all
        $this->consult([...$base,
            'requested_at' => null, 'signed_off_at' => null,
            'consultation_date' => '2024-01-01', 'signoff_date' => '2024-01-02']);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('turnaround.signoff_hours', 4.0)
                ->where('turnaround.signoff_n', 2)
                ->where('turnaround.legacy_excluded', 1)
                ->where('turnaround.from_cutover', true));
    }

    /**
     * First-follow-up fixture (Cardiology):
     *   D  requested 12h ago, first tick 9h ago, second tick 1h ago -> 3h (the FIRST tick counts)
     *   E  requested  8h ago, first tick 3h ago                     -> 5h
     *   F  HISTORICAL: requested_at NULL, ticked 2h ago             -> EXCLUDED
     *   G  requested 4h ago, never ticked                           -> not in the sample
     * median over [3, 5] = 4.0h, n = 2.
     */
    public function test_median_time_to_first_followup_uses_the_earliest_tick_and_skips_legacy_rows(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $base = ['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE];

        $d = $this->consult([...$base, 'requested_at' => now()->subHours(12)]);
        $this->followupAt($d, now()->subDay()->toDateString(), now()->subHours(9)->toDateTimeString());
        $this->followupAt($d, now()->toDateString(), now()->subHours(1)->toDateTimeString());

        $e = $this->consult([...$base, 'requested_at' => now()->subHours(8)]);
        $this->followupAt($e, now()->toDateString(), now()->subHours(3)->toDateTimeString());

        $f = $this->consult([...$base, 'requested_at' => null, 'consultation_date' => '2024-01-01']);
        $this->followupAt($f, now()->toDateString(), now()->subHours(2)->toDateTimeString());

        $this->consult([...$base, 'requested_at' => now()->subHours(4)]);   // never ticked

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('turnaround.first_followup_hours', 4.0)
                ->where('turnaround.first_followup_n', 2));
    }

    public function test_an_all_historical_scope_reports_null_medians_and_the_excluded_count(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        // exactly the shape of the 1,283 imported rows: dates only, no timestamps
        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ONGOING,
            'requested_at' => null, 'consultation_date' => '2024-03-01']);
        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_SIGNED_OFF,
            'requested_at' => null, 'consultation_date' => '2024-03-02', 'signoff_date' => '2024-03-05']);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('turnaround.first_followup_hours', null)
                ->where('turnaround.first_followup_n', 0)
                ->where('turnaround.signoff_hours', null)
                ->where('turnaround.signoff_n', 0)
                ->where('turnaround.legacy_excluded', 2));
    }

    public function test_medians_are_scoped_and_exclude_soft_deleted_rows(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $signed = ['status' => Consultation::STATUS_SIGNED_OFF, 'signoff_date' => now()->toDateString()];

        // in scope: 6h
        $this->consult([...$signed, 'owning_specialty_id' => $cardio->id,
            'requested_at' => now()->subHours(10), 'signed_off_at' => now()->subHours(4)]);
        // other specialty: 100h — must not move the median
        $this->consult([...$signed, 'owning_specialty_id' => $nephro->id,
            'requested_at' => now()->subHours(110), 'signed_off_at' => now()->subHours(10)]);
        // soft-deleted, in scope: 200h — must not move the median either
        $trashed = $this->consult([...$signed, 'owning_specialty_id' => $cardio->id,
            'requested_at' => now()->subHours(210), 'signed_off_at' => now()->subHours(10)]);
        $trashed->delete();

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('turnaround.signoff_hours', 6.0)
                ->where('turnaround.signoff_n', 1));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationDashboardTurnaroundTest
```

Expected failure — the `turnaround` prop is not in the payload yet:

```
FAILED  Tests\Feature\ConsultationDashboardTurnaroundTest > median time to signoff excludes historical null requested at rows
Inertia property [turnaround.signoff_hours] does not exist.
```

- [ ] **Step 3: Write the implementation**

Replace `laravel/app/Http/Controllers/ConsultationDashboardController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * W4 — the PHYSICIAN-scoped consultation dashboard.
 *
 * Statistics, Registry and Reports are admin-only by design (PHI exposure control) and stay that
 * way. This controller is the clinical alternative: it lives in the ordinary auth group and is
 * scoped by Consultation::scopeVisibleTo($user) — a consultant sees their own specialty's book,
 * an admin or a coordinator sees everything and may narrow to one specialty with ?specialty_id=N.
 *
 * Observers are refused FIRST, before any capability flag is consulted (the J1-9 global read-only
 * guarantee — a capability flag must never open a door the role closes).
 *
 * Every query goes through baseQuery(), which pins the three invariants in one place:
 *   1. scopeVisibleTo — the authorization scope, never re-implemented per metric;
 *   2. an EXPLICIT whereNull('consultations.deleted_at') — soft-deleted rows are excluded from
 *      every analytic in this app (see Concerns\MetricQueries). The SoftDeletes global scope
 *      already adds this; it is repeated deliberately so the invariant is visible at the call
 *      site and survives a future switch to a raw DB::table() builder;
 *   3. the optional specialty narrowing, which only a picker can set.
 */
class ConsultationDashboardController extends Controller
{
    /** null = no narrowing (the viewer's full visible scope). */
    private ?int $scopeSpecialtyId = null;

    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }

        $data = $request->validate([
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
        ]);

        // Coordinators and admins choose a specialty; everyone else is pinned by scopeVisibleTo,
        // so their ?specialty_id is ignored rather than honoured (it must never widen a scope).
        $canPick = $user->isAdmin() || $user->canCoordinateConsultations();
        $this->scopeSpecialtyId = $canPick && isset($data['specialty_id']) ? (int) $data['specialty_id'] : null;

        return Inertia::render('Consultations/Dashboard', [
            'canPick' => $canPick,
            'filters' => ['specialty_id' => $this->scopeSpecialtyId],
            'specialties' => $canPick ? Specialty::orderBy('name')->get(['id', 'name']) : [],
            'scopeLabel' => $this->scopeLabel($user, $canPick),
            'openCounts' => $this->openCounts(),
            'ageing' => $this->ageing(),
            'today' => $this->todayCompleteness(now()->toDateString()),
            'turnaround' => $this->turnaround(),
            'generatedAt' => now()->format('H:i'),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** The ONE scoped query builder — see the class docblock for the three invariants it pins. */
    private function baseQuery(): Builder
    {
        return Consultation::query()
            ->visibleTo($this->currentUser())
            ->whereNull('consultations.deleted_at')
            ->when($this->scopeSpecialtyId !== null,
                fn (Builder $q) => $q->where('consultations.owning_specialty_id', $this->scopeSpecialtyId));
    }

    private function scopeLabel(User $user, bool $canPick): string
    {
        if ($this->scopeSpecialtyId !== null) {
            return (string) (Specialty::whereKey($this->scopeSpecialtyId)->value('name') ?? 'Unknown specialty');
        }
        if ($canPick) {
            return 'All specialties';
        }

        return (string) (Specialty::whereKey($user->specialty_id)->value('name') ?? 'Unassigned');
    }

    /**
     * Open counts by status:
     *   SELECT status, COUNT(*) FROM consultations
     *   WHERE deleted_at IS NULL AND <scope> AND status <> 'signed_off'
     *   GROUP BY status
     * Signed-off rows are never an "open" count, so the three keys always sum to `total`.
     */
    private function openCounts(): array
    {
        $rows = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->selectRaw('consultations.status k, COUNT(*) c')
            ->groupBy('k')->pluck('c', 'k')->all();

        $new = (int) ($rows[Consultation::STATUS_NEW] ?? 0);
        $active = (int) ($rows[Consultation::STATUS_ACTIVE] ?? 0);
        $ongoing = (int) ($rows[Consultation::STATUS_ONGOING] ?? 0);

        return ['new' => $new, 'active' => $active, 'ongoing' => $ongoing,
            'total' => $new + $active + $ongoing];
    }

    /**
     * Ageing of OPEN consults, in whole days:
     *   DATEDIFF(CURDATE(), DATE(COALESCE(requested_at, consultation_date)))
     * `requested_at` is authoritative from cutover onward; `consultation_date` is the historical
     * fallback (all 1,283 legacy rows have requested_at NULL — see the design §4.4). A row with
     * NEITHER date is reported as `unknown` rather than silently bucketed: this dashboard never
     * invents a date it does not have.
     */
    private function ageing(): array
    {
        $age = 'DATEDIFF(CURDATE(), DATE(COALESCE(consultations.requested_at, consultations.consultation_date)))';
        $rows = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->selectRaw("CASE
                    WHEN COALESCE(consultations.requested_at, consultations.consultation_date) IS NULL THEN 'unknown'
                    WHEN {$age} <= 2 THEN 'b0_2'
                    WHEN {$age} <= 7 THEN 'b3_7'
                    ELSE 'b8_plus'
                END k, COUNT(*) c")
            ->groupBy('k')->pluck('c', 'k')->all();

        return [
            'b0_2' => (int) ($rows['b0_2'] ?? 0),
            'b3_7' => (int) ($rows['b3_7'] ?? 0),
            'b8_plus' => (int) ($rows['b8_plus'] ?? 0),
            'unknown' => (int) ($rows['unknown'] ?? 0),
        ];
    }

    /**
     * Today's follow-up completeness — "seen X of Y".
     *
     *   Y (due)  = COUNT(*) over the scope WHERE status = 'active'
     *              ('active' is the only status that asserts a DAILY follow-up obligation;
     *               'ongoing' is deliberately on-the-books-without-a-daily-commitment, and
     *               'new' has not been picked up yet — design §2 / §4.4.)
     *   X (seen) = the same set, restricted with EXISTS (
     *                  SELECT 1 FROM consultation_followups f
     *                  WHERE f.consultation_id = consultations.id AND f.followup_date = CURDATE())
     *
     * EXISTS rather than a JOIN so a (hypothetically) duplicated tick could never double-count;
     * W2's unique([consultation_id, followup_date]) already makes that impossible, and this keeps
     * the count exact even if that constraint were ever relaxed. Both figures come back as plain
     * integers — the ratio is formatted in the UI, so an empty worklist is "0 of 0", never a
     * division by zero.
     */
    private function todayCompleteness(string $today): array
    {
        $due = (int) $this->baseQuery()
            ->where('consultations.status', Consultation::STATUS_ACTIVE)
            ->count();

        $seen = (int) $this->baseQuery()
            ->where('consultations.status', Consultation::STATUS_ACTIVE)
            ->whereExists(function ($q) use ($today) {
                $q->select(DB::raw(1))
                    ->from('consultation_followups')
                    ->whereColumn('consultation_followups.consultation_id', 'consultations.id')
                    ->where('consultation_followups.followup_date', $today);
            })
            ->count();

        return ['due' => $due, 'seen' => $seen];
    }

    /**
     * The two turnaround medians — CUTOVER ONWARD ONLY.
     *
     * Median time to first follow-up:
     *   SELECT TIMESTAMPDIFF(MINUTE, c.requested_at, MIN(f.created_at))
     *   FROM consultations c JOIN consultation_followups f ON f.consultation_id = c.id
     *   WHERE c.deleted_at IS NULL AND <scope> AND c.requested_at IS NOT NULL
     *   GROUP BY c.id, c.requested_at HAVING mins >= 0
     *
     * Median time to sign-off:
     *   SELECT TIMESTAMPDIFF(MINUTE, requested_at, signed_off_at)
     *   FROM consultations
     *   WHERE deleted_at IS NULL AND <scope>
     *     AND requested_at IS NOT NULL AND signed_off_at IS NOT NULL
     *     AND signed_off_at >= requested_at
     *
     * `requested_at IS NOT NULL` is the load-bearing predicate. EVERY historical row has it NULL
     * by deliberate design (§4.4: fabricating a time from a date-only legacy column would
     * manufacture precision that never existed), so both samples cover cutover onward only. The
     * payload therefore ships `legacy_excluded` — the number of in-scope rows that carry no
     * request timestamp — and `from_cutover`, so the UI can SAY so instead of quietly presenting
     * a mixed number. `signed_off_at >= requested_at` / `HAVING mins >= 0` drop clock-skew or
     * hand-corrected rows rather than letting a negative duration drag the median.
     *
     * The median (not the mean) because a single 3-week outlier consult would otherwise swamp a
     * ward's real turnaround; medians are also what the design table asks for.
     */
    private function turnaround(): array
    {
        $firstFollowup = $this->baseQuery()
            ->join('consultation_followups as f', 'f.consultation_id', '=', 'consultations.id')
            ->whereNotNull('consultations.requested_at')
            ->groupBy('consultations.id', 'consultations.requested_at')
            ->havingRaw('mins >= 0')
            ->selectRaw('TIMESTAMPDIFF(MINUTE, consultations.requested_at, MIN(f.created_at)) mins')
            ->pluck('mins')->map(fn ($m) => (float) $m)->all();

        $signoff = $this->baseQuery()
            ->whereNotNull('consultations.requested_at')
            ->whereNotNull('consultations.signed_off_at')
            ->whereColumn('consultations.signed_off_at', '>=', 'consultations.requested_at')
            ->selectRaw('TIMESTAMPDIFF(MINUTE, consultations.requested_at, consultations.signed_off_at) mins')
            ->pluck('mins')->map(fn ($m) => (float) $m)->all();

        $legacyExcluded = (int) $this->baseQuery()
            ->whereNull('consultations.requested_at')
            ->count();

        return [
            'first_followup_hours' => $this->medianHours($firstFollowup),
            'first_followup_n' => count($firstFollowup),
            'signoff_hours' => $this->medianHours($signoff),
            'signoff_n' => count($signoff),
            'legacy_excluded' => $legacyExcluded,
            'from_cutover' => true,
        ];
    }

    /**
     * Median of a minute list, returned in hours to one decimal — or NULL when the sample is
     * empty. NULL is a deliberate payload value: the UI renders an explicit "not enough data yet"
     * rather than a 0.0 that would read as "instant turnaround".
     *
     * @param  array<int, float>  $minutes
     */
    private function medianHours(array $minutes): ?float
    {
        $v = array_values($minutes);
        sort($v);
        $n = count($v);
        if ($n === 0) {
            return null;
        }
        $mid = intdiv($n, 2);
        $median = $n % 2 === 1 ? $v[$mid] : ($v[$mid - 1] + $v[$mid]) / 2;

        return round($median / 60, 1);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter="ConsultationDashboardTurnaroundTest|ConsultationDashboardFollowupTest|ConsultationDashboardScopeTest"
```

Expected:

```
PASS  Tests\Feature\ConsultationDashboardTurnaroundTest
  ✓ median time to signoff excludes historical null requested at rows
  ✓ median time to first followup uses the earliest tick and skips legacy rows
  ✓ an all historical scope reports null medians and the excluded count
  ✓ medians are scoped and exclude soft deleted rows

Tests:  14 passed
```

- [ ] **Step 5: Commit**

```bash
cd laravel && git add app/Http/Controllers/ConsultationDashboardController.php tests/Feature/ConsultationDashboardTurnaroundTest.php && git commit -m "feat(consultations): turnaround medians, cutover-onward only (W4)

Median minutes from requested_at to the first consultation_followups tick and to
signed_off_at, both filtered on requested_at IS NOT NULL — every historical row has it NULL
by design, so averaging them in would fabricate precision. The payload carries
legacy_excluded + from_cutover so the UI states the exclusion instead of hiding it. Negative
durations (clock skew / hand corrections) are dropped rather than dragging the median.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 28: Volume trend, top indications and per-consultant load

**Files:**
- Modify: `laravel/app/Http/Controllers/ConsultationDashboardController.php`
- Test: `laravel/tests/Feature/ConsultationDashboardVolumeTest.php`

- [ ] **Step 1: Write the failing test**

Create `laravel/tests/Feature/ConsultationDashboardVolumeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * W4 — Task 28. Volume trend, top indications, per-consultant load.
 *
 * The trend and the indication tally both key on COALESCE(requested_at, consultation_date):
 * unlike the turnaround medians, VOLUME is not a precision claim, so historical rows legitimately
 * count — they just key off their date-only column.
 *
 * `indication` is a JSON array of consultation_reason ids tallied in PHP, mirroring
 * StatisticsController::index ("consultations by reason (decode JSON indication in PHP — small
 * set)"). Legacy rows store the ids as JSON STRINGS (["1","3"]) while app-written rows store INTs
 * — the Round5 J1-2 lesson — so the tally casts before looking a name up, and this test pins both
 * shapes.
 */
class ConsultationDashboardVolumeTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cdv_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'CDV User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function consult(array $attrs = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'CDV Patient',
            'consultation_date' => now()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $attrs));
    }

    public function test_volume_trend_buckets_six_months_by_coalesced_request_date(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $base = ['owning_specialty_id' => $cardio->id];

        // two this month (one by requested_at, one historical by consultation_date)
        $this->consult([...$base, 'requested_at' => now()]);
        $this->consult([...$base, 'requested_at' => null, 'consultation_date' => now()->toDateString()]);
        // one two months ago
        $this->consult([...$base, 'requested_at' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(3)]);
        // one far outside the six-month window — must not appear
        $this->consult([...$base, 'requested_at' => null, 'consultation_date' => now()->subYears(2)->toDateString()]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('trend.labels', fn ($l) => count($l) === 6)
                ->where('trend.data', fn ($d) => count($d) === 6
                    && $d[5] === 2                 // current month
                    && $d[3] === 1                 // two months back
                    && array_sum($d) === 3));      // the 2-year-old row is outside the window
    }

    public function test_top_indications_tally_json_ids_in_php_for_both_int_and_string_rows(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        ConsultationReason::create(['name' => 'Chest pain']);          // id 1
        ConsultationReason::create(['name' => 'Arrhythmia']);          // id 2
        ConsultationReason::create(['name' => 'Heart failure']);       // id 3

        // app-written rows: ids as JSON ints
        $this->consult(['owning_specialty_id' => $cardio->id, 'requested_at' => now(), 'indication' => [1, 2]]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'requested_at' => now(), 'indication' => [1]]);
        // a legacy row: ids as JSON STRINGS, dated by consultation_date
        DB::table('consultations')->insert([
            'mrn' => '77000001', 'patient_name' => 'Legacy Pt',
            'consultation_date' => now()->toDateString(),
            'indication' => '["1","3"]',
            'owning_specialty_id' => $cardio->id,
            'status' => Consultation::STATUS_ONGOING,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('topIndications.0.label', 'Chest pain')
                ->where('topIndications.0.value', 3)
                ->where('topIndications', fn ($rows) => collect($rows)
                    ->firstWhere('label', 'Heart failure')['value'] === 1));
    }

    public function test_per_consultant_load_counts_open_consults_only_within_the_scope(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $busy = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Busy']);
        $quiet = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Quiet']);
        $other = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id, 'full_name' => 'Dr Other']);

        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $busy->id, 'status' => Consultation::STATUS_ACTIVE]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $busy->id, 'status' => Consultation::STATUS_ONGOING]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $quiet->id, 'status' => Consultation::STATUS_NEW]);
        // signed off — closed work is not "load"
        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $quiet->id,
            'status' => Consultation::STATUS_SIGNED_OFF, 'signoff_date' => now()->toDateString()]);
        // another specialty — outside the scope
        $this->consult(['owning_specialty_id' => $nephro->id, 'consultant_id' => $other->id, 'status' => Consultation::STATUS_ACTIVE]);
        // unassigned — no consultant, so no row on the load list
        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => null, 'status' => Consultation::STATUS_ACTIVE]);

        $this->assertNotNull($doc->id);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('perConsultant', fn ($rows) => count($rows) === 2
                    && $rows[0]['name'] === 'Dr Busy' && $rows[0]['c'] === 2
                    && $rows[1]['name'] === 'Dr Quiet' && $rows[1]['c'] === 1));
    }

    public function test_soft_deleted_rows_are_excluded_from_trend_indications_and_load(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $cons = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Load']);
        ConsultationReason::create(['name' => 'Chest pain']);          // id 1

        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $cons->id,
            'status' => Consultation::STATUS_ACTIVE, 'requested_at' => now(), 'indication' => [1]]);
        $gone = $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $cons->id,
            'status' => Consultation::STATUS_ACTIVE, 'requested_at' => now(), 'indication' => [1]]);
        $gone->delete();

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('trend.data', fn ($d) => array_sum($d) === 1)
                ->where('topIndications.0.value', 1)
                ->where('perConsultant.0.c', 1));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationDashboardVolumeTest
```

Expected failure — none of the three props exist yet:

```
FAILED  Tests\Feature\ConsultationDashboardVolumeTest > volume trend buckets six months by coalesced request date
Inertia property [trend.labels] does not exist.
```

- [ ] **Step 3: Write the implementation**

Replace `laravel/app/Http/Controllers/ConsultationDashboardController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MetricQueries;
use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * W4 — the PHYSICIAN-scoped consultation dashboard.
 *
 * Statistics, Registry and Reports are admin-only by design (PHI exposure control) and stay that
 * way. This controller is the clinical alternative: it lives in the ordinary auth group and is
 * scoped by Consultation::scopeVisibleTo($user) — a consultant sees their own specialty's book,
 * an admin or a coordinator sees everything and may narrow to one specialty with ?specialty_id=N.
 *
 * Observers are refused FIRST, before any capability flag is consulted (the J1-9 global read-only
 * guarantee — a capability flag must never open a door the role closes).
 *
 * Every query goes through baseQuery(), which pins the three invariants in one place:
 *   1. scopeVisibleTo — the authorization scope, never re-implemented per metric;
 *   2. an EXPLICIT whereNull('consultations.deleted_at') — soft-deleted rows are excluded from
 *      every analytic in this app (see Concerns\MetricQueries). The SoftDeletes global scope
 *      already adds this; it is repeated deliberately so the invariant is visible at the call
 *      site and survives a future switch to a raw DB::table() builder;
 *   3. the optional specialty narrowing, which only a picker can set.
 */
class ConsultationDashboardController extends Controller
{
    use MetricQueries;

    /** Months of history on the volume trend + the indication tally window. */
    private const TREND_MONTHS = 6;

    /** null = no narrowing (the viewer's full visible scope). */
    private ?int $scopeSpecialtyId = null;

    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user->isObserver()) {
            throw new AccessDeniedHttpException('Observers have read-only access.');
        }

        $data = $request->validate([
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
        ]);

        // Coordinators and admins choose a specialty; everyone else is pinned by scopeVisibleTo,
        // so their ?specialty_id is ignored rather than honoured (it must never widen a scope).
        $canPick = $user->isAdmin() || $user->canCoordinateConsultations();
        $this->scopeSpecialtyId = $canPick && isset($data['specialty_id']) ? (int) $data['specialty_id'] : null;

        $from = Carbon::today()->startOfMonth()->subMonthsNoOverflow(self::TREND_MONTHS - 1);
        $to = Carbon::today()->endOfMonth()->endOfDay();

        return Inertia::render('Consultations/Dashboard', [
            'canPick' => $canPick,
            'filters' => ['specialty_id' => $this->scopeSpecialtyId],
            'specialties' => $canPick ? Specialty::orderBy('name')->get(['id', 'name']) : [],
            'scopeLabel' => $this->scopeLabel($user, $canPick),
            'openCounts' => $this->openCounts(),
            'ageing' => $this->ageing(),
            'today' => $this->todayCompleteness(now()->toDateString()),
            'turnaround' => $this->turnaround(),
            'trend' => $this->volumeTrend($from, $to),
            'topIndications' => $this->topIndications($from, $to),
            'perConsultant' => $this->perConsultantLoad(),
            'generatedAt' => now()->format('H:i'),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** The ONE scoped query builder — see the class docblock for the three invariants it pins. */
    private function baseQuery(): Builder
    {
        return Consultation::query()
            ->visibleTo($this->currentUser())
            ->whereNull('consultations.deleted_at')
            ->when($this->scopeSpecialtyId !== null,
                fn (Builder $q) => $q->where('consultations.owning_specialty_id', $this->scopeSpecialtyId));
    }

    /** The date a consult is COUNTED under: real request time, else the historical date-only column. */
    private function volumeDateExpr(): string
    {
        return 'COALESCE(consultations.requested_at, consultations.consultation_date)';
    }

    private function scopeLabel(User $user, bool $canPick): string
    {
        if ($this->scopeSpecialtyId !== null) {
            return (string) (Specialty::whereKey($this->scopeSpecialtyId)->value('name') ?? 'Unknown specialty');
        }
        if ($canPick) {
            return 'All specialties';
        }

        return (string) (Specialty::whereKey($user->specialty_id)->value('name') ?? 'Unassigned');
    }

    /**
     * Open counts by status:
     *   SELECT status, COUNT(*) FROM consultations
     *   WHERE deleted_at IS NULL AND <scope> AND status <> 'signed_off'
     *   GROUP BY status
     * Signed-off rows are never an "open" count, so the three keys always sum to `total`.
     */
    private function openCounts(): array
    {
        $rows = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->selectRaw('consultations.status k, COUNT(*) c')
            ->groupBy('k')->pluck('c', 'k')->all();

        $new = (int) ($rows[Consultation::STATUS_NEW] ?? 0);
        $active = (int) ($rows[Consultation::STATUS_ACTIVE] ?? 0);
        $ongoing = (int) ($rows[Consultation::STATUS_ONGOING] ?? 0);

        return ['new' => $new, 'active' => $active, 'ongoing' => $ongoing,
            'total' => $new + $active + $ongoing];
    }

    /**
     * Ageing of OPEN consults, in whole days:
     *   DATEDIFF(CURDATE(), DATE(COALESCE(requested_at, consultation_date)))
     * `requested_at` is authoritative from cutover onward; `consultation_date` is the historical
     * fallback (all 1,283 legacy rows have requested_at NULL — see the design §4.4). A row with
     * NEITHER date is reported as `unknown` rather than silently bucketed: this dashboard never
     * invents a date it does not have.
     */
    private function ageing(): array
    {
        $age = 'DATEDIFF(CURDATE(), DATE(' . $this->volumeDateExpr() . '))';
        $rows = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->selectRaw("CASE
                    WHEN " . $this->volumeDateExpr() . " IS NULL THEN 'unknown'
                    WHEN {$age} <= 2 THEN 'b0_2'
                    WHEN {$age} <= 7 THEN 'b3_7'
                    ELSE 'b8_plus'
                END k, COUNT(*) c")
            ->groupBy('k')->pluck('c', 'k')->all();

        return [
            'b0_2' => (int) ($rows['b0_2'] ?? 0),
            'b3_7' => (int) ($rows['b3_7'] ?? 0),
            'b8_plus' => (int) ($rows['b8_plus'] ?? 0),
            'unknown' => (int) ($rows['unknown'] ?? 0),
        ];
    }

    /**
     * Today's follow-up completeness — "seen X of Y".
     *
     *   Y (due)  = COUNT(*) over the scope WHERE status = 'active'
     *              ('active' is the only status that asserts a DAILY follow-up obligation;
     *               'ongoing' is deliberately on-the-books-without-a-daily-commitment, and
     *               'new' has not been picked up yet — design §2 / §4.4.)
     *   X (seen) = the same set, restricted with EXISTS (
     *                  SELECT 1 FROM consultation_followups f
     *                  WHERE f.consultation_id = consultations.id AND f.followup_date = CURDATE())
     *
     * EXISTS rather than a JOIN so a (hypothetically) duplicated tick could never double-count;
     * W2's unique([consultation_id, followup_date]) already makes that impossible, and this keeps
     * the count exact even if that constraint were ever relaxed. Both figures come back as plain
     * integers — the ratio is formatted in the UI, so an empty worklist is "0 of 0", never a
     * division by zero.
     */
    private function todayCompleteness(string $today): array
    {
        $due = (int) $this->baseQuery()
            ->where('consultations.status', Consultation::STATUS_ACTIVE)
            ->count();

        $seen = (int) $this->baseQuery()
            ->where('consultations.status', Consultation::STATUS_ACTIVE)
            ->whereExists(function ($q) use ($today) {
                $q->select(DB::raw(1))
                    ->from('consultation_followups')
                    ->whereColumn('consultation_followups.consultation_id', 'consultations.id')
                    ->where('consultation_followups.followup_date', $today);
            })
            ->count();

        return ['due' => $due, 'seen' => $seen];
    }

    /**
     * The two turnaround medians — CUTOVER ONWARD ONLY.
     *
     * Median time to first follow-up:
     *   SELECT TIMESTAMPDIFF(MINUTE, c.requested_at, MIN(f.created_at))
     *   FROM consultations c JOIN consultation_followups f ON f.consultation_id = c.id
     *   WHERE c.deleted_at IS NULL AND <scope> AND c.requested_at IS NOT NULL
     *   GROUP BY c.id, c.requested_at HAVING mins >= 0
     *
     * Median time to sign-off:
     *   SELECT TIMESTAMPDIFF(MINUTE, requested_at, signed_off_at)
     *   FROM consultations
     *   WHERE deleted_at IS NULL AND <scope>
     *     AND requested_at IS NOT NULL AND signed_off_at IS NOT NULL
     *     AND signed_off_at >= requested_at
     *
     * `requested_at IS NOT NULL` is the load-bearing predicate. EVERY historical row has it NULL
     * by deliberate design (§4.4: fabricating a time from a date-only legacy column would
     * manufacture precision that never existed), so both samples cover cutover onward only. The
     * payload therefore ships `legacy_excluded` — the number of in-scope rows that carry no
     * request timestamp — and `from_cutover`, so the UI can SAY so instead of quietly presenting
     * a mixed number. `signed_off_at >= requested_at` / `HAVING mins >= 0` drop clock-skew or
     * hand-corrected rows rather than letting a negative duration drag the median.
     *
     * The median (not the mean) because a single 3-week outlier consult would otherwise swamp a
     * ward's real turnaround; medians are also what the design table asks for.
     */
    private function turnaround(): array
    {
        $firstFollowup = $this->baseQuery()
            ->join('consultation_followups as f', 'f.consultation_id', '=', 'consultations.id')
            ->whereNotNull('consultations.requested_at')
            ->groupBy('consultations.id', 'consultations.requested_at')
            ->havingRaw('mins >= 0')
            ->selectRaw('TIMESTAMPDIFF(MINUTE, consultations.requested_at, MIN(f.created_at)) mins')
            ->pluck('mins')->map(fn ($m) => (float) $m)->all();

        $signoff = $this->baseQuery()
            ->whereNotNull('consultations.requested_at')
            ->whereNotNull('consultations.signed_off_at')
            ->whereColumn('consultations.signed_off_at', '>=', 'consultations.requested_at')
            ->selectRaw('TIMESTAMPDIFF(MINUTE, consultations.requested_at, consultations.signed_off_at) mins')
            ->pluck('mins')->map(fn ($m) => (float) $m)->all();

        $legacyExcluded = (int) $this->baseQuery()
            ->whereNull('consultations.requested_at')
            ->count();

        return [
            'first_followup_hours' => $this->medianHours($firstFollowup),
            'first_followup_n' => count($firstFollowup),
            'signoff_hours' => $this->medianHours($signoff),
            'signoff_n' => count($signoff),
            'legacy_excluded' => $legacyExcluded,
            'from_cutover' => true,
        ];
    }

    /**
     * Median of a minute list, returned in hours to one decimal — or NULL when the sample is
     * empty. NULL is a deliberate payload value: the UI renders an explicit "not enough data yet"
     * rather than a 0.0 that would read as "instant turnaround".
     *
     * @param  array<int, float>  $minutes
     */
    private function medianHours(array $minutes): ?float
    {
        $v = array_values($minutes);
        sort($v);
        $n = count($v);
        if ($n === 0) {
            return null;
        }
        $mid = intdiv($n, 2);
        $median = $n % 2 === 1 ? $v[$mid] : ($v[$mid - 1] + $v[$mid]) / 2;

        return round($median / 60, 1);
    }

    /**
     * Volume trend — monthly counts over the last six calendar months:
     *   SELECT DATE_FORMAT(COALESCE(requested_at, consultation_date), '%Y-%m') k, COUNT(*) c
     *   FROM consultations
     *   WHERE deleted_at IS NULL AND <scope>
     *     AND COALESCE(requested_at, consultation_date) BETWEEN <from> AND <to>
     *   GROUP BY k
     *
     * VOLUME is not a precision claim — unlike the medians, historical rows legitimately count
     * here, keyed off their date-only column. The bucket list comes from Concerns\MetricQueries
     * (buckets/keyExpr), the same helpers Statistics and Reports use, so an empty month renders
     * as a real 0 rather than disappearing from the axis.
     */
    private function volumeTrend(Carbon $from, Carbon $to): array
    {
        $expr = $this->volumeDateExpr();
        $counts = $this->baseQuery()
            ->whereRaw("{$expr} BETWEEN ? AND ?", [$from->toDateTimeString(), $to->toDateTimeString()])
            ->selectRaw($this->keyExpr($expr, 'month') . ' k, COUNT(*) c')
            ->groupBy('k')->pluck('c', 'k')->all();

        $buckets = $this->buckets($from, $to, 'month');

        return [
            'labels' => array_column($buckets, 'label'),
            'data' => array_map(fn (array $b) => (int) ($counts[$b['key']] ?? 0), $buckets),
        ];
    }

    /**
     * Top indications over the same six-month window. `indication` is a JSON ARRAY of
     * consultation_reason ids, so the tally is done in PHP over a chunked cursor — exactly the
     * approach StatisticsController::index already uses ("decode JSON indication in PHP — small
     * set"), rather than a JSON_CONTAINS query per reason.
     *
     * Legacy rows store the ids as JSON STRINGS (["1","3"]) while app-written rows store INTs
     * (Round-5 J1-2). Casting to int before the name lookup makes both shapes tally to the same
     * reason instead of silently dropping the legacy half.
     */
    private function topIndications(Carbon $from, Carbon $to): array
    {
        $reasonNames = ConsultationReason::pluck('name', 'id');
        $expr = $this->volumeDateExpr();
        $tally = [];

        $this->baseQuery()
            ->whereRaw("{$expr} BETWEEN ? AND ?", [$from->toDateTimeString(), $to->toDateTimeString()])
            ->select(['consultations.id', 'consultations.indication'])
            ->orderBy('consultations.id')
            ->chunk(500, function ($chunk) use (&$tally, $reasonNames) {
                foreach ($chunk as $row) {
                    foreach ((array) ($row->indication ?? []) as $id) {
                        $name = $reasonNames[(int) $id] ?? null;
                        if ($name) {
                            $tally[$name] = ($tally[$name] ?? 0) + 1;
                        }
                    }
                }
            });

        arsort($tally);
        $tally = array_slice($tally, 0, 8, true);

        $out = [];
        foreach ($tally as $label => $value) {
            $out[] = ['label' => $label, 'value' => (int) $value];
        }

        return $out;
    }

    /**
     * Per-consultant load within the scope — OPEN consults only:
     *   SELECT consultant_id, COUNT(*) FROM consultations
     *   WHERE deleted_at IS NULL AND <scope> AND status <> 'signed_off' AND consultant_id IS NOT NULL
     *   GROUP BY consultant_id
     *
     * Names are resolved in a second query with withTrashed(): users are soft-deleted, and a
     * historical consult must still resolve the consultant who owns it rather than showing a bare
     * id (the same reasoning as User::consultantOptions' $activeOnly=false callers). Rows with no
     * consultant are simply absent — "unassigned" is not a person's load.
     */
    private function perConsultantLoad(): array
    {
        $counts = $this->baseQuery()
            ->where('consultations.status', '<>', Consultation::STATUS_SIGNED_OFF)
            ->whereNotNull('consultations.consultant_id')
            ->selectRaw('consultations.consultant_id cid, COUNT(*) c')
            ->groupBy('cid')->pluck('c', 'cid')->all();

        if ($counts === []) {
            return [];
        }

        $names = User::withTrashed()->whereIn('id', array_keys($counts))
            ->get(['id', 'full_name', 'name'])
            ->mapWithKeys(fn (User $u) => [(int) $u->id => ($u->full_name ?: $u->name)]);

        $out = [];
        foreach ($counts as $id => $c) {
            $out[] = ['id' => (int) $id, 'name' => (string) ($names[(int) $id] ?? 'Unknown'), 'c' => (int) $c];
        }
        usort($out, fn (array $a, array $b) => $b['c'] <=> $a['c'] ?: strcmp($a['name'], $b['name']));

        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --filter=ConsultationDashboard
```

Expected (all four W4 backend files, plus the pre-existing consultation authorization suites are re-run in Task 29's full pass):

```
PASS  Tests\Feature\ConsultationDashboardScopeTest        (6 passed)
PASS  Tests\Feature\ConsultationDashboardFollowupTest     (4 passed)
PASS  Tests\Feature\ConsultationDashboardTurnaroundTest   (4 passed)
PASS  Tests\Feature\ConsultationDashboardVolumeTest       (4 passed)

Tests:  18 passed
```

- [ ] **Step 5: Commit**

```bash
cd laravel && git add app/Http/Controllers/ConsultationDashboardController.php tests/Feature/ConsultationDashboardVolumeTest.php && git commit -m "feat(consultations): volume trend, top indications and per-consultant load (W4)

Six monthly buckets keyed on COALESCE(requested_at, consultation_date) via the shared
MetricQueries buckets/keyExpr helpers, so an empty month is a real zero. Indications are
tallied in PHP over the JSON array exactly as StatisticsController does, casting ids so
legacy string-typed rows tally with app-written int rows. Per-consultant load counts open
consults only and resolves names withTrashed so a soft-deleted consultant still shows.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 29: The dashboard page, nav entry, and the rebuilt bundle

**Files:**
- Create: `laravel/resources/js/Pages/Consultations/Dashboard.vue`
- Modify: `laravel/resources/js/Layouts/AppLayout.vue`
- Modify: `laravel/public/build` (rebuilt bundle — committed, never hand-edited)
- Test: `laravel/resources/js/__tests__/ConsultationsDashboard.test.js`

- [ ] **Step 1: Write the failing test**

Create `laravel/resources/js/__tests__/ConsultationsDashboard.test.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// The page navigates with router.visit and reads no shared page props beyond auth; usePage is
// stubbed so the component mounts outside an Inertia app.
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
// the chart-theme composable reads CSS custom properties — stub to inert refs.
vi.mock('@/composables/useChartTheme', () => ({
    useChartTheme: () => ({
        gridColor: { value: '#000' }, axisColor: { value: '#000' }, strokeColor: { value: '#000' }, inkColor: { value: '#000' },
        series: { value: { primary: '#009ca6', accent: '#d9a23c', deep: '#00565e', info: '#2f7fe0', muted: '#5b6a6e', primarySoft: '#38b4ba' } },
    }),
}));

import ConsultationsDashboard from '@/Pages/Consultations/Dashboard.vue';
import { router } from '@inertiajs/vue3';

const baseProps = (over = {}) => ({
    canPick: false,
    filters: { specialty_id: null },
    specialties: [],
    scopeLabel: 'Cardiology',
    openCounts: { new: 1, active: 4, ongoing: 2, total: 7 },
    ageing: { b0_2: 3, b3_7: 2, b8_plus: 1, unknown: 1 },
    today: { due: 4, seen: 3 },
    turnaround: { first_followup_hours: 2.5, first_followup_n: 12, signoff_hours: 30.0, signoff_n: 9, legacy_excluded: 1283, from_cutover: true },
    trend: { labels: ['Mar 26', 'Apr 26', 'May 26', 'Jun 26', 'Jul 26', 'Aug 26'], data: [3, 5, 2, 8, 4, 6] },
    topIndications: [{ label: 'Chest pain', value: 9 }, { label: 'Arrhythmia', value: 4 }],
    perConsultant: [{ id: 7, name: 'Dr Busy', c: 5 }, { id: 8, name: 'Dr Quiet', c: 2 }],
    generatedAt: '09:41',
    ...over,
});

const mountAs = (user, over = {}) => {
    authUser = user;
    return shallowMount(ConsultationsDashboard, {
        props: baseProps(over),
        global: { renderStubDefaultSlot: true, stubs: { apexchart: true, teleport: true } },
    });
};

describe('Consultations dashboard — scope header + picker', () => {
    it('shows the scope label and hides the specialty picker for a plain consultant', () => {
        const w = mountAs({ role: 3, is_admin: false });
        expect(w.text()).toContain('Cardiology');
        expect(w.find('select[data-testid="specialty-picker"]').exists()).toBe(false);
    });

    it('renders the specialty picker for a picker (admin / coordinator) and navigates on change', async () => {
        router.visit.mockClear();
        const w = mountAs({ role: 0, is_admin: true }, {
            canPick: true,
            scopeLabel: 'All specialties',
            specialties: [{ id: 2, name: 'Cardiology' }, { id: 3, name: 'Nephrology' }],
        });
        const picker = w.find('select[data-testid="specialty-picker"]');
        expect(picker.exists()).toBe(true);
        // "All specialties" + the two options
        expect(picker.findAll('option')).toHaveLength(3);

        await picker.setValue('3');
        expect(router.visit).toHaveBeenCalledWith('/consultations/dashboard', { data: { specialty_id: '3' } });
    });
});

describe('Consultations dashboard — honesty of the turnaround block', () => {
    it('renders the visible "from cutover" note naming the excluded historical count', () => {
        const note = mountAs({ role: 3, is_admin: false }).find('[data-testid="cutover-note"]');
        expect(note.exists()).toBe(true);
        expect(note.text()).toContain('from cutover');
        expect(note.text()).toContain('1283');
        // the note must be VISIBLE, not screen-reader-only — the number is the caveat
        expect(note.classes()).not.toContain('sr-only');
    });

    it('renders the note even when nothing was excluded, so the window is always stated', () => {
        const w = mountAs({ role: 3, is_admin: false }, {
            turnaround: { first_followup_hours: 1.0, first_followup_n: 2, signoff_hours: 4.0, signoff_n: 2, legacy_excluded: 0, from_cutover: true },
        });
        expect(w.find('[data-testid="cutover-note"]').text()).toContain('from cutover');
    });

    it('shows a "not enough data yet" placeholder instead of a zero when a median is null', () => {
        const w = mountAs({ role: 3, is_admin: false }, {
            turnaround: { first_followup_hours: null, first_followup_n: 0, signoff_hours: null, signoff_n: 0, legacy_excluded: 1283, from_cutover: true },
        });
        const values = w.findAll('[data-testid="turnaround-value"]').map((n) => n.text());
        expect(values).toEqual(['Not enough data yet', 'Not enough data yet']);
        expect(values.join(' ')).not.toContain('0.0');
    });

    it('formats a real median in hours', () => {
        const values = mountAs({ role: 3, is_admin: false })
            .findAll('[data-testid="turnaround-value"]').map((n) => n.text());
        expect(values[0]).toBe('2.5 h');
        expect(values[1]).toBe('30 h');
    });
});

describe('Consultations dashboard — worklist + load', () => {
    it('states today\'s completeness as "seen X of Y"', () => {
        expect(mountAs({ role: 3, is_admin: false }).find('[data-testid="today-completeness"]').text())
            .toContain('3 of 4');
    });

    it('reads 0 of 0 without a NaN when nothing is on the daily worklist', () => {
        const w = mountAs({ role: 3, is_admin: false }, { today: { due: 0, seen: 0 } });
        const text = w.find('[data-testid="today-completeness"]').text();
        expect(text).toContain('0 of 0');
        expect(text).not.toContain('NaN');
    });

    it('lists per-consultant load rows and drills into the workspace filtered to that consultant', async () => {
        router.visit.mockClear();
        const w = mountAs({ role: 3, is_admin: false });
        const rows = w.findAll('[data-testid="load-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Dr Busy');

        await rows[0].trigger('click');
        expect(router.visit).toHaveBeenCalledWith('/consultations', { data: { consultant_id: 7 } });
    });

    it('renders one row per top indication', () => {
        expect(mountAs({ role: 3, is_admin: false }).findAll('[data-testid="indication-row"]')).toHaveLength(2);
    });

    it('renders a calm empty note instead of a chart when the trend has no data', () => {
        const w = mountAs({ role: 3, is_admin: false }, { trend: { labels: [], data: [] } });
        expect(w.text()).toContain('No data for this period.');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd laravel && npx vitest run resources/js/__tests__/ConsultationsDashboard.test.js
```

Expected failure — the page component does not exist:

```
FAIL  resources/js/__tests__/ConsultationsDashboard.test.js
Error: Failed to resolve import "@/Pages/Consultations/Dashboard.vue"
```

- [ ] **Step 3: Write the implementation**

Create `laravel/resources/js/Pages/Consultations/Dashboard.vue`:

```vue
<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ChartFigure from '@/Components/ChartFigure.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { useReducedMotion, chartAnimations } from '@/composables/useReducedMotion';

// theme-aware chart colours (grid/axis read CSS tokens) — never local hex.
const { gridColor, axisColor, series } = useChartTheme();
const { reduced } = useReducedMotion();

const props = defineProps({
    canPick: Boolean,
    filters: Object,
    specialties: Array,
    scopeLabel: String,
    openCounts: Object,
    ageing: Object,
    today: Object,
    turnaround: Object,
    trend: Object,
    topIndications: Array,
    perConsultant: Array,
    generatedAt: String,
});

// Specialty narrowing is a non-PII filter, so it rides the query string (the PHI-free-URL rule
// only forbids patient names and MRNs there).
const pickSpecialty = (event) => {
    const value = event.target.value;
    router.visit('/consultations/dashboard', value ? { data: { specialty_id: value } } : {});
};

// Drill-through: every figure lands on the workspace list that produced it.
const openWorkspace = (data) => router.visit('/consultations', data ? { data } : {});

const statusCards = computed(() => [
    { key: 'new', label: 'New / not seen', value: props.openCounts.new, tint: 'bg-tint-info/30', status: 'new' },
    { key: 'active', label: 'Active (daily F/U)', value: props.openCounts.active, tint: 'bg-brand-50', status: 'active' },
    { key: 'ongoing', label: 'Ongoing (no daily F/U)', value: props.openCounts.ongoing, tint: 'bg-ink-50', status: 'ongoing' },
]);

// Ageing is measured from requested_at, falling back to the historical date-only column; a consult
// with neither date is reported separately rather than being placed in a bucket it did not earn.
const ageingRows = computed(() => [
    ['0–2 days', props.ageing.b0_2],
    ['3–7 days', props.ageing.b3_7],
    ['Over 7 days', props.ageing.b8_plus],
    ['Date unknown', props.ageing.unknown],
]);
const hasAgeing = computed(() => ageingRows.value.some(([, v]) => v > 0));
const ageingOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit', animations: chartAnimations(reduced) },
    colors: [series.value.primary],
    plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: false },
    grid: { borderColor: gridColor.value, strokeDashArray: 4 },
    xaxis: { categories: ageingRows.value.map(([label]) => label), labels: { style: { colors: axisColor.value } }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { style: { colors: axisColor.value } } },
    legend: { show: false },
}));
const ageingSeries = computed(() => [{ name: 'Open consults', data: ageingRows.value.map(([, v]) => v) }]);

const hasTrend = computed(() => (props.trend.labels || []).length > 0
    && (props.trend.data || []).some((v) => v > 0));
const trendOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit', animations: chartAnimations(reduced) },
    colors: [series.value.deep],
    plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: false },
    grid: { borderColor: gridColor.value, strokeDashArray: 4 },
    xaxis: { categories: props.trend.labels, labels: { style: { colors: axisColor.value } }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { style: { colors: axisColor.value } } },
    legend: { show: false },
}));
const trendSeries = computed(() => [{ name: 'Consultations', data: props.trend.data }]);
const trendRows = computed(() => (props.trend.labels || []).map((m, i) => [m, props.trend.data[i] ?? 0]));

// A NULL median means "no qualifying rows yet" — rendering 0.0 would read as instant turnaround.
const hours = (value) => (value === null || value === undefined
    ? 'Not enough data yet'
    : `${Number.isInteger(value) ? value : value.toFixed(1)} h`);
const turnaroundCards = computed(() => [
    { key: 'first', label: 'Median time to first follow-up', value: props.turnaround.first_followup_hours, n: props.turnaround.first_followup_n },
    { key: 'signoff', label: 'Median time to sign-off', value: props.turnaround.signoff_hours, n: props.turnaround.signoff_n },
]);

const completenessPct = computed(() => (props.today.due > 0
    ? Math.round((props.today.seen / props.today.due) * 100) : 0));

const loadMax = computed(() => Math.max(1, ...(props.perConsultant || []).map((r) => r.c)));
</script>

<template>
    <AppLayout title="Consultation Dashboard">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="font-display text-xl font-bold text-ink-800">Consultation dashboard</h1>
                <p class="text-sm text-ink-500">{{ scopeLabel }} · updated {{ generatedAt }}</p>
            </div>
            <div class="flex items-center gap-3">
                <select v-if="canPick" data-testid="specialty-picker"
                        aria-label="Specialty"
                        class="rounded-xl border border-line bg-card px-3 py-2 text-sm font-semibold text-ink-600 shadow-sm"
                        :value="filters.specialty_id ?? ''" @change="pickSpecialty">
                    <option value="">All specialties</option>
                    <option v-for="s in specialties" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <button type="button" @click="openWorkspace(null)"
                        class="rounded-xl border border-line bg-card px-3 py-2 text-sm font-semibold text-ink-600 shadow-sm transition hover:border-brand-300 hover:text-brand-700">
                    Open workspace →
                </button>
            </div>
        </div>

        <!-- open counts by status -->
        <h2 class="sr-only">Open consultations by status</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <button v-for="c in statusCards" :key="c.key" type="button"
                    :data-testid="`status-card-${c.key}`"
                    class="rounded-2xl p-5 text-start ring-1 ring-line transition hover:ring-brand-300"
                    :class="c.tint" @click="openWorkspace({ status: c.status })">
                <p class="font-display nums text-3xl font-extrabold text-ink-900">{{ c.value }}</p>
                <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-ink-500">{{ c.label }}</p>
            </button>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
            <!-- today's follow-up completeness -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Today's follow-ups</h2>
                <p data-testid="today-completeness" class="font-display nums text-3xl font-extrabold text-ink-900">
                    Seen {{ today.seen }} of {{ today.due }}
                </p>
                <p class="mt-1 text-xs text-ink-500">Active consults only — ongoing consults carry no daily follow-up.</p>
                <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-ink-50"
                     role="img" :aria-label="`${completenessPct} percent of today's active consults have been seen`">
                    <div class="h-full rounded-full bg-brand-500" :style="{ width: completenessPct + '%' }"></div>
                </div>
            </div>

            <!-- turnaround medians + the cutover caveat -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Turnaround</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="t in turnaroundCards" :key="t.key">
                        <p data-testid="turnaround-value" class="font-display nums text-2xl font-extrabold text-ink-900">{{ hours(t.value) }}</p>
                        <p class="mt-1 text-xs text-ink-500">{{ t.label }}</p>
                        <p class="nums text-xs text-ink-400">n = {{ t.n }}</p>
                    </div>
                </div>
                <!-- The caveat is VISIBLE, always, and names the excluded count: historical rows have
                     no request timestamp at all, and averaging a fabricated one in would be a lie. -->
                <p data-testid="cutover-note" class="mt-3 rounded-xl bg-tint-info/30 px-3 py-2 text-xs font-semibold text-on-info">
                    Measured from cutover onward. {{ turnaround.legacy_excluded }} historical consultations have no recorded request time and are excluded.
                </p>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
            <!-- ageing buckets -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Ageing <span class="font-normal text-ink-400">(open consults)</span></h2>
                <ChartFigure title="Ageing of open consultations"
                             caption="Open consultations grouped by how long they have been open, measured from the request time or, for historical rows, the consultation date."
                             :columns="['Age', 'Open consults']" :rows="ageingRows">
                    <apexchart v-if="hasAgeing" type="bar" height="260" :options="ageingOptions" :series="ageingSeries" aria-label="Bar chart: open consultations by age" />
                    <p v-else class="grid h-[260px] place-items-center text-sm text-ink-400">No data for this period.</p>
                </ChartFigure>
            </div>

            <!-- six-month volume -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Volume <span class="font-normal text-ink-400">(last 6 months)</span></h2>
                <ChartFigure title="Consultation volume"
                             caption="Consultations received per month over the last six months."
                             :columns="['Month', 'Consultations']" :rows="trendRows">
                    <apexchart v-if="hasTrend" type="bar" height="260" :options="trendOptions" :series="trendSeries" aria-label="Bar chart: consultations per month over the last six months" />
                    <p v-else class="grid h-[260px] place-items-center text-sm text-ink-400">No data for this period.</p>
                </ChartFigure>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
            <!-- top indications -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-4 font-semibold text-ink-700">Top indications <span class="font-normal text-ink-400">(last 6 months)</span></h2>
                <div class="space-y-2">
                    <div v-for="(row, i) in topIndications" :key="row.label" data-testid="indication-row" class="flex items-center gap-3">
                        <div class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">{{ i + 1 }}</div>
                        <div class="min-w-0 flex-1 truncate text-sm text-ink-700">{{ row.label }}</div>
                        <div class="nums text-sm font-bold text-brand-700">{{ row.value }}</div>
                    </div>
                    <p v-if="!topIndications.length" class="text-sm text-ink-400">No indications recorded in this period.</p>
                </div>
            </div>

            <!-- per-consultant load -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-4 font-semibold text-ink-700">Open load by consultant</h2>
                <div class="space-y-3">
                    <button v-for="row in perConsultant" :key="row.id" type="button" data-testid="load-row"
                            class="flex w-full items-center gap-3 rounded-lg px-1 py-0.5 text-start transition hover:bg-brand-50/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            :aria-label="`${row.name}, ${row.c} open consultations — open the workspace filtered to this consultant`"
                            @click="openWorkspace({ consultant_id: row.id })">
                        <div class="w-40 shrink-0 truncate text-sm font-medium text-ink-600">{{ row.name }}</div>
                        <div class="relative h-2.5 flex-1 overflow-hidden rounded-full bg-ink-50">
                            <div class="h-full rounded-full bg-brand-500" :style="{ width: (row.c / loadMax * 100) + '%' }"></div>
                        </div>
                        <div class="nums w-8 text-end text-sm font-bold text-ink-800">{{ row.c }}</div>
                    </button>
                    <p v-if="!perConsultant.length" class="text-sm text-ink-400">No open consultations assigned.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
```

Modify `laravel/resources/js/Layouts/AppLayout.vue` — add the dashboard entry to the `Clinical` bucket of `clinicalNavSections`, right after the existing Consultations row. Replace this block:

```js
            { label: 'Consultations', href: '/consultations', icon: 'chat', can: !observer },
        ] },
```

with:

```js
            { label: 'Consultations', href: '/consultations', icon: 'chat', can: !observer },
            // W4 — the physician-scoped consultation dashboard. Same visibility rule as the
            // workspace itself (clinical roles, never Observer); the route enforces it server-side.
            { label: 'Consultation Dashboard', href: '/consultations/dashboard', icon: 'grid', can: !observer },
        ] },
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd laravel && npx vitest run resources/js/__tests__/ConsultationsDashboard.test.js
```

Expected:

```
✓ resources/js/__tests__/ConsultationsDashboard.test.js (11 tests)
  ✓ Consultations dashboard — scope header + picker (2)
  ✓ Consultations dashboard — honesty of the turnaround block (4)
  ✓ Consultations dashboard — worklist + load (5)

Test Files  1 passed (1)
Tests  11 passed (11)
```

Then the full frontend suite, the mandatory build chain (source under `resources/` changed, and `public/build` is committed), and the full backend suite so the ~37 existing consultation authorization test methods are proven untouched:

```bash
cd laravel && npx vitest run
cd laravel && npx vite build
cd laravel && node scripts/check-source-allowlist.mjs --write
cd laravel && node scripts/check-source-allowlist.mjs
cd laravel && node scripts/contrast.mjs
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test
```

Expected, in order: the whole Vitest suite green; `vite build` writing a fresh `public/build/manifest.json` and assets; `check-source-allowlist.mjs --write` refreshing the snapshot; the second allowlist run printing `PASS`; `contrast.mjs` printing `PASS`; and the PHPUnit run green with the four new `ConsultationDashboard*` files and every pre-existing consultation authorization test still passing.

- [ ] **Step 5: Commit**

```bash
cd laravel && git add resources/js/Pages/Consultations/Dashboard.vue resources/js/Layouts/AppLayout.vue resources/js/__tests__/ConsultationsDashboard.test.js scripts/source-allowlist.json public/build && git commit -m "feat(consultations): physician consultation dashboard page + nav entry (W4)

Status cards, ageing chart, today's 'seen X of Y' worklist meter, the two turnaround medians
and the six-month volume/indication/load panels, all drilling through to the workspace. The
turnaround block carries a VISIBLE 'from cutover' note naming the number of historical
consultations excluded, and renders 'Not enough data yet' instead of a 0.0 when a median has
no qualifying rows. Charts follow the Dashboard.vue idiom (ChartFigure + theme-token series +
reduced-motion gate); nav entry hidden from Observers, who the route refuses server-side.
Rebuilt public/build; allowlist snapshot refreshed; contrast PASS.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

> **Note on the allowlist snapshot path:** `node scripts/check-source-allowlist.mjs --write` rewrites the snapshot file the checker reads. If `git status` shows a different snapshot filename than `scripts/source-allowlist.json`, stage the file the `--write` run actually modified — `git add -u scripts/` picks it up either way — and re-run `node scripts/check-source-allowlist.mjs` to confirm it still prints `PASS` before committing.
## Cross-wave risks — read before starting, not after

These came out of reading the real code while drafting. They are not hypothetical.

**1. Skipping the truncate is not enough on its own.** `legacy:import` re-seeds `patients` and `users` ids.
A consultation row that merely *survives* the import keeps ids that now belong to **different people** — a
consult silently attached to the wrong patient is worse than a deleted one, because it looks correct. Task 1
captures the natural keys (MRN, `users.legacy_id`) before the rebuild and re-points afterwards; **Task 10a**
extends that to `owning_specialty_id`, `admission_id` and `signed_off_by`. Neither task is optional.

**2. The safety flag can erase itself.** `LegacyImport::importSettings()` rebuilds the settings row from the
legacy dump, which has no `consultations_source_of_truth` column. Without the carry-through added in Task 1,
the flag resets to `false` on the first import and **the second import destroys the whole ledger.** This is the
single most dangerous detail in the plan.

**3. External specialties change id across a rebuild.** `specialties` is truncated and re-inserted; internal
specialties keep their legacy id, but external services are inserted by name and take fresh auto-increment ids.
Anything storing a specialty id must be re-resolved **by name** (Task 10a).

**4. `LegacyImportTest` deliberately escapes `RefreshDatabase`.** TRUNCATE forces an implicit COMMIT, so that
class manages and repairs its own state in `tearDown()`. Add import tests **to that class** — do not build a
parallel harness.

**5. `Setting::current()` is memoised per request via `once()`.** The import command must read the flag with a
direct `DB::table('settings')` query, not through the model, or it can act on a stale in-memory row.

**6. One deliberate payload rename.** Task 2 renames the dashboard key `consultDonut.signed24h` →
`consultDonut.signedTodayOrYesterday` and updates all consumers, including the pinned assertion in
`tests/Feature/Round5J2Test.php`. That assertion is **updated, not deleted**. W4 must not reintroduce a "24h"
label — `signoff_date` is a DATE column, so no rolling-24h consultation metric is computable until W1's
`signed_off_at` exists and has accumulated post-cutover data.

---

## Final verification gate

Run the whole suite — not just the filtered tests — before calling the work done.

```bash
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test
cd laravel && npx vitest run
cd laravel && npx vite build && node scripts/check-source-allowlist.mjs --write && node scripts/check-source-allowlist.mjs && node scripts/contrast.mjs
```

| Gate | Baseline before this plan | Requirement |
|---|---|---|
| PHPUnit | 763 passing | **≥ 763**, zero failures |
| Vitest | 591 passing | **≥ 591**, zero failures |
| `check-source-allowlist.mjs` | PASS | PASS |
| `contrast.mjs` | PASS | PASS |

A test that was passing before and fails now is a **regression** — stop and investigate; never delete it to go
green. The ~37 existing consultation authorization test methods must all still pass, except where a task
**deliberately** updates one and says so.

---

## Cutover — the operational step this plan does not perform

Shipping the code does **not** flip the switch. After W0–W2 are deployed and the subspecialty teams are
actually entering consultations in this system:

1. Confirm the teams have stopped creating consultations on the legacy site.
2. Turn on **Control → Settings → "Consultations: this system is the source of truth"** (Task 10b).
3. Verify with a dry run on a **restored backup**, never on production, that `legacy:import` now reports
   consultations preserved and re-linked.

Only after step 2 is a consultation entered here safe from the next data reload. Deployment itself follows the
existing runbook in `DEPLOY-LARAVEL.md`; back up the database before running migrations.

---

## Execution

Plan complete. Two execution options:

1. **Subagent-Driven (recommended)** — a fresh subagent per task, with a spec-compliance review then a
   code-quality review between tasks. Fast iteration, and each task stays in a clean context.
2. **Inline Execution** — execute in this session with batched checkpoints for review.

W0 must complete and be verified before any task in W1 begins.
