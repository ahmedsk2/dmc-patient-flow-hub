# Transfer-driven Handover + Notification/List UX — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make "needs a handover" mean *a transfer left one outstanding* (which resets today's backlog with no data changes), fix the notification dropdown, add a non-destructive Clear, hide zero-patient consultants, and add a searchable select for long option lists.

**Architecture:** An indexed `notifications.admission_id` column turns the handover reminder into a cheap correlation, letting `Admission::handoverPending()` replace the old "no note today" scope that matched every patient. The bell panel becomes a capped flex column with one scroll container. A self-adapting `SearchableSelect.vue` replaces long `<select>`s while short enumerations stay native.

**Tech Stack:** Laravel 13, Inertia 2, Vue 3 `<script setup>`, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-07-18-transfer-driven-handover-and-ux-design.md`

---

## Environment (read before Task 1)

- Work from `laravel/`. PHP is `C:/wamp64/bin/php/php8.5.0/php.exe` (not on PATH) — always pass `-d xdebug.mode=off`. **No Composer.**
- One test file: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/HandoverTest.php`
- Full **two-pass**: `... artisan test --exclude-group pdf` then `... artisan test --group pdf`
- Vitest: `cd laravel && npx vitest run <path>`
- `public/build` is **committed**. After ANY `resources/` change: `npx vite build` → `node scripts/check-source-allowlist.mjs --write` → `node scripts/check-source-allowlist.mjs` (PASS) → `node scripts/contrast.mjs` (PASS).
- **Line numbers below WILL have drifted — re-read each file and match on the quoted code.**
- Reuse existing AA-safe tokens only (`bg-tint-warning`/`text-on-warning`, `bg-brand-100`/`text-brand-700`, `bg-brand-solid`/`hover:bg-brand-solid-hover`, `text-on-danger`). Invent no colour utilities.
- Test fixtures: there are **no Admission/Patient factories**. Mirror the `User::create` + `Patient::create` + `Admission::create` pattern in `tests/Feature/HandoverTest.php` (users need `mfa_secret` via `Totp::secret()`, `mfa_enrolled_at`, `email_verified_at`; login is by the `username` column; `password` has a `hashed` cast so set it **plain**). `HandoverTest`'s `user()` helper defaults to ROLE_CONSULTANT — pass an explicit role where it matters.

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `database/migrations/2026_07_18_000000_add_admission_id_to_notifications_table.php` | indexed FK-less column + backfill | 1 |
| `app/Http/Controllers/PatientActionController.php` | write `admission_id`; dedupe by column | 2 |
| `app/Http/Controllers/HandoverController.php` | resolve by column; feed query | 2, 7 |
| `app/Models/Admission.php` | `scopeHandoverPending` replaces `scopeNeedsHandoverToday` | 3 |
| `app/Http/Controllers/{Dashboard,Patients,Handover}Controller.php` | 5 call sites renamed | 3 |
| `tests/Feature/ReassignReminderTest.php` | receiver-gets-no-alarm regression | 4 |
| `app/Http/Controllers/PatientsController.php` | drop zero-census fill | 5 |
| `app/Http/Controllers/DashboardController.php` | `consultantBoard` requires a patient | 5 |
| `resources/js/Layouts/AppLayout.vue` | panel scroll fix + Clear | 6, 7 |
| `resources/js/Components/SearchableSelect.vue` | **new** searchable picker | 8 |
| (long-list call sites) | swap `<select>` → `SearchableSelect` | 9 |

---

## Task 1: `notifications.admission_id` column + backfill

**Files:** Create `database/migrations/2026_07_18_000000_add_admission_id_to_notifications_table.php`; Test `tests/Feature/HandoverCheckpointsTest.php`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/HandoverCheckpointsTest.php` (reuse its existing fixture helpers):

```php
    public function test_notifications_table_has_an_indexed_admission_id_column(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('notifications', 'admission_id'));

        $u = \App\Models\User::factory()->create();
        $n = \App\Models\Notification::create([
            'user_id' => $u->id, 'type' => 'handover.incomplete', 'created_at' => now(),
            'admission_id' => 4242, 'payload' => ['admission_id' => 4242],
        ]);
        $this->assertSame(4242, (int) \App\Models\Notification::find($n->id)->admission_id);
    }
