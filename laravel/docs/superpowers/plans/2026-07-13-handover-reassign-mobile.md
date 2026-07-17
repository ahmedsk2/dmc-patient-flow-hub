# Handover + Reassign + Mobile — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-patient handover checkpoints, soften the reassign hard-gate into an "allow + persistent reminder" flow, widen the Reassign modal, fix mobile horizontal overflow app-wide, and write a clinical-audit compliance summary.

**Architecture:** Additive JSON `checkpoints` on `handovers` + `handover_revisions`; a `resolved_at` column makes `handover.incomplete` notifications persist until a note is saved; `bulkReassign` stops throwing on stale handovers and instead notifies the reassigner + from-consultant; `BaseModal` gains a `wide` size; a global no-horizontal-scroll guard plus per-page fixes.

**Tech Stack:** Laravel 13, Inertia 2, Vue 3 `<script setup>`, Tailwind v4. PHP = `C:/wamp64/bin/php/php8.5.0/php.exe` (no Composer). PHPUnit two-pass (`--exclude-group pdf`, then `--group pdf`) isolated with `DB_DATABASE=dmc_test2`. Vitest via `npx vitest run`. `public/build` is committed — rebuild `npx vite build` after any `resources/` change, then `node scripts/check-source-allowlist.mjs --write` + `node scripts/contrast.mjs`.

**Spec:** `docs/superpowers/specs/2026-07-13-handover-reassign-mobile-design.md`

---

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `resources/js/Components/BaseModal.vue` | add `wide` size (~75%) | 1 |
| `resources/js/Components/Patients/ReassignModal.vue` | use `wide`; relax gate → warning | 1, 6 |
| `database/migrations/2026_07_13_010000_add_handover_checkpoints_and_notification_resolved_at.php` | `checkpoints` json ×2 + `resolved_at` | 2 |
| `app/Models/{Handover,HandoverRevision,Notification}.php` | casts | 2 |
| `app/Http/Controllers/HandoverController.php` | save/show checkpoints; resolve reminders | 3, 4, 7 |
| `resources/js/composables/useHandover.js` | `saveHandover(id, body, checkpoints)` | 4 |
| `resources/js/Components/Patients/HandoverModal.vue` | checkpoint editor + chips | 4 |
| `app/Http/Controllers/PatientActionController.php` | bulkReassign: no throw → notify | 5 |
| `app/Http/Middleware/HandleInertiaRequests.php` | resolved-aware unread count | 7 |
| `resources/js/Layouts/AppLayout.vue` | bell "Needs attention" group | 7 |
| `resources/css/app.css` | global no-x-scroll guard | 8 |
| (audited pages) | per-page overflow fixes | 8 |
| `docs/HANDOVER-COMPLIANCE.md` | compliance write-up | 9 |

Run PHPUnit like: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test tests/Feature/HandoverTest.php`. Each task commits.

---

## Task 1: BaseModal `wide` size + Reassign uses it

**Files:** Modify `resources/js/Components/BaseModal.vue`, `resources/js/Components/Patients/ReassignModal.vue`; Test `resources/js/Components/__tests__/BaseModal.spec.js`.

- [ ] **Step 1: Failing test** — add to `BaseModal.spec.js`:
```js
it('supports a wide size (~75%) for large forms', () => {
    const w = mount(BaseModal, { props: { open: true, size: 'wide' }, slots: { default: 'x' }, global: { stubs: { Teleport: true } } });
    expect(w.html()).toContain('max-w-4xl');
});
```
- [ ] **Step 2: Run → FAIL.** `cd laravel && npx vitest run resources/js/Components/__tests__/BaseModal.spec.js`
- [ ] **Step 3: Implement.** In `BaseModal.vue`: extend the `size` prop validator to include `'wide'`, and the `sizeClass` map. Final lines:
```js
    size: { type: String, default: 'md', validator: (v) => ['md', 'lg', 'xl', '2xl', 'wide'].includes(v) },
