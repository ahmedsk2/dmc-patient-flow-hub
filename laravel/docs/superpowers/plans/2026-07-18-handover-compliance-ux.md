# Handover Compliance UX — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a complete handover the easiest path at every consultant-to-consultant transfer, and make whatever still slips through impossible to miss — without ever blocking clinical care.

**Architecture:** Remove the last two hard gates so all three consultant-changing paths share one soft policy backed by a single extracted reminder helper. Introduce one `HandoverCapture.vue` component with two densities, used by both the bulk Reassign rows and the single-patient Action modal, replacing two different bare textareas. Proceeding without a handover is possible only through an explicit `useConfirm()` acknowledgement that is recorded in the audit trail. Four read-only surfaces (dashboard tile, board filter, pinned banner, inbox tab) expose the outstanding list.

**Tech Stack:** Laravel 13, Inertia 2, Vue 3 `<script setup>`, Tailwind v4. **No schema change.**

**Spec:** `docs/superpowers/specs/2026-07-18-handover-compliance-ux-design.md`

---

## Environment (read before Task 1)

- Work from `laravel/`. PHP is `C:/wamp64/bin/php/php8.5.0/php.exe` (not on PATH) — always pass `-d xdebug.mode=off`. **No Composer.**
- PHPUnit, one file: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/HandoverTest.php`
- PHPUnit, full **two-pass**: `... artisan test --exclude-group pdf` then `... artisan test --group pdf`
- Vitest: `cd laravel && npx vitest run <path>`
- `public/build` is **committed**. After ANY `resources/` change: `npx vite build` → `node scripts/check-source-allowlist.mjs --write` → `node scripts/check-source-allowlist.mjs` (PASS) → `node scripts/contrast.mjs` (PASS).
- **Line numbers below WILL have drifted — re-read each file before editing and match on the quoted code, not the line number.**
- Design tokens: reuse existing AA-safe pairs only (`bg-tint-warning`/`text-on-warning`, `bg-tint-danger`/`text-on-danger`, `bg-brand-100`/`text-brand-700`, `bg-brand-solid`/`hover:bg-brand-solid-hover`). Do not invent colour utilities.
- Test fixtures: there are **no Admission/Patient factories**. Mirror the `User::create` + `Patient::create` + `Admission::create` pattern already in `tests/Feature/HandoverCheckpointsTest.php`. Users need `mfa_secret` (`Totp::secret()`), `mfa_enrolled_at`, `email_verified_at`; `password` has a `hashed` cast so set it **plain**; login is by the `username` column.
- Non-`api/*` routes must use `$this->jsonValidate($request, [...])`, not `$request->validate()`, to answer JSON 422 (`bootstrap/app.php` limits `shouldRenderJsonWhen` to `api/*`).

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `app/Http/Controllers/PatientActionController.php` | one soft policy + shared `raiseIncompleteHandoverReminders` | 1, 2, 3 |
| `resources/js/lib/handover.js` | **new** — canonical checkpoint shape/labels shared by all editors | 4 |
| `resources/js/Components/Patients/HandoverCapture.vue` | **new** — the one handover editor, two densities | 4 |
| `resources/js/Components/Patients/HandoverModal.vue` | import the shared defaults (de-duplicate) | 4 |
| `resources/js/Components/Patients/ReassignModal.vue` | compact capture per stale row; carry checkpoints | 5 |
| `resources/js/Components/Patients/ActionModal.vue` | proactive full capture; delete the reactive gate | 6 |
| both hosts | acknowledgement dialog + `acknowledged` flag | 7 |
| `app/Http/Controllers/{Patients,Dashboard,Handover}Controller.php` | needs-handover count / filter / tab dataset | 8 |
| `resources/js/Pages/{Dashboard,Patients/Index,Handovers/Index}.vue` | tile, filter chip, pinned banner, third tab | 9 |

---

## Task 1: Extract the shared reminder helper (no behaviour change)

**Files:** Modify `app/Http/Controllers/PatientActionController.php`; Test `tests/Feature/ReassignReminderTest.php` (must stay green).

- [ ] **Step 1: Run the existing tests to establish the baseline**

Run: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/ReassignReminderTest.php`
Expected: PASS (this task is a pure refactor — these tests are the safety net).

- [ ] **Step 2: Add the helper**

Add this private method to `PatientActionController` (place it directly after the existing `createHandoverSignature` method):

```php
    /**
     * Soft handover gate (owner-approved): a consultant-changing move is NEVER blocked by a stale
     * handover. Instead raise a PERSISTENT `handover.incomplete` reminder to the acting user AND the
     * outgoing consultant for every moved patient whose handover wasn't current today; it clears when
     * a note is saved (HandoverController::save). At most ONE unresolved reminder per (user, admission).
     *
     * $acknowledged records that the actor explicitly confirmed the "handover not complete" dialog,
     * so the audit trail distinguishes a conscious clinical decision from an accident.
     */
    private function raiseIncompleteHandoverReminders(
        iterable $staleAdmissions,
        int $fromConsultantId,
        int $toConsultantId,
        bool $acknowledged = false
    ): void {
        $stale = collect($staleAdmissions);
        if ($stale->isEmpty()) {
            return;
        }
        $recipients = collect([Auth::id(), $fromConsultantId])->unique()->values();
        $fromName = $this->consultantName($fromConsultantId);
        $toName = $this->consultantName($toConsultantId);

        foreach ($stale as $a) {
            // recipients who ALREADY hold an unresolved reminder for this admission — don't duplicate.
            // The `payload->admission_id` comparison MUST cast to string: a raw int does not match the
            // stored JSON value (regression fixed previously — do not "simplify" this).
            $existing = Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
                ->where('payload->admission_id', (string) $a->id)
                ->whereIn('user_id', $recipients)->pluck('user_id')->all();
            foreach ($recipients as $uid) {
                if (in_array((int) $uid, array_map('intval', $existing), true)) {
                    continue;
                }
                Notification::create(['user_id' => $uid, 'type' => 'handover.incomplete', 'created_at' => now(), 'payload' => [
                    'admission_id' => $a->id, 'patient_name' => $a->patient?->name, 'mrn' => $a->patient?->mrn,
                    'from_name' => $fromName, 'to_name' => $toName,
                ]]);
            }
        }

        Audit::log('handover.reassign_incomplete', 'consultant', (string) $fromConsultantId, [
            'admission_ids' => $stale->pluck('id')->all(),
            'recipients' => $recipients->all(),
            'acknowledged' => $acknowledged,
        ]);
    }