```
> If `User::factory()` is unavailable here, mirror the `User::create` fixture the neighbouring tests in this file use.

- [ ] **Step 2: Run → FAIL** (column missing)

Run: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/HandoverCheckpointsTest.php`

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `handover.incomplete` reminders are correlated to admissions on every board/dashboard load
     * (Admission::handoverPending()). Doing that through the JSON payload is unindexable — a full
     * scan of a table that grows with every notification. This indexed column makes it a plain
     * lookup, and removes the `(string)`-cast fragility the JSON comparison required.
     *
     * Deliberately NO foreign key: notifications outlive the admissions they reference, and the
     * reminder trail is retained for clinical audit (docs/HANDOVER-COMPLIANCE.md).
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $t) {
            $t->unsignedBigInteger('admission_id')->nullable()->after('type')->index();
        });

        // one-off backfill so reminders raised before this migration keep resolving
        DB::statement("UPDATE notifications
            SET admission_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.admission_id')) AS UNSIGNED)
            WHERE admission_id IS NULL AND JSON_EXTRACT(payload, '$.admission_id') IS NOT NULL");
    }

    public function down(): void
    {
        // MySQL drops the column's index with the column
        Schema::table('notifications', fn (Blueprint $t) => $t->dropColumn('admission_id'));
    }
};
```

- [ ] **Step 4: Run → PASS.** Same command as Step 2.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_18_000000_add_admission_id_to_notifications_table.php tests/Feature/HandoverCheckpointsTest.php
git commit -m "feat(notifications): indexed admission_id column + backfill from payload"
```

---

## Task 2: Writers set the column; resolve + dedupe use it

**Files:** Modify `app/Http/Controllers/PatientActionController.php`, `app/Http/Controllers/HandoverController.php`; Test `tests/Feature/ReassignReminderTest.php`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ReassignReminderTest.php` (reuse its `reassignFixture()`):

```php
    public function test_reminders_are_written_with_the_admission_id_column_and_resolve_by_it(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();

        $this->actingAs($admin)->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id, 'admission_ids' => [$admission->id],
        ])->assertRedirect();

        // every reminder carries the column, not just the JSON payload
        $this->assertSame(2, \App\Models\Notification::where('type', 'handover.incomplete')
            ->where('admission_id', $admission->id)->whereNull('resolved_at')->count());

        // saving the note resolves them THROUGH the column
        $this->actingAs($to)->postJson("/admissions/{$admission->id}/handover", ['body' => 'done'])->assertOk();
        $this->assertSame(0, \App\Models\Notification::where('type', 'handover.incomplete')
            ->where('admission_id', $admission->id)->whereNull('resolved_at')->count());
    }
```

- [ ] **Step 2: Run → FAIL** (`admission_id` is null on the new rows)

- [ ] **Step 3: Implement — writer**

In `PatientActionController::raiseIncompleteHandoverReminders`, add the column to the create and switch the duplicate check to it. READ the method first; the two edits are:

```php
            $existing = Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
                ->where('admission_id', $a->id)
                ->whereIn('user_id', $recipients)->pluck('user_id')->all();
```

```php
                Notification::create(['user_id' => $uid, 'type' => 'handover.incomplete', 'created_at' => now(),
                    'admission_id' => $a->id, 'payload' => [
                    'admission_id' => $a->id, 'patient_name' => $a->patient?->name, 'mrn' => $a->patient?->mrn,
                    'from_name' => $fromName, 'to_name' => $toName,
                ]]);
```
**Keep `admission_id` inside the payload too** — the audit trail and pre-migration rows depend on it. Delete the now-obsolete `(string)` cast comment above the dedupe query and replace it with: `// correlate on the indexed column (was a JSON payload compare needing a (string) cast)`.

- [ ] **Step 4: Implement — resolver**

In `HandoverController::save`, change the resolve query:

```php
        // Resolve any persistent "incomplete handover" reminders for this admission (all recipients).
        // Keyed on the indexed admission_id column — the previous JSON payload compare required a
        // (string) cast to match at all, which was a real bug once.
        Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('admission_id', $admission->id)->update(['resolved_at' => now()]);
```

- [ ] **Step 5: Run → PASS**

Run: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/ReassignReminderTest.php tests/Feature/HandoverTest.php`

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PatientActionController.php app/Http/Controllers/HandoverController.php tests/Feature/ReassignReminderTest.php
git commit -m "feat(handover): write + resolve reminders via the indexed admission_id column"
```

---

## Task 3: `handoverPending` replaces `needsHandoverToday`

**Files:** Modify `app/Models/Admission.php`, `app/Http/Controllers/{Dashboard,Patients,Handover}Controller.php`; Test `tests/Feature/HandoverTest.php`.