```
```js
const sizeClass = { md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-2xl', '2xl': 'max-w-2xl', wide: 'max-w-4xl' };
```
- [ ] **Step 4:** In `ReassignModal.vue` `<BaseModal ... size="md" ...>` → `size="wide"`.
- [ ] **Step 5: Run → PASS.** Also run `npx vitest run resources/js/Components/Patients/__tests__/ReassignModal.spec.js` (unchanged, still green).
- [ ] **Step 6: Commit** `feat(ui): BaseModal wide size (~75%) for the Reassign modal`.

---

## Task 2: Migration + model casts (checkpoints + resolved_at)

**Files:** Create `database/migrations/2026_07_13_010000_add_handover_checkpoints_and_notification_resolved_at.php`; Modify `app/Models/Handover.php`, `app/Models/HandoverRevision.php`, `app/Models/Notification.php`; Test `tests/Feature/HandoverCheckpointsTest.php`.

- [ ] **Step 1: Failing test** — create `tests/Feature/HandoverCheckpointsTest.php`:
```php
<?php
namespace Tests\Feature;

use App\Models\Handover;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoverCheckpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_handover_checkpoints_and_notification_resolved_at_round_trip(): void
    {
        $u = User::factory()->create();
        // uses an admission from the factory chain in existing tests; minimal admission:
        $admission = \App\Models\Admission::factory()->create();
        $h = Handover::create(['admission_id' => $admission->id, 'body' => 'x', 'updated_by' => $u->id,
            'checkpoints' => ['vte_completed' => true, 'code_status' => 'dnr']]);
        $this->assertSame(true, Handover::find($h->id)->checkpoints['vte_completed']);
        $this->assertSame('dnr', Handover::find($h->id)->checkpoints['code_status']);

        $n = Notification::create(['user_id' => $u->id, 'type' => 'handover.incomplete', 'created_at' => now()]);
        $this->assertNull($n->resolved_at);
        $n->update(['resolved_at' => now()]);
        $this->assertNotNull(Notification::find($n->id)->resolved_at);
    }
}
```
> If `Admission::factory()` / `User::factory()` aren't defined, mirror how `tests/Feature/HandoverTest.php` builds an admission + user and adapt.
- [ ] **Step 2: Run → FAIL** (columns missing).
- [ ] **Step 3: Migration:**
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handovers', fn (Blueprint $t) => $t->json('checkpoints')->nullable()->after('body'));
        Schema::table('handover_revisions', fn (Blueprint $t) => $t->json('checkpoints')->nullable()->after('body'));
        Schema::table('notifications', fn (Blueprint $t) => $t->timestamp('resolved_at')->nullable()->after('read_at'));
    }

    public function down(): void
    {
        Schema::table('handovers', fn (Blueprint $t) => $t->dropColumn('checkpoints'));
        Schema::table('handover_revisions', fn (Blueprint $t) => $t->dropColumn('checkpoints'));
        Schema::table('notifications', fn (Blueprint $t) => $t->dropColumn('resolved_at'));
    }
};
```
- [ ] **Step 4: Casts.**
  - `Handover.php`: add `protected $casts = ['checkpoints' => 'array'];`
  - `HandoverRevision.php`: add `protected $casts = ['checkpoints' => 'array'];`
  - `Notification.php`: change casts to `protected $casts = ['payload' => 'array', 'read_at' => 'datetime', 'resolved_at' => 'datetime', 'created_at' => 'datetime'];`
- [ ] **Step 5: Run → PASS.**
- [ ] **Step 6: Commit** `feat(handover): checkpoints json (handovers + revisions) + notifications.resolved_at`.

---

## Task 3: HandoverController::save persists + snapshots checkpoints

**Files:** Modify `app/Http/Controllers/HandoverController.php`; Test `tests/Feature/HandoverCheckpointsTest.php`.