```

- [ ] **Step 3: Make `bulkReassign` use it**

In `bulkReassign`, find the block that begins `$stale = $moving->reject(fn ($a) => $freshIds->has($a->id));` (immediately after `$this->bustDashboardCache();`) and replace **the whole `if ($stale->isNotEmpty()) { … }` block** with:

```php
        $stale = $moving->reject(fn ($a) => $freshIds->has($a->id));
        $this->raiseIncompleteHandoverReminders(
            $stale,
            (int) $data['from_consultant_id'],
            (int) $data['to_consultant_id'],
            $request->boolean('acknowledged'),
        );
```

- [ ] **Step 4: Run tests to verify nothing changed**

Run: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/ReassignReminderTest.php tests/Feature/HandoverTest.php`
Expected: PASS, same counts as Step 1.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PatientActionController.php
git commit -m "refactor(reassign): extract raiseIncompleteHandoverReminders helper"
```

---

## Task 2: Soften the single-patient `assign` path

**Files:** Modify `app/Http/Controllers/PatientActionController.php`; Test `tests/Feature/ReassignReminderTest.php`.

**Context:** `assign()` currently calls `$this->assertHandoverToday($admission);` which throws `ValidationException(['handover' => 'Handover must be updated today before transfer.'])`. **Read the method first** and note the exact condition under which that call is reached (it only applies when moving to a *different* consultant — a first assignment is not gated). You must apply the reminder under that **same** condition.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ReassignReminderTest.php` (reuse the file's existing `reassignFixture()` helper; read it first):

```php
    public function test_single_assign_to_a_different_consultant_proceeds_with_a_stale_handover(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/assign", [
            'consultant_id' => $to->id,
        ])->assertRedirect()->assertSessionHasNoErrors();   // NO 422 — the hard gate is gone

        $this->assertSame($to->id, (int) $admission->fresh()->consultant_id);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
    }

    public function test_single_assign_records_the_acknowledgement_in_the_audit_trail(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/assign", [
            'consultant_id' => $to->id, 'acknowledged' => true,
        ])->assertRedirect();

        $row = \App\Models\AuditLog::where('action', 'handover.reassign_incomplete')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) ($row->details['acknowledged'] ?? false));
    }
```

- [ ] **Step 2: Run → FAIL**

Run: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/ReassignReminderTest.php`
Expected: FAIL — the first test gets a validation error on `handover` instead of a clean redirect.

- [ ] **Step 3: Implement**

In `assign()`:
1. **Delete** the line `$this->assertHandoverToday($admission);`.
2. Capture the outgoing consultant **before** the update if the method doesn't already (it does — it is used for `createHandoverSignature`; reuse that same variable, referred to below as `$oldConsultant`).
3. After the move completes and `$this->bustDashboardCache();` runs, add — **inside the same `if` that previously guarded `assertHandoverToday`** (i.e. only when the consultant actually changed to a different one):

```php
            if (! Handover::updatedToday($admission->id)) {
                $admission->loadMissing('patient:id,name,mrn');
                $this->raiseIncompleteHandoverReminders(
                    [$admission],
                    (int) $oldConsultant,
                    (int) $data['consultant_id'],
                    $request->boolean('acknowledged'),
                );
            }
```

4. Allow the new field through validation: add `'acknowledged' => ['sometimes', 'boolean']` to this method's validation rules.

- [ ] **Step 4: Run → PASS**

Run: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/ReassignReminderTest.php tests/Feature/HandoverTest.php`
Expected: PASS. **If a pre-existing test asserted the old blocking behaviour on `assign`, rename it and flip it to the soft expectation — do not delete the coverage.**

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PatientActionController.php tests/Feature/ReassignReminderTest.php tests/Feature/HandoverTest.php
git commit -m "feat(assign): soft handover gate on single-patient assign + acknowledgement audit"
```

---

## Task 3: Soften the specialty-transfer path

**Files:** Modify `app/Http/Controllers/PatientActionController.php`; Test `tests/Feature/ReassignReminderTest.php`.

- [ ] **Step 1: Write the failing test**

```php
    public function test_specialty_transfer_proceeds_with_a_stale_handover_and_notifies(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        // transferSpecialty needs a specialty + a consultant of that specialty; mirror how
        // tests/Feature/HandoverTest.php builds a specialty transfer (read it first and copy the
        // exact payload shape — mode/target/specialty_id/consultant_id).
        $to->forceFill(['specialty_id' => $from->specialty_id ?: 1])->save();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $to->specialty_id, 'consultant_id' => $to->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
    }
```

- [ ] **Step 2: Run → FAIL** (currently rejected by the handover gate).

- [ ] **Step 3: Implement**

In `transferSpecialty()`:
1. **Delete** `$this->assertHandoverToday($admission);`.
2. After the transfer completes and the cache is busted, add (using the outgoing-consultant variable the method already captures for `createHandoverSignature`):

```php
            if (! Handover::updatedToday($admission->id)) {
                $admission->loadMissing('patient:id,name,mrn');
                $this->raiseIncompleteHandoverReminders(
                    [$admission],
                    (int) $oldConsultant,
                    (int) $data['consultant_id'],
                    $request->boolean('acknowledged'),
                );
            }
```

3. Add `'acknowledged' => ['sometimes', 'boolean']` to this method's validation rules.
4. **Now check whether `assertHandoverToday` has any remaining callers:** `grep -n "assertHandoverToday" app/Http/Controllers/PatientActionController.php`. If none remain, delete the method and its docblock. If `Handover::updatedToday` becomes unused too, leave it — it is still used by the new code above.

- [ ] **Step 4: Run → PASS**

Run: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/ReassignReminderTest.php tests/Feature/HandoverTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PatientActionController.php tests/Feature/ReassignReminderTest.php
git commit -m "feat(transfer): soft handover gate on specialty transfer; drop assertHandoverToday"
```

---

## Task 4: `HandoverCapture.vue` + shared checkpoint lib

**Files:** Create `resources/js/lib/handover.js`, `resources/js/Components/Patients/HandoverCapture.vue`, `resources/js/Components/Patients/__tests__/HandoverCapture.spec.js`; Modify `resources/js/Components/Patients/HandoverModal.vue`.

- [ ] **Step 1: Write the failing test**

Create `resources/js/Components/Patients/__tests__/HandoverCapture.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import HandoverCapture from '@/Components/Patients/HandoverCapture.vue';

const cp = { vte_completed: true, ready_for_discharge: false, high_risk: true, needs_workup: false, workup_pending: false, code_status: 'dnr' };

describe('HandoverCapture', () => {
    it('full density renders a labelled checkbox per flag plus a code-status select', () => {
        const w = mount(HandoverCapture, { props: { density: 'full', body: 'prior note', checkpoints: cp } });
        expect(w.findAll('input[type="checkbox"]')).toHaveLength(5);
        expect(w.find('select').exists()).toBe(true);
        expect(w.find('textarea').element.value).toBe('prior note');
        w.unmount();
    });

    it('compact density renders one toggle button per flag (no checkbox list)', () => {
        const w = mount(HandoverCapture, { props: { density: 'compact', body: '', checkpoints: cp } });
        expect(w.findAll('[data-cp-toggle]')).toHaveLength(5);
        expect(w.findAll('input[type="checkbox"]')).toHaveLength(0);
        w.unmount();
    });

    it('emits update:checkpoints with the flag flipped when a compact chip is clicked', async () => {
        const w = mount(HandoverCapture, { props: { density: 'compact', body: '', checkpoints: cp } });
        await w.findAll('[data-cp-toggle]')[0].trigger('click');   // vte_completed: true -> false
        expect(w.emitted('update:checkpoints').at(-1)[0].vte_completed).toBe(false);
        w.unmount();
    });

    it('tolerates a null checkpoints payload by falling back to the canonical shape', () => {
        const w = mount(HandoverCapture, { props: { density: 'full', body: '', checkpoints: null } });
        expect(w.findAll('input[type="checkbox"]').every((c) => c.element.checked === false)).toBe(true);
        w.unmount();
    });

    it('shows a stale pill when the handover was not saved today', () => {
        const w = mount(HandoverCapture, { props: { density: 'full', body: '', checkpoints: null, today: false, updatedAt: '2026-07-10T09:00:00+00:00' } });
        expect(w.text()).toContain('stale');
        w.unmount();
    });
});
```

- [ ] **Step 2: Run → FAIL**

Run: `cd laravel && npx vitest run resources/js/Components/Patients/__tests__/HandoverCapture.spec.js`
Expected: FAIL — cannot resolve `HandoverCapture.vue`.

- [ ] **Step 3: Create the shared lib**

Create `resources/js/lib/handover.js`:

```js
// Canonical handover checkpoint shape — the ONE definition shared by HandoverCapture,
// HandoverModal and CheckpointChips. Server mirror: HandoverController::normalizeCheckpoints().
export const CHECKPOINT_FIELDS = [
    { key: 'vte_completed', label: 'VTE prophylaxis', short: 'VTE' },
    { key: 'ready_for_discharge', label: 'Ready for discharge', short: 'D/C ready' },
    { key: 'high_risk', label: 'High-risk', short: 'High-risk' },
    { key: 'needs_workup', label: 'Needs more workup', short: 'Needs workup' },
    { key: 'workup_pending', label: 'Workup pending', short: 'Workup pending' },
];

export const CODE_STATUS_OPTIONS = [
    { value: null, label: 'None' },
    { value: 'full', label: 'Full' },
    { value: 'dnr', label: 'DNR' },
    { value: 'dni', label: 'DNI' },
];

export const defaultCheckpoints = () => ({
    vte_completed: false, ready_for_discharge: false, high_risk: false,
    needs_workup: false, workup_pending: false, code_status: null,
});

/** Spread a fetched (possibly null/partial) payload over the canonical shape. */
export const withCheckpointDefaults = (cp) => ({ ...defaultCheckpoints(), ...(cp || {}) });
```

- [ ] **Step 4: Create the component**

Create `resources/js/Components/Patients/HandoverCapture.vue`:

```vue
<script setup>
import { computed } from 'vue';
import { CHECKPOINT_FIELDS, CODE_STATUS_OPTIONS, withCheckpointDefaults } from '@/lib/handover.js';

/**
 * HandoverCapture — the ONE handover editor used at every point of care transfer.
 *
 *   density="compact" → bulk Reassign rows: tappable chips + inline code status + note.
 *                       Stays scannable when several patients are stale at once.
 *   density="full"    → single-patient Assign / specialty-transfer modal: labelled checkboxes,
 *                       code-status select, full note, collapsible revision history.
 *
 * Presentational + fully controlled: the host owns the state and passes body/checkpoints down,
 * receiving update:body / update:checkpoints back. It never saves — the host does.
 */
const props = defineProps({
    body: { type: String, default: '' },
    checkpoints: { type: Object, default: null },
    density: { type: String, default: 'full', validator: (v) => ['compact', 'full'].includes(v) },
    updatedAt: { type: String, default: null },
    today: { type: Boolean, default: false },
    revisions: { type: Array, default: () => [] },
    label: { type: String, default: '' },   // patient name — disambiguates aria-labels in stacked rows
});
const emit = defineEmits(['update:body', 'update:checkpoints']);

const cp = computed(() => withCheckpointDefaults(props.checkpoints));
const fmtAt = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
const toggle = (key) => emit('update:checkpoints', { ...cp.value, [key]: !cp.value[key] });
const setCode = (v) => emit('update:checkpoints', { ...cp.value, code_status: v === '' ? null : v });
const aria = (suffix) => (props.label ? `${suffix} for ${props.label}` : suffix);
</script>

<template>
    <div>
        <p class="mb-1 flex flex-wrap items-center gap-2 text-xs text-ink-400">
            <span v-if="updatedAt">Handover · last updated {{ fmtAt(updatedAt) }}</span>
            <span v-else>No handover recorded yet</span>
            <span v-if="!today" class="rounded-full bg-tint-warning px-2 py-0.5 text-[10px] font-semibold text-on-warning">stale</span>
        </p>

        <!-- compact: the chips ARE the control -->
        <div v-if="density === 'compact'" class="mb-2 flex flex-wrap items-center gap-1.5">
            <button v-for="f in CHECKPOINT_FIELDS" :key="f.key" type="button" data-cp-toggle
                    :aria-pressed="cp[f.key]" :aria-label="aria(f.label)" @click="toggle(f.key)"
                    class="rounded-full px-2.5 py-1 text-[11px] font-semibold transition"
                    :class="cp[f.key] ? 'bg-brand-100 text-brand-700' : 'border border-ink-200 text-ink-500 hover:bg-ink-50'">
                {{ cp[f.key] ? '✓ ' : '' }}{{ f.short }}
            </button>
            <label class="ml-1 flex items-center gap-1 text-[11px] text-ink-500">
                <span class="sr-only">{{ aria('Code status') }}</span>Code
                <select :value="cp.code_status ?? ''" @change="setCode($event.target.value)"
                        class="rounded-lg border border-ink-200 px-2 py-1 text-[11px] outline-none focus:border-brand-500">
                    <option v-for="o in CODE_STATUS_OPTIONS" :key="String(o.value)" :value="o.value ?? ''">{{ o.label }}</option>
                </select>
            </label>
        </div>

        <!-- full: labelled controls with room to breathe -->
        <div v-else class="mb-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm text-ink-600 sm:grid-cols-3">
            <label v-for="f in CHECKPOINT_FIELDS" :key="f.key" class="flex items-center gap-2">
                <input type="checkbox" class="rounded text-brand-600" :checked="cp[f.key]" @change="toggle(f.key)" />
                {{ f.label }}
            </label>
            <label class="flex items-center gap-2">Code status
                <select :value="cp.code_status ?? ''" @change="setCode($event.target.value)" :aria-label="aria('Code status')"
                        class="rounded-lg border border-ink-200 px-2 py-1 text-xs outline-none focus:border-brand-500">
                    <option v-for="o in CODE_STATUS_OPTIONS" :key="String(o.value)" :value="o.value ?? ''">{{ o.label }}</option>
                </select>
            </label>
        </div>

        <textarea :value="body" @input="emit('update:body', $event.target.value)"
                  :rows="density === 'compact' ? 2 : 6" maxlength="5000" :aria-label="aria('Handover text')"
                  placeholder="Write today's handover…"
                  class="w-full rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>

        <details v-if="density === 'full' && revisions.length" class="mt-2">
            <summary class="cursor-pointer text-xs font-semibold text-brand-600">History ({{ revisions.length }})</summary>
            <ul class="mt-1 space-y-1">
                <li v-for="(r, i) in revisions" :key="i" class="rounded-lg bg-app/70 px-2 py-1 text-xs text-ink-600">
                    <span class="font-semibold">{{ r.author || '—' }}</span> · {{ fmtAt(r.at) }}
                    <p class="whitespace-pre-wrap">{{ r.body }}</p>
                </li>
            </ul>
        </details>
    </div>
</template>
```

- [ ] **Step 5: De-duplicate the defaults in `HandoverModal.vue`**

In `HandoverModal.vue`, delete the local `defaultCheckpoints` factory and import the shared one instead:

```js
import { defaultCheckpoints, withCheckpointDefaults } from '@/lib/handover.js';
```

Then replace the merge in the fetch handler with `hForm.checkpoints = withCheckpointDefaults(d.checkpoints);` and keep `useForm({ body: '', checkpoints: defaultCheckpoints() })` as-is. **Do not change HandoverModal's own editor markup in this task** — its spec must stay green.

- [ ] **Step 6: Run → PASS**

Run: `cd laravel && npx vitest run resources/js/Components/Patients/__tests__/HandoverCapture.spec.js resources/js/Components/Patients/__tests__/HandoverModal.spec.js`
Expected: both PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/js/lib/handover.js resources/js/Components/Patients/HandoverCapture.vue resources/js/Components/Patients/HandoverModal.vue resources/js/Components/Patients/__tests__/HandoverCapture.spec.js
git commit -m "feat(handover): HandoverCapture component (compact + full) on a shared checkpoint lib"
```

---

## Task 5: Bulk Reassign rows use the compact capture

**Files:** Modify `resources/js/Components/Patients/ReassignModal.vue`; Test `resources/js/Components/Patients/__tests__/ReassignModal.spec.js`.

**Context:** the modal keeps `preflight` (`{loading, rows}`), a `preflightBodies` map keyed by admission id (pre-filled from `r.body`), `staleRows`, `allStaleFilled`, `saveAllStale()`, `uncheckAllStale()`, `preflightReady`, `submitReassign`. Read it before editing.

- [ ] **Step 1: Write the failing test**

Add to `ReassignModal.spec.js`. **First read the file** — it already mounts the modal and already mocks `@/composables/useHandover` (with a `saveHandover` spy) and drives `preflight`. Reuse that exact mount helper and spy name; only the body below is new:

```js
import HandoverCapture from '@/Components/Patients/HandoverCapture.vue';
import { withCheckpointDefaults } from '@/lib/handover.js';

it('renders a compact HandoverCapture per stale row and sends checkpoints on save-all', async () => {
    const w = mountModal();          // <- this file's existing mount helper
    // drive preflight exactly as the neighbouring tests do (same shape, plus the new checkpoints key)
    w.vm.preflight = { loading: false, rows: [
        { id: 7, name: 'Ahmed M.', mrn: '44219', handover_today: false, body: 'prior note', checkpoints: null },
    ] };
    w.vm.selectedIds.add(7);
    await nextTick();

    const caps = w.findAllComponents(HandoverCapture);
    expect(caps).toHaveLength(1);
    expect(caps[0].props('density')).toBe('compact');

    caps[0].vm.$emit('update:body', 'today note');
    caps[0].vm.$emit('update:checkpoints', { ...withCheckpointDefaults(null), high_risk: true });
    await nextTick();

    await w.vm.saveAllStale();
    // the THIRD argument is the point of this test — checkpoints now travel with the note
    expect(saveHandoverSpy).toHaveBeenCalledWith(7, 'today note', expect.objectContaining({ high_risk: true }));
    w.unmount();
});
```

(If the existing spy is named differently, use that name. If the file drives `preflight` through `loadPreflight()` with a mocked fetch rather than by assignment, drive it that way instead — match the file, don't fight it.)

- [ ] **Step 2: Run → FAIL**

Run: `cd laravel && npx vitest run resources/js/Components/Patients/__tests__/ReassignModal.spec.js`

- [ ] **Step 3: Implement**

1. Import the component and the lib:

```js
import HandoverCapture from '@/Components/Patients/HandoverCapture.vue';
import { withCheckpointDefaults } from '@/lib/handover.js';
```

2. Add a checkpoints map beside `preflightBodies`, populated in the same place `preflightBodies` is (the preflight load handler):

```js
const preflightCheckpoints = ref({});
```

and where `preflightBodies.value = Object.fromEntries(...)` is assigned, add:

```js
    preflightCheckpoints.value = Object.fromEntries(rows.filter((r) => !r.handover_today).map((r) => [r.id, withCheckpointDefaults(r.checkpoints)]));
```

3. Replace the per-row `<textarea v-model="preflightBodies[r.id]" …>` with:

```html
                            <HandoverCapture density="compact" :label="r.name"
                                :body="preflightBodies[r.id] || ''"
                                :checkpoints="preflightCheckpoints[r.id]"
                                :today="false"
                                @update:body="preflightBodies[r.id] = $event"
                                @update:checkpoints="preflightCheckpoints[r.id] = $event" />
```

(The `data-stale-textarea` focus hook lived on the old textarea. Keep focus working by targeting the capture's textarea instead: change the `nextTick` focus call to `document.querySelector('[data-stale-capture] textarea')?.focus()` and add `data-stale-capture` to the wrapping `<div v-for="(r, i) in staleRows">` for `i === 0`.)

4. Pass checkpoints through the save loop in `saveAllStale()`:

```js
        for (const r of staleRows.value) await saveHandover(r.id, preflightBodies.value[r.id].trim(), preflightCheckpoints.value[r.id]);
```

5. Expose the new ref for tests by adding `preflightCheckpoints` to the existing `defineExpose({ … })`.

- [ ] **Step 4: Run → PASS**

Run: `cd laravel && npx vitest run resources/js/Components/Patients/__tests__/ReassignModal.spec.js`

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Patients/ReassignModal.vue resources/js/Components/Patients/__tests__/ReassignModal.spec.js
git commit -m "feat(reassign): compact HandoverCapture per stale row; checkpoints saved with the note"
```

---

## Task 6: Single-patient modal shows the handover proactively

**Files:** Modify `resources/js/Components/Patients/ActionModal.vue`; Test `resources/js/Components/Patients/__tests__/ActionModal.spec.js` (create if absent).

**Context:** `ActionModal` currently implements a **reactive** gate: `gateBody` ref + `gateBusy` + `saveGateThen(retry)`, and an amber block rendered only `v-if="aForm.errors.handover"` / `v-if="tForm.errors.handover"` containing a **blank** textarea. All of that is deleted here.

- [ ] **Step 1: Write the failing test**

Create the spec if absent. Mock `@/composables/useHandover` so `fetchHandover` resolves a payload and `saveHandover` is a spy (copy the mock shape from `ReassignModal.spec.js`).

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ActionModal from '@/Components/Patients/ActionModal.vue';
import HandoverCapture from '@/Components/Patients/HandoverCapture.vue';

const patient = { id: 7, name: 'Ahmed M.', mrn: '44219', consultant_id: 1 };
const consultants = [{ id: 1, name: 'Dr A', on_service: 1 }, { id: 2, name: 'Dr B', on_service: 1 }];
const mountAssign = () => mount(ActionModal, {
    props: { open: true, mode: 'assign', patient, consultants },
    global: { stubs: { BaseModal: { template: '<div><slot /></div>' }, Link: true } },
});

describe('ActionModal — proactive handover', () => {
    it('shows the handover editor as soon as a DIFFERENT consultant is picked, before any submit', async () => {
        const w = mountAssign();
        expect(w.findComponent(HandoverCapture).exists()).toBe(false);

        w.vm.aForm.consultant_id = 2;
        await nextTick(); await nextTick();      // watch + fetchHandover resolution

        const cap = w.findComponent(HandoverCapture);
        expect(cap.exists()).toBe(true);
        expect(cap.props('density')).toBe('full');
        w.unmount();
    });

    it('does NOT show the handover editor when re-picking the SAME consultant', async () => {
        const w = mountAssign();
        w.vm.aForm.consultant_id = 1;            // unchanged — a first/no-op assign is not a handoff
        await nextTick(); await nextTick();
        expect(w.findComponent(HandoverCapture).exists()).toBe(false);
        w.unmount();
    });
});
```

(`aForm` must be reachable for this — add it to the component's `defineExpose` if it isn't already. If `BaseModal` stubbing differs elsewhere in the repo, match the prevailing pattern.)

- [ ] **Step 2: Run → FAIL**

Run: `cd laravel && npx vitest run resources/js/Components/Patients/__tests__/ActionModal.spec.js`

- [ ] **Step 3: Implement**

1. Imports:

```js
import HandoverCapture from '@/Components/Patients/HandoverCapture.vue';
import { withCheckpointDefaults } from '@/lib/handover.js';
```

2. Replace `gateBody`/`gateBusy`/`saveGateThen` with proactive state. `useHandover()` already provides `fetchHandover` and `saveHandover` — destructure both:

```js
const { fetchHandover, saveHandover } = useHandover();

// Proactive handover panel — shown the moment the chosen consultant differs from the current one,
// never as a reaction to a rejected submit (the old behaviour: the requirement was invisible until
// the user had already pressed the button).
const ho = ref(null);            // null | { body, checkpoints, today, updated_at, revisions }
const hoBody = ref('');
const hoCheckpoints = ref(withCheckpointDefaults(null));
const hoSaving = ref(false);

const changingConsultant = computed(() =>
    (props.mode === 'assign' && !!aForm.consultant_id && Number(aForm.consultant_id) !== Number(props.patient?.consultant_id))
    || (props.mode === 'transfer' && tForm.mode === 'specialty' && !!tForm.consultant_id));

watch(changingConsultant, async (on) => {
    if (!on || !props.patient) { ho.value = null; return; }
    const d = await fetchHandover(props.patient.id);
    ho.value = d;
    hoBody.value = d?.body || '';
    hoCheckpoints.value = withCheckpointDefaults(d?.checkpoints);
});

/** Save the handover, then run the original submit. Used by the primary button. */
const saveHandoverThen = async (retry) => {
    if (!hoBody.value.trim()) { retry(); return; }
    hoSaving.value = true;
    try { await saveHandover(props.patient.id, hoBody.value.trim(), hoCheckpoints.value); ho.value = { ...ho.value, today: true }; retry(); }
    finally { hoSaving.value = false; }
};
```

3. **Delete** both amber `v-if="…errors.handover"` blocks and their textareas/buttons entirely.

4. In the `assign` form, immediately above the submit row, add:

```html
                <div v-if="changingConsultant" class="rounded-xl bg-app/60 p-3 ring-1 ring-line">
                    <HandoverCapture density="full" :label="patient?.name"
                        :body="hoBody" :checkpoints="hoCheckpoints"
                        :today="!!ho?.today" :updated-at="ho?.updated_at" :revisions="ho?.revisions || []"
                        @update:body="hoBody = $event" @update:checkpoints="hoCheckpoints = $event" />
                </div>
```

Add the identical block inside the `transfer` form's specialty branch (bind the same refs).

5. Change the assign submit button label to `Save handover & assign` when `changingConsultant` is true (keep `Assign` otherwise), and route it through `saveHandoverThen(submitAssign)`. Do the same for the specialty transfer (`Save handover & transfer` → `saveHandoverThen(submitTransfer)`). **Do not add any "assign without handover" button** — proceeding without one goes through the Task 7 dialog.

6. Widen the modal so the full editor is usable: change `<BaseModal … size="md"` to `size="lg"`. (`lg` = `max-w-lg`; `wide`/`max-w-4xl` is reserved for the bulk modal's multi-patient list and is oversized for a single form.)

- [ ] **Step 4: Run → PASS**

Run: `cd laravel && npx vitest run resources/js/Components/Patients/__tests__/ActionModal.spec.js`

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Patients/ActionModal.vue resources/js/Components/Patients/__tests__/ActionModal.spec.js
git commit -m "feat(assign): proactive full HandoverCapture in the single-patient modal; drop the reactive gate"
```

---

## Task 7: The acknowledgement dialog

**Files:** Modify `resources/js/Components/Patients/ActionModal.vue`, `resources/js/Components/Patients/ReassignModal.vue`; Tests: both specs.

**Context:** `useConfirm()` exposes `ask(title, body, tone) → Promise<boolean>`; `<ConfirmDialog />` is already mounted once in `AppLayout`. Cancel resolves `false`, Confirm resolves `true`.

> **This task SUPERSEDES the button wiring from Task 6 Step 5.** There, the assign/transfer buttons were pointed directly at `saveHandoverThen(submitAssign)`. Here they are re-pointed at `submitWithHandoverGuard(submitAssign, aForm)`, which calls `saveHandoverThen` internally when the handover is complete. That is intentional (Task 6 ships a working proactive panel; Task 7 adds the gate on top) — do not treat the change as a mistake or revert it.

- [ ] **Step 1: Write the failing tests**

```js
// ActionModal.spec.js
it('asks for acknowledgement when assigning with an incomplete handover, and aborts on Cancel', async () => {
    // stub useConfirm's ask to resolve false; trigger submit with an empty hoBody;
    // assert ask() was called and NO router/post call happened.
});
it('submits with acknowledged:true when the user confirms', async () => {
    // stub ask to resolve true; assert the posted payload contains acknowledged: true
});

// ReassignModal.spec.js
it('asks once for the whole batch when stale rows remain, and posts acknowledged:true on confirm', async () => {
    // two stale rows selected → exactly ONE ask() call whose body contains "2 of"
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Implement — ActionModal**

```js
import { useConfirm } from '@/composables/useConfirm';
const { ask } = useConfirm();

/**
 * Single primary action. If the handover isn't current, intercept with an explicit
 * acknowledgement — there is deliberately NO visible "assign without handover" button.
 */
const submitWithHandoverGuard = async (retry, formRef) => {
    const complete = !!ho.value?.today || !!hoBody.value.trim();
    if (complete) { await saveHandoverThen(retry); return; }
    const ok = await ask(
        'Handover not complete',
        `${props.patient?.name || 'This patient'} has no handover saved today. A reminder will be sent to you and the outgoing consultant until it is completed.`,
        'warning',
    );
    if (!ok) return;                      // "Complete handover now" — stay put, panel is already open
    formRef.acknowledged = true;          // "I'm aware — proceed"
    retry();
};
```

Add `acknowledged: false` to `aForm` and `tForm`'s `useForm({...})` initial state, and route both primary buttons through `submitWithHandoverGuard(submitAssign, aForm)` / `submitWithHandoverGuard(submitTransfer, tForm)`.

Confirm-dialog button labels come from `ConfirmDialog.vue` (Cancel / Confirm) — do **not** try to relabel them per-call; the dialog title and body carry the meaning, and Cancel is the safe default that keeps the user on the panel.

- [ ] **Step 4: Implement — ReassignModal**

```js
import { useConfirm } from '@/composables/useConfirm';
const { ask } = useConfirm();

const confirmThenSubmit = async () => {
    if (staleRows.value.length === 0) { submitReassign(); return; }
    const ok = await ask(
        'Handover not complete',
        `${staleRows.value.length} of ${selectedIds.value.size} selected patient(s) have no handover saved today. A reminder will be sent to you and the outgoing consultant until each is completed.`,
        'warning',
    );
    if (!ok) { document.querySelector('[data-stale-capture] textarea')?.focus(); return; }
    rForm.acknowledged = true;
    submitReassign();
};
```

Add `acknowledged: false` to `rForm`'s `useForm({...})`, point the Confirm button at `confirmThenSubmit`, and add `confirmThenSubmit` to `defineExpose`.

- [ ] **Step 5: Run → PASS**

Run: `cd laravel && npx vitest run resources/js/Components/Patients/__tests__/ActionModal.spec.js resources/js/Components/Patients/__tests__/ReassignModal.spec.js`

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Patients/ActionModal.vue resources/js/Components/Patients/ReassignModal.vue resources/js/Components/Patients/__tests__/
git commit -m "feat(handover): acknowledgement dialog replaces any visible bypass; acknowledged flag sent to the server"
```

---

## Task 8: Backend — needs-handover count, filter, and tab dataset

**Files:** Modify `app/Http/Controllers/HandoverController.php`, `app/Http/Controllers/PatientsController.php`, `app/Http/Controllers/DashboardController.php`; Test `tests/Feature/HandoverTest.php`.

**Definition (use verbatim everywhere):** an `Admission::active()` row whose handover has no save dated today —
`->whereDoesntHave('handover', fn ($q) => $q->whereDate('updated_at', today()))`.
This correctly covers both "no handover row at all" and "a row not updated today".

- [ ] **Step 1: Write the failing tests**

```php
    public function test_needs_handover_counts_are_personal_and_resolved_by_saving_a_note(): void
    {
        $me = $this->user();
        $a1 = $this->admission(['consultant_id' => $me->id]);   // stale
        $a2 = $this->admission(['consultant_id' => $me->id]);
        $this->freshHandover($a2, $me);                         // current today
        $other = $this->user();
        $this->admission(['consultant_id' => $other->id]);      // someone else's — must NOT count

        $this->actingAs($me)->get('/patients')->assertOk()
            ->assertInertia(fn ($p) => $p->where('needsHandoverCount', 1));
    }

    public function test_needs_handover_tab_lists_only_stale_active_admissions(): void
    {
        $me = $this->user();
        $stale = $this->admission(['consultant_id' => $me->id]);
        $fresh = $this->admission(['consultant_id' => $me->id]);
        $this->freshHandover($fresh, $me);

        $this->actingAs($me)->get('/handovers')->assertOk()
            ->assertInertia(fn ($p) => $p->has('needsHandover', 1)
                ->where('needsHandover.0.admission_id', $stale->id));
    }
```

> `assertInertia` needs `use Inertia\Testing\AssertableInertia as Assert;` — check how existing tests in this file assert Inertia props and copy that style. If the file has no Inertia assertions yet, assert on the JSON of the Inertia response instead (`->assertInertia(...)` is available in this project — verify with `grep -rn "assertInertia" tests/` first and match the prevailing pattern).

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Implement — a shared scope**

Add to `app/Models/Admission.php`:

```php
    /** Active admissions with no handover saved today — the "needs handover" definition. */
    public function scopeNeedsHandoverToday($query)
    {
        return $query->active()->whereDoesntHave('handover', fn ($q) => $q->whereDate('updated_at', today()));
    }
```

- [ ] **Step 4: Implement — the three controllers**

`PatientsController` (in `index`, alongside the existing props): add the count and honour a `needs_handover` filter inside `boardGroups()`:

```php
        'needsHandoverCount' => Admission::needsHandoverToday()->where('consultant_id', $request->user()->id)->count(),
```

and in `boardGroups()`, where the other filters are applied:

```php
            ->when($request->boolean('needs_handover'), fn ($q) => $q->needsHandoverToday())
```

Add `'needs_handover'` to the `$request->only([...])` filter whitelist (it is a boolean flag, **not** PHI — unlike `search`, it is safe in the query string).

`DashboardController`: add `'handoverDue' => Admission::needsHandoverToday()->where('consultant_id', $user->id)->count(),` to the `myUnit` payload, and `'handoverDueUnit' => Admission::needsHandoverToday()->count(),` to `adminBand`.

`HandoverController::index`: add a third dataset next to `awaiting`/`outgoing`:

```php
            'needsHandover' => Admission::needsHandoverToday()
                ->when(! Auth::user()->isAdmin(), fn ($q) => $q->where('consultant_id', Auth::id()))
                ->with(['patient:id,mrn,name', 'handover', 'consultant:id,name,full_name'])
                ->orderBy('admit_date')->get()
                ->map(fn ($a) => [
                    'admission_id' => $a->id,
                    'patient' => $a->patient?->name ?? 'Unknown',
                    'mrn' => $a->patient?->mrn,
                    'bed' => $a->bed,
                    'consultant' => $a->consultant ? ($a->consultant->full_name ?: $a->consultant->name) : '—',
                    'last_updated' => $a->handover?->updated_at?->toIso8601String(),
                    'checkpoints' => $a->handover?->checkpoints,
                ])->values(),
```

(Verify the `consultant` relation name on `Admission` before using it — `grep -n "function consultant" app/Models/Admission.php`.)

`HandoverController::preflight`: add `'checkpoints' => $a->handover?->checkpoints,` to the mapped row (the compact capture needs it).

- [ ] **Step 5: Run → PASS**

Run: `cd laravel && DB_DATABASE=dmc_test2 C:/wamp64/bin/php/php8.5.0/php.exe -d xdebug.mode=off artisan test tests/Feature/HandoverTest.php`

- [ ] **Step 6: Commit**

```bash
git add app/Models/Admission.php app/Http/Controllers/HandoverController.php app/Http/Controllers/PatientsController.php app/Http/Controllers/DashboardController.php tests/Feature/HandoverTest.php
git commit -m "feat(handover): needsHandoverToday scope + board filter, dashboard counts, inbox dataset"
```

---

## Task 9: Frontend visibility — tile, filter chip, pinned banner, third tab

**Files:** Modify `resources/js/Pages/Dashboard.vue`, `resources/js/Pages/Patients/Index.vue`, `resources/js/Pages/Handovers/Index.vue`; Tests: `resources/js/Pages/Patients/__tests__/Index.spec.js` + a new `resources/js/Pages/Handovers/__tests__/Index.spec.js`.

- [ ] **Step 1: Write the failing tests**

```js
// Patients/Index.spec.js
it('pins the needs-handover banner whenever the personal count is above zero', () => {
    // mount with needsHandoverCount: 3 → expect(w.text()).toContain('3')
    //                                   expect(w.text()).toMatch(/no handover today/i)
});
it('renders no banner when the count is zero', () => {
    // mount with needsHandoverCount: 0 → banner absent
});

// Handovers/Index.spec.js
it('renders a Needs handover tab with one row per stale admission', async () => {
    // mount with needsHandover: [{admission_id:7, patient:'A', mrn:'1', consultant:'Dr X'}]
    // click the tab, assert the row renders
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Dashboard tile**

In `Dashboard.vue`, add one entry to the `myUnitCards` computed array (it is a list of `[label, value, tone, link]` tuples), after `['Boarding', …]`:

```js
    ['Handover due', props.myUnit.handoverDue, 'bg-tint-warning/30', '/patients?needs_handover=1'],
```

and one entry to `adminBandCards`, after the existing `Pending Handovers` tile:

```js
    { label: 'Handover Due (unit)', count: props.adminBand.handoverDueUnit ?? 0, href: '/patients?needs_handover=1', iconPath: bandIcons.handover, urgent: true },
```

- [ ] **Step 4: Board filter chip + pinned banner**

In `Patients/Index.vue`:

1. Accept the new prop: add `needsHandoverCount: { type: Number, default: 0 }` to `defineProps`.
2. Import the callout: `import FlowAlert from '@/Components/FlowAlert.vue';` (props: `tone` ∈ info|warning|critical, `title` required; body goes in the default slot).
3. Render the banner at the very top of the page content, **pinned whenever the count is above zero** (owner's decision — not dismissible; it clears only when the count reaches zero). It always uses the personal count, including for admins:

```html
        <FlowAlert v-if="needsHandoverCount > 0" tone="warning"
                   :title="`${needsHandoverCount} of your patients have no handover today`" class="mb-4">
            <Link href="/patients?needs_handover=1" class="font-semibold underline">Show them</Link>
        </FlowAlert>
```

4. Add a filter chip beside the existing board filters:

```html
            <Link href="/patients?needs_handover=1" class="rounded-full px-3 py-1 text-xs font-semibold"
                  :class="$page.props.filters?.needs_handover ? 'bg-tint-warning text-on-warning' : 'border border-ink-200 text-ink-500 hover:bg-ink-50'">
                Needs handover ({{ needsHandoverCount }})
            </Link>
```

(Match the surrounding filter markup — read it first and mirror its element/classes rather than pasting this verbatim if the existing chips use `<button>` + `router.get`.)

- [ ] **Step 5: Inbox third tab**

In `Handovers/Index.vue`: accept `needsHandover: { type: Array, default: () => [] }`, add the tab button next to the existing two (which follow the pattern `tab === 'x' ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'`):

```html
                <button @click="tab = 'needs'" class="rounded-lg px-4 py-2 text-sm font-semibold transition" :class="tab === 'needs' ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">Needs handover ({{ needsHandover.length }})</button>
```

and a matching `v-show="tab === 'needs'"` panel — an `overflow-x-auto`-wrapped table with columns Patient (+MRN), Bed, Consultant, Last updated, and a Write action linking to `/patients?highlight={{ row.admission_id }}`, rendering `<CheckpointChips :checkpoints="row.checkpoints" />` per row (already imported in this file). Extend the subtitle ternary at the top to cover the third tab.

- [ ] **Step 6: Run → PASS**

Run: `cd laravel && npx vitest run resources/js/Pages/Patients/__tests__/Index.spec.js resources/js/Pages/Handovers/__tests__/Index.spec.js`

- [ ] **Step 7: Build + gates + commit**

```bash
cd laravel && npx vite build
node scripts/check-source-allowlist.mjs --write && node scripts/check-source-allowlist.mjs && node scripts/contrast.mjs
git add -A
git commit -m "feat(handover): dashboard tile, board filter + pinned banner, inbox Needs-handover tab"
```

---

## Task 10: Full verification + ship

- [ ] **Step 1:** `cd laravel && npx vite build`
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

- **Spec coverage:** §A→T1–T3 · §B capture→T4–T6 · §B acknowledgement→T7 · §C surfaces→T8–T9 · §D falls out of T8–T9 · §E tests are embedded in every task + T10.
- **No schema change** anywhere in this plan. If you find yourself writing a migration, stop — the definition is derived from `handovers.updated_at`.
- **The `(string)` cast** on `payload->admission_id` in Task 1 is load-bearing (a raw int silently matches nothing). Do not remove it.
- **Naming is consistent across tasks:** `raiseIncompleteHandoverReminders` (T1–T3), `HandoverCapture` with props `body`/`checkpoints`/`density`/`today`/`updatedAt`/`revisions`/`label` and events `update:body`/`update:checkpoints` (T4–T7), `needsHandoverToday()` scope + `needsHandoverCount` / `handoverDue` / `handoverDueUnit` / `needsHandover` props (T8–T9).
- **Do not add an "assign without handover" button** anywhere — the owner explicitly rejected a visible bypass; Task 7's dialog is the only way past.
- **The banner is permanently pinned** while the count is above zero (owner's decision, made against my recommendation to auto-clear it) and always uses the **personal** count, even for admins.