**This is the backlog reset.** With no reminders raised, every surface reads zero — no data is written or deleted.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/HandoverTest.php` (reuse `$this->user()`, `$this->admission()`, `$this->freshHandover()`):

```php
    public function test_handover_pending_is_zero_when_no_transfer_has_raised_a_reminder(): void
    {
        $me = $this->user();
        $this->admission(['consultant_id' => $me->id]);   // active, NO handover note at all
        $this->admission(['consultant_id' => $me->id]);

        // the OLD definition would have counted both; transfer-driven counts none
        $this->assertSame(0, \App\Models\Admission::handoverPending()->count());

        $this->actingAs($me)->get('/patients')->assertOk()
            ->assertInertia(fn ($p) => $p->where('needsHandoverCount', 0));
    }

    public function test_handover_pending_counts_only_admissions_with_an_unresolved_reminder(): void
    {
        $me = $this->user();
        $flagged = $this->admission(['consultant_id' => $me->id]);
        $this->admission(['consultant_id' => $me->id]);   // untouched → not pending

        \App\Models\Notification::create(['user_id' => $me->id, 'type' => 'handover.incomplete',
            'created_at' => now(), 'admission_id' => $flagged->id, 'payload' => ['admission_id' => $flagged->id]]);

        $ids = \App\Models\Admission::handoverPending()->pluck('id')->all();
        $this->assertSame([$flagged->id], $ids);
    }

    public function test_resolving_the_reminder_clears_the_admission_from_pending(): void
    {
        $me = $this->user();
        $a = $this->admission(['consultant_id' => $me->id]);
        \App\Models\Notification::create(['user_id' => $me->id, 'type' => 'handover.incomplete',
            'created_at' => now(), 'admission_id' => $a->id, 'payload' => ['admission_id' => $a->id]]);
        $this->assertSame(1, \App\Models\Admission::handoverPending()->count());

        \App\Models\Notification::where('admission_id', $a->id)->update(['resolved_at' => now()]);
        $this->assertSame(0, \App\Models\Admission::handoverPending()->count());
    }

    public function test_a_discharged_admission_is_never_pending(): void
    {
        $me = $this->user();
        $a = $this->admission(['consultant_id' => $me->id]);
        \App\Models\Notification::create(['user_id' => $me->id, 'type' => 'handover.incomplete',
            'created_at' => now(), 'admission_id' => $a->id, 'payload' => ['admission_id' => $a->id]]);
        $a->update(['discharge_date' => now()->toDateString()]);

        $this->assertSame(0, \App\Models\Admission::handoverPending()->count());
    }
```

- [ ] **Step 2: Run → FAIL** (`handoverPending` does not exist)

- [ ] **Step 3: Replace the scope**

In `app/Models/Admission.php`, replace `scopeNeedsHandoverToday` entirely with:

```php
    /**
     * Handover PENDING (transfer-driven): an ACTIVE admission carrying at least one UNRESOLVED
     * `handover.incomplete` reminder — i.e. a transfer moved this patient and the note was never
     * written. Held by ANY recipient (initiator or outgoing consultant): the ADMISSION is the unit
     * of "pending", not the person.
     *
     * This deliberately replaced "no note saved today", which matched every active patient every
     * morning and drowned the alerts. The board card's amber handover icon still shows daily
     * currency — it is informational and no longer raises an alarm.
     */
    public function scopeHandoverPending(Builder $q): Builder
    {
        return $q->active()->whereExists(fn ($s) => $s->selectRaw('1')->from('notifications')
            ->whereColumn('notifications.admission_id', 'admissions.id')
            ->where('notifications.type', 'handover.incomplete')
            ->whereNull('notifications.resolved_at'));
    }
```

- [ ] **Step 4: Update the five call sites**

Rename `needsHandoverToday()` → `handoverPending()` at each. **Do not change the per-viewer scoping** (`User::seesOwnPatientsOnly()`) at any of them:
- `DashboardController` — `'handoverDue' => (int) Admission::handoverPending()->where('consultant_id', $myId)->count(),`
- `DashboardController` — `$handoverDueUnit = (int) Admission::handoverPending()->count();`
- `HandoverController::index` — `'needsHandover' => Admission::handoverPending()->when(Auth::user()->seesOwnPatientsOnly(), …)`
- `PatientsController::index` — `'needsHandoverCount' => Admission::handoverPending()->when(…)->count(),`
- `PatientsController::boardGroups` — `->when($filters['needs_handover'] ?? null, fn ($q) => $q->handoverPending())`

Verify none remain: `grep -rn "needsHandoverToday" app/ tests/` must return nothing.

- [ ] **Step 5: Run → PASS**

Run: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/HandoverTest.php tests/Feature/ReassignReminderTest.php`
**Existing tests written against the OLD definition will fail** (e.g. one asserting a stale-note patient counts as needing a handover). Those assertions are now wrong by design — rename and flip them to the transfer-driven expectation. Convert coverage, never delete it. Report every test you changed.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Admission.php app/Http/Controllers/ tests/Feature/HandoverTest.php
git commit -m "feat(handover): needs-handover becomes transfer-driven (handoverPending scope)"
```

---

## Task 4: Receiving consultant never gets the alarm (regression only)

**Files:** Test `tests/Feature/ReassignReminderTest.php`. **No production code changes.**

- [ ] **Step 1: Write the test**

```php
    public function test_the_receiving_consultant_never_gets_the_pending_alarm(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();

        $this->actingAs($admin)->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id, 'admission_ids' => [$admission->id],
        ])->assertRedirect();

        // initiator + OUTGOING consultant are chased …
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'handover.incomplete']);
        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete']);
        // … the RECEIVER is not (they get the ordinary handover.transfer notice instead)
        $this->assertDatabaseMissing('notifications', ['user_id' => $to->id, 'type' => 'handover.incomplete']);
        $this->assertDatabaseHas('notifications', ['user_id' => $to->id, 'type' => 'handover.transfer']);
    }