- [ ] **Step 1: Failing tests** — add:
```php
    public function test_save_persists_checkpoints_on_handover_and_revision(): void
    {
        [$admin, $admission] = $this->adminAndAdmission();   // helper: see note below
        $this->actingAs($admin)->postJson("/admissions/{$admission->id}/handover", [
            'body' => 'today note',
            'checkpoints' => ['vte_completed' => true, 'ready_for_discharge' => false, 'high_risk' => true,
                'needs_workup' => false, 'workup_pending' => true, 'code_status' => 'full'],
        ])->assertOk();

        $h = \App\Models\Handover::where('admission_id', $admission->id)->first();
        $this->assertTrue($h->checkpoints['vte_completed']);
        $this->assertSame('full', $h->checkpoints['code_status']);
        $rev = \App\Models\HandoverRevision::where('admission_id', $admission->id)->latest('id')->first();
        $this->assertTrue($rev->checkpoints['high_risk']);   // snapshotted in history
    }

    public function test_save_rejects_a_bad_code_status(): void
    {
        [$admin, $admission] = $this->adminAndAdmission();
        $this->actingAs($admin)->postJson("/admissions/{$admission->id}/handover", [
            'body' => 'x', 'checkpoints' => ['code_status' => 'bogus'],
        ])->assertStatus(422);
    }
```
> Add a private `adminAndAdmission()` helper to this test class that creates an admin User (mirror `tests/Feature/WaveBTest.php` admin() shape — mfa_secret + mfa_enrolled_at + email_verified_at) plus an active `Admission` owned by that admin (so `canManageAdmission` passes). Reuse `HandoverTest.php`'s setup if it already builds these.
- [ ] **Step 2: Run → FAIL** (checkpoints ignored).
- [ ] **Step 3: Implement** — in `HandoverController::save`, extend validation + persistence. Replace the validate + transaction block with:
```php
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'checkpoints' => ['nullable', 'array'],
            'checkpoints.vte_completed' => ['boolean'],
            'checkpoints.ready_for_discharge' => ['boolean'],
            'checkpoints.high_risk' => ['boolean'],
            'checkpoints.needs_workup' => ['boolean'],
            'checkpoints.workup_pending' => ['boolean'],
            'checkpoints.code_status' => ['nullable', 'in:full,dnr,dni'],
        ]);

        DB::transaction(function () use ($admission, $data, $request) {
            $h = Handover::firstOrNew(['admission_id' => $admission->id]);
            // checkpoints: replace with the submitted set when present, else keep the current ones
            $cp = $request->has('checkpoints') ? $this->normalizeCheckpoints($data['checkpoints'] ?? []) : $h->checkpoints;
            $h->body = $data['body'];
            $h->checkpoints = $cp;
            $h->updated_by = Auth::id();
            $h->updated_at = now();
            $h->save();
            HandoverRevision::create(['admission_id' => $admission->id, 'body' => $data['body'], 'checkpoints' => $cp, 'author_id' => Auth::id()]);
        });
```
And add a private helper on the controller:
```php
    /** Coerce the six checkpoint fields to a canonical shape (booleans + a nullable code_status enum). */
    private function normalizeCheckpoints(array $c): array
    {
        return [
            'vte_completed' => (bool) ($c['vte_completed'] ?? false),
            'ready_for_discharge' => (bool) ($c['ready_for_discharge'] ?? false),
            'high_risk' => (bool) ($c['high_risk'] ?? false),
            'needs_workup' => (bool) ($c['needs_workup'] ?? false),
            'workup_pending' => (bool) ($c['workup_pending'] ?? false),
            'code_status' => in_array($c['code_status'] ?? null, ['full', 'dnr', 'dni'], true) ? $c['code_status'] : null,
        ];
    }
```
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** `feat(handover): save + validate + snapshot checkpoints`.

---

## Task 4: show() returns checkpoints; useHandover + HandoverModal editor + chips

**Files:** Modify `app/Http/Controllers/HandoverController.php` (show), `resources/js/composables/useHandover.js`, `resources/js/Components/Patients/HandoverModal.vue`; Test `resources/js/Components/Patients/__tests__/HandoverModal.spec.js`, `resources/js/__tests__/useHandover.test.js`.