```

- [ ] **Step 2: Run → PASS immediately** (this documents existing behaviour). If it FAILS, stop and report — that would mean the recipients are wrong.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ReassignReminderTest.php
git commit -m "test(handover): lock in that the receiving consultant gets no pending alarm"
```

---

## Task 5: Hide consultants with no patients

**Files:** Modify `app/Http/Controllers/PatientsController.php`, `app/Http/Controllers/DashboardController.php`; Test `tests/Feature/HandoverTest.php`.

- [ ] **Step 1: Write the failing tests**

```php
    public function test_a_consultant_with_no_patients_is_absent_from_the_board_and_active_list(): void
    {
        $busy = $this->user(['on_service' => 1]);
        $idle = $this->user(['on_service' => 1]);          // on service, ZERO patients
        $this->admission(['consultant_id' => $busy->id]);
        $viewer = $this->user(['role' => \App\Models\User::ROLE_ADMIN]);

        foreach (['/patients', '/active-list'] as $url) {
            $res = $this->actingAs($viewer)->get($url)->assertOk();
            $ids = collect(data_get($res->viewData('page')['props'], 'groups.*.id'))->all();
            $this->assertContains($busy->id, $ids, "$url should list a consultant with patients");
            $this->assertNotContains($idle->id, $ids, "$url must not list a zero-patient consultant");
        }
    }

    public function test_dashboard_consultant_table_excludes_zero_patient_consultants(): void
    {
        $busy = $this->user(['on_service' => 1]);
        $idle = $this->user(['on_service' => 1]);
        $this->admission(['consultant_id' => $busy->id]);
        $admin = $this->user(['role' => \App\Models\User::ROLE_ADMIN]);

        $res = $this->actingAs($admin)->get('/dashboard')->assertOk();
        $ids = collect(data_get($res->viewData('page')['props'], 'consultantBoard.*.id'))->all();
        $this->assertContains($busy->id, $ids);
        $this->assertNotContains($idle->id, $ids);
    }
```
> The prop paths (`groups.*.id`, `consultantBoard.*.id`) and the dashboard route (`/dashboard` vs `/`) are assumptions — **verify the real shapes** by reading `boardGroups()` and the `Inertia::render` call in `DashboardController`, and adjust. Keep the intent. If `$this->user()` does not accept an `on_service` override, extend the helper or set the attribute after creation. `DashboardController` caches a heavy tier — if the cache makes the test flaky, call `\App\Support\DashboardCache::bust()` in the test before the request.

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Board — delete the zero-census fill**

In `PatientsController::boardGroups`, DELETE the whole block that begins with the comment `// zero-census on-service consultants get an empty group on the UNFILTERED board only` and its `if (empty($filters['search']) && …) { … }` body (it queries `User::where('role', User::ROLE_CONSULTANT)->where('active', 1)->where('on_service', 1)` and inserts empty groups). Leave the ordering code that follows it untouched.

`PatientsController::activeList` calls the same `boardGroups()`, so it inherits the fix — confirm `resources/js/Pages/ActiveList.vue` does no separate roster merge of its own.

- [ ] **Step 4: Dashboard — require at least one active admission**

In `DashboardController`, the `$consultantBoard` query currently keeps a row when the user is an on-service consultant **or** has an active admission. Replace that whole `->where(fn ($w) => $w->where(fn ($w2) => $w2->where('u.on_service', 1)->where('u.role', \App\Models\User::ROLE_CONSULTANT))->orWhereExists(…))` clause with the existence requirement alone:

```php
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('admissions as ax')
                ->whereColumn('ax.consultant_id', 'u.id')->whereNull('ax.discharge_date')->whereNull('ax.deleted_at'))
```

Add above it: `// "Patient count per consultant" lists only consultants who actually hold patients — the legacy members-list behaviour (every on-service consultant, zeros included) was noise.`

`$perConsultant` (the top-8 load chart, an INNER join) needs **no** change.