- [ ] **Step 1: show() returns checkpoints.** In `HandoverController::show`, add to the JSON: `'checkpoints' => $h?->checkpoints,` (top level) and in the revisions map add `'checkpoints' => $r->checkpoints,`.
- [ ] **Step 2: useHandover test + change.** In `useHandover.test.js` add a case asserting `saveHandover` posts the checkpoints in the body. Then change `saveHandover`:
```js
    async function saveHandover(admissionId, body, checkpoints = undefined) {
        saving.value = true;
        try {
            const res = await fetch(`/admissions/${admissionId}/handover`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify(checkpoints === undefined ? { body } : { body, checkpoints }),
            });
            return res.ok;
        } finally { saving.value = false; }
    }
```
- [ ] **Step 3: HandoverModal editor + chips (failing Vitest first).** Add to `HandoverModal.spec.js`:
```js
it('shows checkpoint chips for set flags in the read view', async () => {
    // mock fetchHandover to return checkpoints; mount open with a patient; assert chips render
    // (mirror the existing HandoverModal.spec mocking of useHandover.fetchHandover)
    // expect(w.text()).toContain('VTE'); expect(w.text()).toContain('DNR');
});
it('edits checkpoints and posts them on Save', async () => {
    // enter edit mode, toggle high_risk + set code_status, click Save,
    // assert hForm.post payload includes checkpoints
});
```
Then implement in `HandoverModal.vue`:
  - `hForm` becomes `useForm({ body: '', checkpoints: { vte_completed: false, ready_for_discharge: false, high_risk: false, needs_workup: false, workup_pending: false, code_status: null } })`.
  - On fetch success, set `hForm.checkpoints` from `d.checkpoints` (fallback to the default object) alongside `hForm.body`.
  - In the **edit** template, above the textarea: a row of 5 checkboxes (`v-model="hForm.checkpoints.vte_completed"` …) + a `<select v-model="hForm.checkpoints.code_status">` with options None/Full/DNR/DNI (values `null`/`full`/`dnr`/`dni`). Labels: "VTE prophylaxis", "Ready for discharge", "High-risk", "Needs more workup", "Workup pending", "Code status".
  - In the **read** template, above the body, a chip row: for each set flag render a small chip using AA-safe tokens — e.g. `bg-tint-warning text-on-warning` for High-risk, `bg-tint-danger text-on-danger` for a DNR/DNI code status, `bg-brand-100 text-brand-700` for VTE/Ready/Workup — labels "VTE", "D/C ready", "High-risk", "Needs workup", "Workup pending", and the code status ("Full"/"DNR"/"DNI"). Only render chips whose flag is true / code_status set.
  - `submitHandover` already posts `hForm` → checkpoints ride along automatically (Inertia serializes the whole form).
- [ ] **Step 4: Run the two specs → PASS.**
- [ ] **Step 5: Commit** `feat(handover): checkpoint editor + read-view chips; show() + useHandover carry checkpoints`.

---

## Task 5: bulkReassign — allow the move, notify instead of throwing

**Files:** Modify `app/Http/Controllers/PatientActionController.php`; Test `tests/Feature/HandoverTest.php` (or a new `ReassignReminderTest.php`).

**Context:** `bulkReassign` currently computes `$freshIds` (handovers updated today) and THROWS `ValidationException(['handover' => 'Handover must be updated today before transfer.'])` (≈ lines 308–314). Remove the throw; keep computing the stale set; after the move, notify.