- [ ] **Step 5: Run → PASS.** Then sweep for collateral: `... artisan test --filter="Patients|Dashboard|Board|ActiveList"`. Update any test that asserted a zero-patient consultant was present.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PatientsController.php app/Http/Controllers/DashboardController.php tests/
git commit -m "feat(board): hide consultants with no patients from board, active list and dashboard table"
```

---

## Task 6: Notification dropdown scrolls inside itself

**Files:** Modify `resources/js/Layouts/AppLayout.vue`; Test `resources/js/__tests__/AppLayout.notifications.test.js`.

- [ ] **Step 1: Write the failing test**

**Read the file first** — it already has a mount helper and a `fetch` mock for `/api/notifications`. Reuse both; supply a payload containing BOTH one `actionable` item and several ordinary feed items, then open the bell exactly as the neighbouring tests do.

```js
it('caps the panel height and scrolls both groups inside one container', async () => {
    const w = mountLayout();                       // <- this file's existing helper
    mockNotifications({                            // <- this file's existing fetch mock
        notifications: [ /* 3+ ordinary items */ ],
        actionable: [{ id: 91, type: 'handover.incomplete', payload: { admission_id: 7, patient_name: 'A' }, created_at: new Date().toISOString() }],
        unread: 4,
    });
    await w.vm.toggleBell();
    await nextTick();

    const panel = w.find('#notifications-panel');
    expect(panel.classes().join(' ')).toMatch(/max-h-\[70vh\]/);
    const scroller = panel.find('[data-notif-scroll]');
    expect(scroller.exists()).toBe(true);
    expect(scroller.classes()).toContain('overscroll-contain');
    expect(scroller.classes()).toContain('overflow-y-auto');
    // the pinned group and the feed both live INSIDE that one scroller
    expect(scroller.text()).toContain('Needs attention');
    expect(scroller.find('ul').exists()).toBe(true);
    // and the old inner cap is gone
    expect(panel.html()).not.toContain('max-h-80');
});
```

- [ ] **Step 2: Run → FAIL**

Run: `cd laravel && npx vitest run resources/js/__tests__/AppLayout.notifications.test.js`

- [ ] **Step 3: Restructure the panel**

Change the panel element's classes to make it a capped flex column:

```html
        <div v-if="bellOpen" id="notifications-panel" role="dialog" aria-label="Notifications" :ref="focusFirstBell" @keydown.esc="bellOpen = false"
             class="absolute end-0 top-11 z-50 flex max-h-[70vh] w-80 flex-col overflow-hidden rounded-2xl bg-card shadow-2xl ring-1 ring-line">
```

Keep the existing header row as the first child and add `shrink-0` to it so it never scrolls away. Then wrap **both** the pinned "Needs attention" group and the feed `<ul>` (and the empty state) in ONE scroll container:

```html
            <div data-notif-scroll class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                <!-- pinned actionable group (unchanged markup) … -->
                <!-- feed <ul> … -->
                <!-- empty state … -->
            </div>
```

Remove `max-h-80` and `overflow-auto` from the feed `<ul>` (it becomes just `class="divide-y divide-line"`) — the parent now owns scrolling.

`min-h-0` is required: without it a flex child refuses to shrink below its content and the container never scrolls. `overscroll-contain` stops the scroll chaining to the page once you hit the end — the reported bug.

- [ ] **Step 4: Run → PASS.** Then `npx vitest run` (full suite).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Layouts/AppLayout.vue resources/js/__tests__/AppLayout.notifications.test.js
git commit -m "fix(notifications): cap the bell panel and scroll both groups in one container"
```

---

## Task 7: "Clear notifications" (non-destructive)

**Files:** Modify `app/Http/Controllers/HandoverController.php`, `resources/js/Layouts/AppLayout.vue`; Test `tests/Feature/HandoverTest.php`, `resources/js/__tests__/AppLayout.notifications.test.js`.

- [ ] **Step 1: Write the failing tests**

PHP:
```php
    public function test_the_feed_lists_only_unread_ordinary_notifications(): void
    {
        $me = $this->user();
        $read = \App\Models\Notification::create(['user_id' => $me->id, 'type' => 'handover.transfer',
            'created_at' => now(), 'read_at' => now(), 'payload' => []]);
        $unread = \App\Models\Notification::create(['user_id' => $me->id, 'type' => 'handover.transfer',
            'created_at' => now(), 'payload' => []]);

        $ids = collect($this->actingAs($me)->getJson('/api/notifications')->json('notifications'))->pluck('id')->all();
        $this->assertContains($unread->id, $ids);
        $this->assertNotContains($read->id, $ids, 'a cleared (read) notification must leave the feed');
    }

    public function test_clearing_empties_the_feed_but_keeps_unresolved_handover_alarms(): void
    {
        $me = $this->user();
        \App\Models\Notification::create(['user_id' => $me->id, 'type' => 'handover.transfer', 'created_at' => now(), 'payload' => []]);
        \App\Models\Notification::create(['user_id' => $me->id, 'type' => 'handover.incomplete',
            'created_at' => now(), 'admission_id' => 7, 'payload' => ['admission_id' => 7]]);

        $this->actingAs($me)->post('/notifications/read-all')->assertRedirect();

        $body = $this->actingAs($me)->getJson('/api/notifications')->json();
        $this->assertCount(0, $body['notifications'], 'ordinary items are cleared');
        $this->assertCount(1, $body['actionable'], 'the handover alarm survives');
        // and nothing was deleted — the audit trail is intact
        $this->assertSame(2, \App\Models\Notification::where('user_id', $me->id)->count());
    }
```

Vitest — reuse this file's mount helper and fetch mock; capture the POSTs so both assertions are real:

```js
it('does NOT auto-clear when the bell is opened', async () => {
    const w = mountLayout();
    mockNotifications({ notifications: [{ id: 1, type: 'handover.transfer', payload: {}, created_at: new Date().toISOString() }], actionable: [], unread: 1 });
    await w.vm.toggleBell();
    await nextTick();
    // the old behaviour fired read-all on open; it must not any more
    expect(postedUrls()).not.toContain('/notifications/read-all');
    w.unmount();
});

it('Clear empties the feed but leaves the pinned alarms', async () => {
    const w = mountLayout();
    mockNotifications({
        notifications: [{ id: 1, type: 'handover.transfer', payload: {}, created_at: new Date().toISOString() }],
        actionable: [{ id: 91, type: 'handover.incomplete', payload: { admission_id: 7, patient_name: 'A' }, created_at: new Date().toISOString() }],
        unread: 2,
    });
    await w.vm.toggleBell();
    await nextTick();

    // after clearing, the server returns an empty ordinary feed but the alarm survives
    mockNotifications({ notifications: [], actionable: [{ id: 91, type: 'handover.incomplete', payload: { admission_id: 7, patient_name: 'A' }, created_at: new Date().toISOString() }], unread: 1 });
    await w.vm.clearNotifications();
    await nextTick();

    expect(postedUrls()).toContain('/notifications/read-all');
    expect(w.vm.feedNotifications).toHaveLength(0);
    expect(w.vm.actionable).toHaveLength(1);
    expect(w.text()).toContain('Needs attention');
    w.unmount();
});
```
> `postedUrls()` stands for whatever this spec already uses to observe fetch calls — reuse it rather than inventing a new mock. `clearNotifications` and `feedNotifications` must be reachable on `w.vm`; add them to the component's `defineExpose` if the file uses one.

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Feed query**

In `HandoverController::notifications()`, restrict the `notifications` list to unread ordinary items (leave `actionable` and `unread` exactly as they are):

```php
            'notifications' => Notification::where('user_id', $me)->whereNull('read_at')
                ->where('type', '!=', 'handover.incomplete')
                ->orderByDesc('id')->limit(15)->get(['id', 'type', 'payload', 'read_at', 'resolved_at', 'created_at']),
```
`readAll()` already updates only `whereNull('read_at')->where('type', '!=', 'handover.incomplete')` — it needs **no change** and becomes the Clear endpoint.

- [ ] **Step 4: Front end — explicit Clear, no auto-clear**

In `AppLayout.vue`, remove the auto read-all from `toggleBell` (delete the `if (d.unread > 0) { await fetch('/notifications/read-all', …) }` block **and** the `readOverride.value = true;` that followed it — with a read-filtered feed, auto-clearing would empty the list the instant it opened).

Add a Clear action:

```js
const clearing = ref(false);
/** Clear = mark ordinary notifications read. Handover alarms are excluded server-side and stay
 *  pinned until the note is saved. Nothing is deleted (the reminder trail is retained for audit). */
const clearNotifications = async () => {
    clearing.value = true;
    try {
        await fetch('/notifications/read-all', { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });
        readOverride.value = true;
        const d = await (await fetch('/api/notifications', { headers: { Accept: 'application/json' } })).json();
        notifications.value = d.notifications || [];
        actionable.value = d.actionable || [];
    } finally { clearing.value = false; }
};
```

In the panel header (the `shrink-0` row from Task 6), add the button beside the existing "Handover inbox →" control:

```html
                                <button v-if="feedNotifications.length" type="button" @click="clearNotifications" :disabled="clearing"
                                        class="text-xs font-semibold text-ink-500 transition hover:text-ink-700 disabled:opacity-50">{{ clearing ? 'Clearing…' : 'Clear' }}</button>
```

- [ ] **Step 5: Run → PASS** (both suites), then `npx vitest run`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HandoverController.php resources/js/Layouts/AppLayout.vue tests/ resources/js/__tests__/
git commit -m "feat(notifications): explicit Clear that keeps unresolved handover alarms"
```

---

## Task 8: `SearchableSelect.vue`

**Files:** Create `resources/js/Components/SearchableSelect.vue`, `resources/js/Components/__tests__/SearchableSelect.spec.js`.

- [ ] **Step 1: Write the failing test**

```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SearchableSelect from '@/Components/SearchableSelect.vue';

const many = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, name: `Dr. Person ${i + 1}` }));
const few = [{ id: 1, name: 'Alpha' }, { id: 2, name: 'Beta' }];

describe('SearchableSelect', () => {
    it('renders a plain native select at or below the threshold', () => {
        const w = mount(SearchableSelect, { props: { modelValue: '', options: few } });
        expect(w.find('select').exists()).toBe(true);
        expect(w.find('input[role="combobox"]').exists()).toBe(false);
        w.unmount();
    });

    it('renders a combobox above the threshold', () => {
        const w = mount(SearchableSelect, { props: { modelValue: '', options: many } });
        expect(w.find('input[role="combobox"]').exists()).toBe(true);
        w.unmount();
    });

    it('filters by case-insensitive SUBSTRING, not prefix', async () => {
        const opts = [...many, { id: 99, name: 'Khalid Alizadeh' }];
        const w = mount(SearchableSelect, { props: { modelValue: '', options: opts } });
        await w.find('input[role="combobox"]').trigger('focus');
        await w.find('input[role="combobox"]').setValue('ali');
        const items = w.findAll('[role="option"]').map((li) => li.text());
        expect(items).toContain('Khalid Alizadeh');       // matched mid-word
        expect(items.every((t) => t.toLowerCase().includes('ali'))).toBe(true);
        w.unmount();
    });

    it('emits the option id on selection', async () => {
        const w = mount(SearchableSelect, { props: { modelValue: '', options: many } });
        await w.find('input[role="combobox"]').trigger('focus');
        await w.findAll('[role="option"]')[2].trigger('mousedown');
        expect(w.emitted('update:modelValue').at(-1)[0]).toBe(3);
        w.unmount();
    });

    it('shows a no-matches hint when nothing matches', async () => {
        const w = mount(SearchableSelect, { props: { modelValue: '', options: many } });
        await w.find('input[role="combobox"]').trigger('focus');
        await w.find('input[role="combobox"]').setValue('zzzzz');
        expect(w.text()).toContain('No matches');
        w.unmount();
    });
});
```

- [ ] **Step 2: Run → FAIL**

Run: `cd laravel && npx vitest run resources/js/Components/__tests__/SearchableSelect.spec.js`

- [ ] **Step 3: Create the component**

```vue
<script setup>
import { computed, ref } from 'vue';
import { FIELD } from '@/lib/ui.js';

/**
 * SearchableSelect — one picker for option lists too long to scan.
 *
 * Self-adapting: at or below `searchableFrom` options it renders a plain native <select> (on a
 * phone the OS picker beats a filter box for a handful of items); above it, an accessible combobox
 * filtering by case-insensitive SUBSTRING — "ali" finds "Khalid Alizadeh" — not prefix or exact.
 *
 * CLIENT-side only: the arrays it filters (consultants, specialties) already ship with the page.
 * IcdTypeahead.vue stays the SERVER-backed picker for large reference data (~72k ICD-10 rows).
 * Keyboard + ARIA mirror IcdTypeahead so both pickers behave identically, including the
 * first-Esc-closes-the-dropdown-not-the-modal rule.
 */
const props = defineProps({
    modelValue: { type: [Number, String], default: '' },
    options: { type: Array, default: () => [] },     // [{ id, name }] — extra keys ignored
    placeholder: { type: String, default: 'Select…' },
    disabled: { type: Boolean, default: false },
    searchableFrom: { type: Number, default: 8 },
    inputClass: { type: String, default: FIELD },
});
const emit = defineEmits(['update:modelValue']);

const searchable = computed(() => props.options.length > props.searchableFrom);
const label = (o) => o?.name ?? '';
const selected = computed(() => props.options.find((o) => String(o.id) === String(props.modelValue)) || null);

const query = ref('');
const open = ref(false);
const hi = ref(-1);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? props.options.filter((o) => label(o).toLowerCase().includes(q)) : props.options;
});

const openList = () => { if (props.disabled) return; open.value = true; query.value = ''; hi.value = props.options.length ? 0 : -1; };
const close = () => { open.value = false; hi.value = -1; };
const choose = (o) => { emit('update:modelValue', o.id); close(); };
const onInput = (e) => { query.value = e.target.value; open.value = true; hi.value = filtered.value.length ? 0 : -1; };
const onKeydown = (e) => {
    if (e.key === 'ArrowDown' && !open.value) { e.preventDefault(); openList(); return; }
    if (!open.value || !filtered.value.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); hi.value = Math.min(hi.value + 1, filtered.value.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); hi.value = Math.max(hi.value - 1, 0); }
    else if (e.key === 'Enter') { e.preventDefault(); if (hi.value >= 0) choose(filtered.value[hi.value]); }
    else if (e.key === 'Escape') { e.stopPropagation(); close(); }   // first Esc: dropdown only
};
</script>

<template>
    <!-- short list: the native control is genuinely better (OS picker on mobile) -->
    <select v-if="!searchable" :value="modelValue" :disabled="disabled" :class="inputClass"
            @change="emit('update:modelValue', $event.target.value)">
        <option value="">{{ placeholder }}</option>
        <option v-for="o in options" :key="o.id" :value="o.id">{{ label(o) }}</option>
    </select>

    <div v-else class="relative">
        <input :value="open ? query : (selected ? label(selected) : '')" :class="inputClass" :placeholder="placeholder"
               :disabled="disabled" role="combobox" :aria-expanded="open" aria-autocomplete="list"
               @input="onInput" @focus="openList" @keydown="onKeydown" @blur="close" />
        <ul v-if="open && filtered.length" role="listbox"
            class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-line bg-card py-1 shadow-lg">
            <li v-for="(o, i) in filtered" :key="o.id" role="option" :aria-selected="i === hi"
                @mousedown.prevent="choose(o)" @mouseenter="hi = i"
                class="cursor-pointer px-3 py-1.5 text-sm" :class="i === hi ? 'bg-brand-50' : ''">{{ label(o) }}</li>
        </ul>
        <p v-else-if="open" class="absolute z-10 mt-1 w-full rounded-xl border border-line bg-card px-3 py-2 text-sm text-ink-400 shadow-lg">No matches</p>
    </div>