- [ ] **Step 1: Failing tests** — create `tests/Feature/ReassignReminderTest.php`:
```php
<?php
namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReassignReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reassign_proceeds_with_a_stale_handover_and_notifies_both_parties(): void
    {
        // Build: an admin (actor), a from-consultant, an on-service to-consultant, and an active
        // admission owned by the from-consultant WITH NO same-day handover. (Mirror how
        // tests/Feature/HandoverTest.php + WaveBTest.php build consultants/admissions.)
        [$admin, $from, $to, $admission] = $this->reassignFixture();

        $this->actingAs($admin)->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id,
            'admission_ids' => [$admission->id],
        ])->assertRedirect();   // NO 422 — the move is allowed now

        $this->assertSame($to->id, (int) $admission->fresh()->consultant_id);   // moved
        // a persistent reminder to BOTH the actor and the from-consultant
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
    }

    public function test_no_reminder_when_the_handover_is_current(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture(withTodayHandover: true);
        $this->actingAs($admin)->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id, 'admission_ids' => [$admission->id],
        ])->assertRedirect();
        $this->assertDatabaseMissing('notifications', ['type' => 'handover.incomplete']);
    }
}
```
> Implement `reassignFixture(bool $withTodayHandover = false)` in the test to build the admin + two consultants (to-consultant on_service=1) + one active admission under `$from`, optionally with a `Handover` updated today. Reuse patterns from `HandoverTest.php`.
- [ ] **Step 2: Run → FAIL** (currently 422 on the stale case).
- [ ] **Step 3: Implement.** In `bulkReassign`:
  1. Load the patient relation on the moving set — change the `$moving = Admission::whereNull('discharge_date')->where('consultant_id', $data['from_consultant_id'])->get();` to `->with('patient:id,name,mrn')->get();`.
  2. Keep the `$freshIds` computation. **Delete** the `if ($moving->contains(...)) throw ValidationException(['handover' => ...]);` block.
  3. After `$this->bustDashboardCache();` (outside the move transaction is fine), add:
```php
        // Soft gate (owner-approved): the move is NOT blocked by a stale handover. Instead, raise a
        // PERSISTENT reminder (handover.incomplete) to the actor AND the from-consultant for every moved
        // patient whose handover wasn't current today; it clears when a note is saved (HandoverController).
        $stale = $moving->reject(fn ($a) => $freshIds->has($a->id));
        if ($stale->isNotEmpty()) {
            $recipients = collect([Auth::id(), (int) $data['from_consultant_id']])->unique()->values();
            $fromName = $this->consultantName((int) $data['from_consultant_id']);
            $toName = $this->consultantName((int) $data['to_consultant_id']);
            foreach ($stale as $a) {
                foreach ($recipients as $uid) {
                    Notification::create(['user_id' => $uid, 'type' => 'handover.incomplete', 'created_at' => now(), 'payload' => [
                        'admission_id' => $a->id, 'patient_name' => $a->patient?->name, 'mrn' => $a->patient?->mrn,
                        'from_name' => $fromName, 'to_name' => $toName,
                    ]]);
                }
            }
            Audit::log('handover.reassign_incomplete', 'consultant', (string) $data['from_consultant_id'],
                ['admission_ids' => $stale->pluck('id')->all(), 'recipients' => $recipients->all()]);
        }
```
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** `feat(reassign): soft handover gate — move proceeds + persistent incomplete-handover reminders`.

---

## Task 6: ReassignModal gate → warning (client)

**Files:** Modify `resources/js/Components/Patients/ReassignModal.vue`; Test `resources/js/Components/Patients/__tests__/ReassignModal.spec.js`.

- [ ] **Step 1: Failing test** — add:
```js
it('allows confirm with a stale handover and shows the incomplete-handover warning', async () => {
    // mount, drive preflight to a state with a stale row selected (mirror the existing ReassignModal.spec setup),
    // assert preflightReady === true (was false) and the warning text renders:
    // expect(w.text()).toContain('will move with an incomplete handover');
});
```
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement.**
  - `preflightReady`: drop the `staleRows.length === 0` requirement:
```js
const preflightReady = computed(() => !!preflight.value && !preflight.value.loading && selectedIds.value.size > 0);
```
  - The Confirm button `:disabled` currently includes `|| !preflightReady`; keep it (now only requires from/to + ≥1 selected).
  - In the `<template v-if="staleRows.length">` block, keep the editors + Save-all + Uncheck-all, but change the blocking copy to a soft **warning** and make it clear the move is allowed. Replace the `{{ staleRows.length }} of {{ preflight.rows.length }} patient(s) still need today's handover note.` line with:
```html
                        <p class="mt-3 rounded-lg bg-tint-warning px-2.5 py-1.5 text-sm font-semibold text-on-warning">{{ staleRows.length }} of {{ selectedIds.size }} selected patient(s) will move with an incomplete handover — a reminder will be sent to you and the outgoing consultant until each is completed. You can write the note(s) below now, or proceed.</p>
```
- [ ] **Step 4: Run → PASS.** Also re-run the whole `ReassignModal.spec.js` (existing gate assertions must be updated to match the new soft behavior — change any test that asserted Confirm stays disabled while stale to assert it is now enabled + warning shown).
- [ ] **Step 5: Commit** `feat(reassign): gate → warning; move allowed with incomplete handover`.

---

## Task 7: Persistent "action needed" notifications (resolve + bell)

**Files:** Modify `app/Http/Controllers/HandoverController.php` (save resolve; notifications() list; readAll), `app/Http/Middleware/HandleInertiaRequests.php` (unread count), `resources/js/Layouts/AppLayout.vue` (bell UI); Test `tests/Feature/ReassignReminderTest.php`, `resources/js/__tests__/AppLayout.notifications.test.js`.

- [ ] **Step 1: Failing test (resolve)** — add to `ReassignReminderTest.php`:
```php
    public function test_saving_a_handover_resolves_the_incomplete_reminders(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        $this->actingAs($admin)->post('/admissions/reassign', ['from_consultant_id' => $from->id, 'to_consultant_id' => $to->id, 'admission_ids' => [$admission->id]]);
        $this->assertSame(2, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')->count());

        // the receiving consultant writes the note → both reminders resolve
        $this->actingAs($to)->postJson("/admissions/{$admission->id}/handover", ['body' => 'done'])->assertOk();
        $this->assertSame(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')->count());
    }
```
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Resolve on save.** In `HandoverController::save`, after the `DB::transaction(...)` block and before/after the `Audit::log(...)`, add:
```php
        // Resolve any persistent "incomplete handover" reminders for this admission (all recipients).
        Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('payload->admission_id', $admission->id)->update(['resolved_at' => now()]);
```
- [ ] **Step 4: Bell list + counts.** 
  - `HandoverController::notifications()` — return an extra `actionable` list + a resolved-aware unread count:
```php
        $me = Auth::id();
        $actionable = fn ($q) => $q->where('type', 'handover.incomplete')->whereNull('resolved_at');

        return response()->json([
            'notifications' => Notification::where('user_id', $me)->orderByDesc('id')->limit(15)
                ->get(['id', 'type', 'payload', 'read_at', 'resolved_at', 'created_at']),
            'actionable' => Notification::where('user_id', $me)->where($actionable)
                ->orderByDesc('id')->get(['id', 'type', 'payload', 'created_at']),
            'unread' => Notification::where('user_id', $me)->where(fn ($q) => $q
                ->where(fn ($x) => $x->where('type', '!=', 'handover.incomplete')->whereNull('read_at'))
                ->orWhere($actionable))->count(),
        ]);
```
  - `HandoverController::readAll()` — only dismiss non-actionable (actionable persist until resolved):
```php
        Notification::where('user_id', Auth::id())->whereNull('read_at')
            ->where('type', '!=', 'handover.incomplete')->update(['read_at' => now()]);
```
  - `HandleInertiaRequests.php` — find where `unreadNotifications` is shared and replace the count with the same resolved-aware expression:
```php
                'unreadNotifications' => \App\Models\Notification::where('user_id', $user->id)->where(fn ($q) => $q
                    ->where(fn ($x) => $x->where('type', '!=', 'handover.incomplete')->whereNull('read_at'))
                    ->orWhere(fn ($x) => $x->where('type', 'handover.incomplete')->whereNull('resolved_at')))->count(),
```
- [ ] **Step 5: Bell UI (AppLayout.vue).** In the bell dropdown, above the normal notifications list, render a pinned **"Needs attention"** group from the new `actionable` array (fetched in `toggleBell`): each item shows the `notifText` for `handover.incomplete` and links to the patient. Add to `notifText`:
```js
    if (n.type === 'handover.incomplete') {
        return `${p.patient_name || 'A patient'}${p.mrn ? ` (MRN ${p.mrn})` : ''} was reassigned from Dr. ${p.from_name || '—'} without a completed handover — complete it.`;
    }
```
  Store the fetched `actionable` (from `/api/notifications`) in a ref; render group markup:
```html
<div v-if="actionable.length" class="border-b border-line">
  <p class="px-4 pt-2.5 pb-1 text-[11px] font-bold uppercase tracking-wide text-on-warning">Needs attention</p>
  <Link v-for="n in actionable" :key="n.id" :href="`/patients?search=${encodeURIComponent(n.payload?.mrn || '')}`" @click="bellOpen = false"
        class="block px-4 py-2.5 text-start transition hover:bg-tint-warning/40">
    <p class="text-sm leading-snug text-ink-700">{{ notifText(n) }}</p>
    <p class="nums mt-0.5 text-xs text-ink-400">{{ relTime(n.created_at) }}</p>
  </Link>
</div>
```
  Add `const actionable = ref([]);` and set `actionable.value = d.actionable || []` inside `toggleBell`'s fetch handler. Keep the existing read-all POST (it now leaves actionable lit until resolved server-side).
- [ ] **Step 6: Vitest** — add an `AppLayout.notifications.test.js` case: given `/api/notifications` returns an `actionable` item, the bell renders a "Needs attention" group with the incomplete-handover text.
- [ ] **Step 7: Run** the PHPUnit + Vitest suites for these → PASS.
- [ ] **Step 8: Commit** `feat(handover): persistent incomplete-handover reminders — resolve on save + bell "Needs attention"`.

---

## Task 8: Mobile horizontal-overflow pass

**Files:** Modify `resources/css/app.css` (global guard) + per-page fixes across `resources/js/Pages/**`.

This task is **audit-driven** — fixes are found live at a 375px viewport, not pre-written. Follow the method exactly; commit once at the end.

- [ ] **Step 1: Global guard** — in `app.css` `@layer base`, add to the `body` (or the app content wrapper) a horizontal-overflow clamp so nothing can widen the page:
```css
    /* Phones: the page never scrolls sideways — wide content scrolls inside its own card instead. */
    html, body { overflow-x: hidden; }
```
  (If a specific element relies on `position: sticky` horizontally, prefer `overflow-x: clip` on the `<main>` content wrapper instead; verify the sticky sidebar/header still work.)
- [ ] **Step 2: Build + preview at 375px.** `cd laravel && npx vite build`; then `preview_start` the dev server, `resize_window` to 375×812, and open each page: Dashboard `/`, Patients `/patients`, Registry `/registry`, Control `/control`, Handovers `/handovers`, New Admissions `/admissions`, Statistics `/statistics`, Audit `/audit`, Active List `/active-list`.
- [ ] **Step 3: For each page, detect + fix overflow.** On each page run in the console: `document.documentElement.scrollWidth <= window.innerWidth` (must be `true`). If false, find the widest offender (`[...document.querySelectorAll('*')].filter(e=>e.scrollWidth>window.innerWidth+1).slice(0,5).map(e=>e.className)`), then apply the smallest fix:
  - a table/wide block → wrap in `<div class="overflow-x-auto">…</div>` (or add `overflow-x-auto` to its existing card).
  - a flex row whose child holds a long string (MRN/email/diagnosis) → add `min-w-0` to the child + `break-words`/`truncate` to the text.
  - a fixed-width element (`w-[…]` / `min-w-[…]`) that's too wide on mobile → make it `max-w-full` or responsive.
  - the dashboard KPI/chart grid → ensure it collapses to `grid-cols-1` at the base breakpoint; charts get `width:'100%'`.
- [ ] **Step 4: Re-verify** every page: `scrollWidth <= innerWidth` is `true` at 375px. Also confirm the desktop layouts (1280px) are unchanged.
- [ ] **Step 5:** `npx vite build` again (final assets), then `node scripts/check-source-allowlist.mjs --write` (new utilities from the fixes) + `node scripts/check-source-allowlist.mjs` (PASS) + `node scripts/contrast.mjs` (PASS).
- [ ] **Step 6: Commit** `fix(mobile): no horizontal page scroll on phones (global guard + per-page overflow fixes)` — include `resources/`, `public/build`, and `scripts/` (snapshot).

---

## Task 9: Compliance write-up

**Files:** Create `docs/HANDOVER-COMPLIANCE.md`.

- [ ] **Step 1: Write the document** covering, in plain English for a clinical-audit reader:
  1. **Lifecycle** — how a handover is created/edited (revision per save), the checkpoints captured, how reassignment now works (soft gate + reminder), and the receiving-consultant sign-off.
  2. **What is retained (the audit trail)** — a table: `handover_revisions` (author, timestamp, full text, checkpoint snapshot — one row per edit, append-only, never mutated); `handover_signatures` (from/to consultant, the exact revision signed, required_at/signed_at/signed_by, voided_at); `audit_logs` (`handover.update`, `handover.sign`, `handover.read` when break-glass logging is on, `handover.reassign_incomplete`); `notifications` (the reminder trail: raised → resolved, recipients, timestamps).
  3. **How to query each** for an audit (which table/column answers "who wrote what, when", "was the incoming consultant's acknowledgement recorded", "which reassignments proceeded without a completed handover and were they later completed").
  4. A short **retention/immutability** note: revisions + audit_logs are append-only; nothing is edited in place.
- [ ] **Step 2: Commit** `docs: handover clinical-audit / compliance summary`.

---

## Task 10: Full verification + ship

- [ ] **Step 1:** `cd laravel && npx vite build` (if not already current from Task 8).
- [ ] **Step 2:** `node scripts/check-source-allowlist.mjs` (PASS) + `node scripts/contrast.mjs` (PASS).
- [ ] **Step 3:** `npx vitest run` — all green.
- [ ] **Step 4:** Two-pass PHPUnit:
```
DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test --exclude-group pdf
DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe artisan test --group pdf
```
Green both passes; investigate any NEW red referencing handover/reassign/notification/mobile.
- [ ] **Step 5: Commit** any residual (rebuilt assets), then this branch is ready to push + CI.

---

## Self-review notes (for the executor)

- **Spec coverage:** §A→T8, §B→T1, §C(client)→T6, §C(server)→T5, §C(resolve+bell)→T7, §D(data)→T2, §D(save)→T3, §D(UI+chips)→T4, §E→T9. All covered.
- **Correction vs the spec:** the reassign gate is enforced BOTH client-side (T6) AND server-side (T5 — the `ValidationException(['handover'=>…])` in `bulkReassign`, ≈ lines 308–314). The spec's "client-side only" note is wrong; T5 relaxes the server throw. Both must change for the move to actually proceed.
- **Checkpoint shape** is identical everywhere: `{vte_completed, ready_for_discharge, high_risk, needs_workup, workup_pending: bool, code_status: 'full'|'dnr'|'dni'|null}` — server (T3 `normalizeCheckpoints`), model cast (T2), UI form default (T4), tests.
- **Notification contract:** type `handover.incomplete`, payload `{admission_id, patient_name, mrn, from_name, to_name}`, persists until `resolved_at` set on handover save. The resolved-aware unread count is duplicated in TWO places on purpose (T7): `HandoverController::notifications` (bell fetch) AND `HandleInertiaRequests` (per-visit shared prop) — keep them identical.
- **De-dup:** when the reassigner IS the from-consultant, `collect([Auth::id(), from_id])->unique()` yields one recipient (T5).
```