</template>
```

- [ ] **Step 4: Run → PASS**, then `npx vitest run`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/SearchableSelect.vue resources/js/Components/__tests__/SearchableSelect.spec.js
git commit -m "feat(ui): SearchableSelect — substring-filtered picker that stays native for short lists"
```

---

## Task 9: Use `SearchableSelect` for the long lists

**Files:** Modify `resources/js/Components/Patients/ActionModal.vue`, `resources/js/Components/Patients/ReassignModal.vue`, `resources/js/Components/PatientForm.vue`, and the consultant/specialty pickers on `resources/js/Pages/Registry/Index.vue` + `resources/js/Pages/Control/Index.vue`; Tests: the existing specs for those components.

- [ ] **Step 1: Convert the consultant pickers**

In each file, `import SearchableSelect from '@/Components/SearchableSelect.vue';` and replace the consultant `<select v-model="…">` with:

```html
                    <SearchableSelect v-model="aForm.consultant_id" :options="assignConsultants" placeholder="Select consultant…" />
```
Adjust the model + options per site:
- `ActionModal.vue` — assign mode: `v-model="aForm.consultant_id"`, `:options="assignConsultants"`. Specialty transfer: `v-model="tForm.consultant_id"`, `:options="specConsultants"`.
- `ReassignModal.vue` — `v-model="rForm.from_consultant_id"` `:options="consultants"`; `v-model="rForm.to_consultant_id"` `:options="onServiceConsultants"`.
- `PatientForm.vue` — the consultant picker, `:options="consultants"`.
- `Registry/Index.vue`, `Control/Index.vue` — the consultant and specialty pickers only.

**Preserve the "(off service)" suffix** where a site renders one today (ActionModal's assign list does). `SearchableSelect` renders `o.name` verbatim, so pre-compute the label rather than teaching the component about it:

```js
const assignConsultantsLabelled = computed(() =>
    assignConsultants.value.map((c) => ({ ...c, name: c.on_service ? c.name : `${c.name} (off service)` })));
```
and pass that. Do the same anywhere else a suffix is currently shown.

**Do NOT convert** short enumerations: gender, code status, ward/ICU location, discharge destination, transfer mode, outcome, and the Handover code-status select. They stay native `<select>`.

- [ ] **Step 2: Run the affected specs**

Run: `cd laravel && npx vitest run resources/js/Components/Patients/__tests__/ resources/js/Components/__tests__/`
Specs that drive a consultant choice via `w.find('select').setValue(…)` will now fail against a combobox. Update them to set the model through the exposed form (e.g. `w.vm.aForm.consultant_id = 2`) or to interact with `SearchableSelect` — convert the coverage, do not delete it. Report every spec you touched.

- [ ] **Step 3: Full suite + build + gates**

```
cd laravel && npx vitest run
cd laravel && npx vite build
node scripts/check-source-allowlist.mjs --write
node scripts/check-source-allowlist.mjs
node scripts/contrast.mjs
```
All green; allowlist and contrast PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js public/build scripts
git commit -m "feat(ui): searchable consultant/specialty pickers on the long lists"
```

---

## Task 10: Full verification + ship

- [ ] **Step 1:** `cd laravel && npx vite build` (if not current from Task 9)
- [ ] **Step 2:** `node scripts/check-source-allowlist.mjs` (PASS) + `node scripts/contrast.mjs` (PASS)
- [ ] **Step 3:** `npx vitest run` — all green
- [ ] **Step 4:** Two-pass PHPUnit, both green:

```
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --exclude-group pdf
cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test --group pdf
```

- [ ] **Step 5:** Commit any residual rebuilt assets, then push and watch CI to green (`gh run watch <id> --exit-status`).

---

## Self-review notes (for the executor)

- **Spec coverage:** §1→T1–T3 · §2→T4 · §5→T5 · §3→T6 · §4→T7 · §6→T8–T9 · testing→embedded + T10.
- **Only ONE schema change** (T1, additive + backfilled + reversible). If you find yourself writing a second migration, stop.
- **The backlog reset is a definition change, not a data change.** Never write, backdate or delete handover rows to make the count drop.
- **Never delete notification rows** — `docs/HANDOVER-COMPLIANCE.md` documents `notifications` as a retained audit trail. Clear only sets `read_at`.
- **Naming is consistent across tasks:** `handoverPending()` (T3, replacing `needsHandoverToday`), `notifications.admission_id` (T1–T2), `data-notif-scroll` (T6), `clearNotifications()` (T7), `SearchableSelect` props `modelValue`/`options`/`searchableFrom`/`inputClass` (T8–T9).
- **Per-viewer scoping (`User::seesOwnPatientsOnly()`) must not change** — this batch alters what counts as pending, not who sees what.
- Tasks 3, 5 and 9 will each break existing tests written against the old behaviour. That is expected: **rename and flip them, never delete the coverage**, and report each one.
