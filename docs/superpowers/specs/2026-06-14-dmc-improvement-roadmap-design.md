# DMC Patient-Flow — Forward-Looking Improvement Roadmap (Design Spec)

> **For agentic workers:** this is the consolidated design for a 5-phase improvement program.
> Each phase is built as its own wave (TDD, suite green, committed, pushed) with a checkpoint
> between phases. Source of the ideas: a 7-dimension read-only code review (2026-06-14).

**Goal:** Make the (already feature-complete, legacy-parity) DMC Laravel app *more useful to clinicians and admins* — turning correct-but-passive data into actionable workflow, surfacing the audit trail that is already captured, deepening reporting, and hardening security/data-integrity — without regressing any shipped behavior.

**Status:** Roadmap approved by owner ("build the whole roadmap", 2026-06-14). Phases run in order 0 → 4. PDPL data-subject tooling + PHI-column encryption-at-rest are explicitly deferred to a later **Phase 5** (largest/riskiest) and are NOT designed here.

**Tech stack / invariants (apply to every phase):** Laravel 13 + Inertia 2 + Vue 3 `<script setup>` + Tailwind v4 (tokens: `bg-card`/`bg-app`/`ring-line`/`text-ink-*`/brand·accent·info·warning·danger scales/`.font-display`/`.nums`), ApexCharts via `useChartTheme`, MySQL(dev)/MariaDB 10.11(prod), dompdf + `AppSupportReportSvg`, openspout for .xlsx. PHPUnit Feature tests against real MySQL `dmc_test` (RefreshDatabase, `withoutVite()`); `public/build` committed (rebuild + commit every wave).

---

## Cross-cutting rules (non-negotiable, every phase)

1. **Authorization stays server-side.** Roles Admin(0)/Registrar(2)/Consultant(3)/Resident(4)/Observer(5=read-only) + capability flags can_add/can_assign/can_manage/can_modify. Observers never write regardless of flags.
2. **Single source of truth for metrics** lives on `app/Models/Admission.php` (`NON_ICU_SQL`, `REAL_DISCHARGE_TYPES`, `readmissionJoin()`, `losBand()`, `bucketizeDestinations()`, `DEST_BUCKETS`). New metrics are added there and pinned by **value tests** (independent SQL vs displayed).
3. **Every state change**: in a DB transaction, session-sourced attribution (never client-supplied), and an `audit_log` row (via the Phase-0 centralized writer once it exists).
4. **No regressions**: full suite green + `npm run build` clean before each commit; commit per logical batch; push per wave.
5. **Defaults over blocking**: where a clinical choice exists, ship a safe admin-tunable default and flag it for clinician confirmation rather than stalling.

## Clinical defaults chosen (admin-tunable unless noted) — for clinician review

| Setting | Default | Where |
|---|---|---|
| Boarding = discharge-delayed | `discharge_date IS NULL AND medical_discharge_date IS NOT NULL`; delay = `DATEDIFF(today, medical_discharge_date)` | Phase 1 |
| Over-census alert | ward census ≥ `ward_beds` (100%) | Phase 1 |
| Boarding alert threshold | > 3 patients | Phase 1 (Setting) |
| Readmission alert | existing `readmission_window_days` count above a tunable N | Phase 1 (Setting) |
| Idle session timeout | 30 min, absolute cap 12h | Phase 4 (Setting) |
| Soft-delete retention | keep + manual restore, **no** auto-purge | Phase 4 |
| Stale-episode flag (data-quality) | active non-longterm LOS > `long_los × 3` | Phase 4 (derived) |
| PHI-read logging | log registry search + exports; per-record opens off by default | Phase 2 (Setting) |
| Monthly report email | 1st of month, prior month, recipients in settings | Phase 3 |

---

## Phase 0 — Quick wins & foundations

This phase delivers eight independent enablers and two UX refinements that have no server-side migrations and no schema changes. All items are pure frontend or lightweight PHP-class additions. The phase can be parallelised into two tracks: frontend components (items 1–3, 9–10) and PHP refactors (items 4–6), with items 7 and 8 as pure self-contained additions to existing files. Completing this phase unblocks Phase 1 (statistics rework, server-side PDF, MFA hardening) by establishing the audit writer, the `canManage` rule, and the readmission subquery as single sources of truth.

---

### Item 1 — Themed accessible confirm dialog (effort M, risk Low)

**Goal / user value**
Replace the ~16 `window.confirm()` calls that produce un-themed, inaccessible browser alerts with a modal that shows the patient name/MRN, a consequence sentence, and a danger/neutral tone—making destructive actions feel deliberate and identifiable on any device.

**Files**
- CREATE `laravel/resources/js/Components/ConfirmDialog.vue`
- CREATE `laravel/resources/js/composables/useConfirm.js`
- MODIFY `laravel/resources/js/Pages/Patients/Index.vue` (lines 67, 199, 225, 239)
- MODIFY `laravel/resources/js/Pages/Admissions/Index.vue` (lines 28, 48, 85)
- MODIFY `laravel/resources/js/Pages/Consultations/Index.vue` (lines 68, 77–79)
- MODIFY `laravel/resources/js/Pages/Handovers/Index.vue` (line 29)
- MODIFY `laravel/resources/js/Pages/Recent/Index.vue` (lines 9–10)
- MODIFY `laravel/resources/js/Pages/Control/Index.vue` (lines 54, 57–58)

**Design**

`useConfirm.js` — module-level singleton, returns one async function:
```js
// composables/useConfirm.js
import { ref } from 'vue';
const state = ref(null);   // null | { title, body, tone, resolve }
export function useConfirm() {
    const ask = (title, body, tone = 'danger') =>
        new Promise((resolve) => { state.value = { title, body, tone, resolve }; });
    const answer = (ok) => { state.value?.resolve(ok); state.value = null; };
    return { state, ask, answer };
}
```

`ConfirmDialog.vue` — consumes `useConfirm()` singleton state. Props: none (reads module state). The component mounts into `AppLayout.vue` once (teleport to `<body>`).

Structure: `<Teleport to="body"><div role="dialog" aria-modal="true" aria-labelledby="cd-title" ...>`. Focus trap via `onMounted` → `firstFocusable.focus()`; `keydown` listener captures `Tab`/`Shift+Tab` to cycle within the dialog, `Escape` maps to `answer(false)`. Backdrop click also calls `answer(false)`. On `answer(true)`, the caller's promise resolves; the caller then performs the router action.

Tone variants:
- `danger`: header `bg-danger-100 text-danger-600`, confirm button `bg-danger-600 hover:bg-danger-700 text-white`
- `neutral`: header `bg-ink-50 text-ink-700`, confirm button `bg-brand-600 hover:bg-brand-700 text-white`

The dialog always renders a `<h3 id="cd-title">` (the title), a `<p>` (the consequence body showing patient name + MRN from the `body` string), Cancel button (secondary, always first tab stop), then the Confirm button (primary, last tab stop). Returns focus to the opener element (caller stores `document.activeElement` before calling `ask`).

Call pattern replacing `window.confirm`:
```js
// OLD: if (confirm(`Delete...`)) router.delete(...)
// NEW:
const { ask } = useConfirm();
const destroyAdmission = async (row) => {
    if (await ask('Delete admission',
        `Permanently remove the episode for ${row.name} (MRN ${row.mrn}) and its diagnoses.`))
        router.delete(`/admissions/${row.id}`, { preserveScroll: true });
};
```

For `shuffle` and `undoMedical`, tone is `'neutral'`.
For `destroyAdmission`, `deleteConsult`, `deleteUser`, `undoDischarge`, `undoSignoff`, tone is `'danger'`.
For `signoff`, `fromIcu`, `signAll`, tone is `'neutral'`.
For `confirmIdentity` (identity-change gate, `Patients/Index.vue:225`), tone is `'danger'` with title `'Change patient identity'`.
For `resetMfa` (`Control/Index.vue:54`), tone is `'neutral'`.

Register `<ConfirmDialog />` once in `AppLayout.vue` template, below the flash toast.

**Decisions & defaults**
- Singleton state (not prop-drilled) avoids threading the dialog through every page; the module is small and tree-shaken if unused.
- `Teleport to="body"` avoids z-index conflicts with existing `z-50` modals.
- Focus returns to opener even when the page re-renders on Inertia navigation; store the ref before the `await ask(...)`.
- "Cancel" always resolves `false`; no timer auto-close (clinical confirmations must be explicit).

**Test plan**
- Vitest: `useConfirm.test.js` — `ask()` returns a Promise; calling `answer(true)` resolves it with `true`; calling `answer(false)` resolves with `false`; calling `ask()` twice replaces the first (no stacking).
- Vitest: `ConfirmDialog.test.js` — renders with `role="dialog"` and `aria-modal="true"`; Escape emits `answer(false)`; backdrop click emits `answer(false)`; Tab cycles within the two buttons only; `aria-labelledby` points to the `h3`.
- No Feature (PHP) test needed — pure frontend.

**Build sequence**
1. Write failing `useConfirm.test.js` asserting Promise resolve behavior.
2. Implement `useConfirm.js` → green.
3. Write failing `ConfirmDialog.test.js` asserting ARIA attributes + focus trap + Escape.
4. Implement `ConfirmDialog.vue` → green.
5. Register `<ConfirmDialog />` in `AppLayout.vue`.
6. Replace each `window.confirm` call page-by-page (Patients, Admissions, Consultations, Handovers, Recent, Control). One commit per page file.
7. Run full Vitest suite. Build assets + commit.

---

### Item 2 — Mobile fixes: table overflow + touch targets (effort S, risk Low)

**Goal / user value**
The Consultations table and the Patients per-consultant summary table overflow their container on small screens, causing horizontal scroll of the full page. Card action buttons at 28px (h-7 w-7) are below the 44px WCAG 2.5.8 minimum for touch targets; rare actions (Delete, Long-term, Undo) should collapse to a kebab on coarse-pointer devices.

**Files**
- MODIFY `laravel/resources/js/Pages/Consultations/Index.vue` (line 109 wrapper)
- MODIFY `laravel/resources/js/Pages/Patients/Index.vue` (line 286 summary table wrapper, lines 377–391 card action row)

**Design**

**Consultations table** (`Consultations/Index.vue:109`):
Wrap the existing `<div class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">` with `<div class="overflow-x-auto">`. Apply `min-w-[640px]` to the inner `<table>` so it scrolls horizontally on small screens without the containing card losing its border-radius. The outer card div keeps its rounded + shadow; the inner overflow-x is contained.

```html
<!-- replace line 109 -->
<div class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
  <div class="overflow-x-auto">
    <table class="min-w-[640px] w-full text-sm">
```

**Patients summary table** (`Patients/Index.vue:286–312`):
Wrap the existing `<div class="mb-5 overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">` with an `overflow-x-auto` child div and give the table `min-w-[540px]`:

```html
<div class="mb-5 overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
  <div class="overflow-x-auto">
    <table class="min-w-[540px] w-full text-sm">
```

**Card action buttons** (`Patients/Index.vue:377–391`): The action row `<div class="flex gap-1 border-t border-ink-50 px-2 ...">` contains up to 8 icon buttons at `h-7 w-7` (28px). On touch devices this is too small and the row becomes cluttered.

Strategy: on `@media (pointer: coarse)` lift all buttons to `h-10 w-10` (40px) and reduce gap to `gap-0.5`. Collapse the three low-frequency actions (Delete `destroyAdmission`, Long-term toggle `longterm`, Undo medical discharge `undoMedical`) into a kebab menu that appears as a single `h-10 w-10` button, revealing a small dropdown of those three actions.

Implementation:
- Add a per-card `kebabOpen` ref keyed by admission `id`: `const kebabOpen = ref(null)`.
- In the card action row, wrap the three rare-action buttons in a single `<div class="relative">` containing a kebab trigger button and a conditional `<div>` dropdown (positioned absolute, `z-10`, `bg-card ring-1 ring-line rounded-xl shadow-lg`). Only show the kebab group when `@media (pointer: coarse)` — use a Tailwind custom variant in `app.css`:

```css
@custom-variant coarse (@media (pointer: coarse));
```

Then apply: `class="hidden coarse:block"` on the kebab wrapper, `class="coarse:hidden"` on each of the three individual buttons.

The five primary buttons (assign, modify, transfer, discharge/ICU-discharge, complete) keep their individual rendering on all screen sizes but gain `h-7 w-7 coarse:h-10 coarse:w-10` classes.

Closing the kebab: `@click.outside` directive on the kebab wrapper (or a transparent backdrop overlay), plus `Escape` key.

**Decisions & defaults**
- `overflow-x-auto` on a child inside the rounded card preserves the card's visual border-radius on small screens.
- The kebab-for-coarse approach keeps the desktop layout identical — zero regression. Mobile gets a cleaner row.
- `coarse:` custom variant requires one line in `app.css`; no extra plugin.

**Test plan**
- Vitest: render the card action row in a jsdom environment; assert all three primary action buttons are present; assert kebab trigger is present. (Pointer-coarse CSS is not testable in jsdom — document the limitation.)
- Smoke: a real device/browser check at 375px viewport that the Consultations table scrolls horizontally within its card, not the whole page.

**Build sequence**
1. Add `@custom-variant coarse` to `app.css`.
2. Wrap Consultations table — rebuild + verify no visual regression.
3. Wrap Patients summary table — rebuild.
4. Add `kebabOpen` ref and kebab dropdown in Patients card action row.
5. Lift button sizes with `coarse:h-10 coarse:w-10`.
6. Write Vitest smoke for card action button presence.
7. Build assets + commit.

---

### Item 3 — Accessibility: modals, live regions, skip link, drawer (effort M, risk Low)

**Goal / user value**
The current modals have no `role`, no `aria-modal`, no focus management, and no return-focus. The flash toast has no `role`. There is no skip-to-content link and the mobile drawer has no focus trap. Screen reader users and keyboard-only users cannot meaningfully navigate clinical workflows.

**Files**
- CREATE `laravel/resources/js/composables/useModalA11y.js`
- MODIFY `laravel/resources/js/Layouts/AppLayout.vue` (skip link, toast role, drawer focus, bell dropdown)
- MODIFY `laravel/resources/js/Pages/Patients/Index.vue` (all 4 modals: action, reassign, handover, modify)
- MODIFY `laravel/resources/js/Pages/Admissions/Index.vue` (3 modals: assign, ICU, modify)
- MODIFY `laravel/resources/js/Pages/Consultations/Index.vue` (2 modals: new, edit)
- MODIFY `laravel/resources/js/Pages/Handovers/Index.vue` (no modal; add live region for signature count)

**Design**

`useModalA11y.js`:
```js
// Returns { trapRef, onOpen, onClose } to spread onto a modal root element.
// onOpen(openerEl): saves opener, focuses first focusable inside trapRef.
// onClose(): returns focus to saved opener.
// Tab/Shift+Tab cycle: handled by keydown on the modal container.
export function useModalA11y() {
    const trapRef = ref(null);
    let opener = null;
    const focusableSelectors = 'button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
    const getFocusable = () => [...(trapRef.value?.querySelectorAll(focusableSelectors) ?? [])];
    const onOpen = (el) => {
        opener = el ?? document.activeElement;
        nextTick(() => { const items = getFocusable(); items[0]?.focus(); });
    };
    const onClose = () => { opener?.focus(); opener = null; };
    const onKeydown = (e) => {
        if (e.key === 'Tab') {
            const items = getFocusable();
            if (!items.length) return;
            const first = items[0], last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    };
    return { trapRef, onOpen, onClose, onKeydown };
}
```

Usage in each page modal: the existing `<div v-if="modal" class="fixed inset-0 z-50 ...">` gets:
- `ref="trapRef"` on the inner white panel `<div class="... rounded-2xl bg-card ...">` (the focusable container, not the backdrop)
- `role="dialog"` `aria-modal="true"` `aria-labelledby="modal-title-{uniqueId}"` on the inner panel
- The inner `<h3>` gets `:id="'modal-title-' + uniqueId"` (use a static string per modal, e.g. `modal-title-action`, `modal-title-reassign`)
- `@keydown="onKeydown"` on the inner panel

Each `openModal(mode, row)` call receives `onOpen(event.currentTarget ?? event.target)` where `event` is passed through the button `@click`. The `onEsc` handler calls `onClose()` before nulling the modal ref. Each Cancel button calls `closeModal` which also calls `onClose()`.

For pages with multiple modal types, use one `useModalA11y()` instance per logical modal slot (action modal, reassign modal, handover modal, modify modal in `Patients/Index.vue`).

**Skip link** (`AppLayout.vue`): Add as the very first child of the root `<div class="min-h-full">`:
```html
<a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[100] focus:rounded-xl focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">
    Skip to content
</a>
```
Add `id="main-content"` to `<main class="p-5 lg:p-7">`.

**Flash toast** (`AppLayout.vue:255–263`): Add `role="status"` and `aria-live="polite"` to the toast `<div>`. The error variant should use `role="alert"` `aria-live="assertive"`. Change the static class binding:
```html
<div v-if="toast"
    :role="toast.type === 'error' ? 'alert' : 'status'"
    :aria-live="toast.type === 'error' ? 'assertive' : 'polite'"
    class="fixed bottom-6 right-6 z-50 ...">
```

**Notification bell dropdown** (`AppLayout.vue:208–229`): The dropdown `<div v-if="bellOpen">` currently has no ARIA. Add `role="dialog"` `aria-label="Notifications"` to the dropdown panel. The bell button gets `aria-expanded="bellOpen"` `aria-controls="notifications-panel"` and the panel gets `id="notifications-panel"`. Focus the first notification item (or the "Handover inbox" link) on open: add `@vue:mounted="focusFirst"` where `focusFirst` is `el => el.querySelector('button,a')?.focus()`.

**Mobile drawer** (`AppLayout.vue`): The `<aside>` sidebar is the drawer. On open (`sidebarOpen = true`), focus the first nav link inside it. On close, return focus to the hamburger button. Trap Tab within the aside while it is open on mobile (`:class="{ 'translate-x-0': sidebarOpen }"`). Add `aria-expanded="sidebarOpen"` to the hamburger button and `aria-hidden="!sidebarOpen"` on the aside on mobile (the `lg:translate-x-0` always-visible sidebar should not have `aria-hidden`).

**Result-count announcements**: In `Patients/Index.vue`, add an `aria-live="polite"` visually-hidden span that announces when the filtered results change:
```html
<span class="sr-only" aria-live="polite" aria-atomic="true">
    {{ groups.length ? `${groups.length} consultant group(s) shown` : 'No results' }}
</span>
```
Similarly in `Consultations/Index.vue` after the table for `consultations.total`.

**Decisions & defaults**
- `useModalA11y` is a composable (not a wrapper component) so existing modal markup doesn't need restructuring — only attribute additions.
- The `sr-only` utility already exists in Tailwind v4 (`@layer utilities { .sr-only {...} }`); verify it is defined in `app.css`. If not, add it to the base layer.
- One focus trap instance per modal slot is cleaner than a single global trap that must be context-switched.

**Test plan**
- Vitest: `useModalA11y.test.js` — `onOpen` focuses first focusable; Tab on last focusable wraps to first; Shift+Tab on first wraps to last; `onClose` calls `focus()` on the stored opener.
- Vitest: `AppLayout` render — toast renders with `role="status"`, error toast renders with `role="alert"`.
- Manual: navigate to `Patients/Index.vue` with keyboard only; open an action modal; confirm focus lands on the first field; confirm Escape returns focus to the triggering button.

**Build sequence**
1. Write failing `useModalA11y.test.js`.
2. Implement `useModalA11y.js` → green.
3. Add skip link + `id="main-content"` to `AppLayout.vue`.
4. Add `role`/`aria-live` to flash toast.
5. Add bell dropdown ARIA + focus-on-open.
6. Add mobile drawer focus management.
7. Apply `useModalA11y` to each modal in Patients/Index.vue (4 modals).
8. Apply to Admissions/Index.vue (3 modals).
9. Apply to Consultations/Index.vue (2 modals).
10. Add result-count live regions to Patients and Consultations.
11. Build + commit.

---

### Item 4 — Centralized audit writer (effort S, risk Low)

**Goal / user value**
`AuditLog::create([...])` appears at 21 callsites across 8 controllers, with two near-identical private `audit()` methods in `PatientActionController` and `HandoverController`. Any future change to the audit schema (adding a `session_id` column, changing IP resolution) requires editing 8 files. The centralised writer ensures consistent attribution and is the enabler for all Phase 2 audit assertions.

**Files**
- CREATE `laravel/app/Support/Audit.php`
- MODIFY `laravel/app/Http/Controllers/PatientActionController.php` — remove `private function audit()`, replace all `AuditLog::create([...])` calls
- MODIFY `laravel/app/Http/Controllers/HandoverController.php` — same
- MODIFY `laravel/app/Http/Controllers/AdmissionsController.php`, `ConsultationsController.php`, `ControlController.php`, `ImportController.php`, `RegistryController.php`, `RecentController.php` — replace inline `AuditLog::create([...])` calls

**Design**

`App\Support\Audit`:
```php
namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

final class Audit
{
    /**
     * Write one audit row. Actor identity and IP always come from the session/request;
     * the caller supplies what happened and to what.
     *
     * @param string      $action     e.g. 'admission.discharge', 'user.delete'
     * @param string      $entityType e.g. 'admission', 'consultation', 'user'
     * @param string|null $entityId   string-cast PK, or null for bulk/system actions
     * @param array       $details    arbitrary JSON context (before/after values, etc.)
     */
    public static function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        array $details = []
    ): void {
        AuditLog::create([
            'actor_id'    => Auth::id(),
            'actor_name'  => Auth::user()?->name,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'details'     => $details,
            'ip'          => Request::ip(),
        ]);
    }
}
```

Replacement pattern in each controller — for example in `PatientActionController`:
```php
// BEFORE
AuditLog::create([
    'actor_id' => Auth::id(), 'actor_name' => Auth::user()->name,
    'action' => $action, 'entity_type' => 'admission', 'entity_id' => (string) $a->id,
    'details' => $details, 'ip' => request()->ip(),
]);

// AFTER
\App\Support\Audit::log($action, 'admission', (string) $a->id, $details);
```

The two private `audit()` wrapper methods in `PatientActionController` and `HandoverController` are deleted. Their callers (`$this->audit(...)`) become `Audit::log(...)` with the entity type/id inlined.

No import alias needed for the first pass; add `use App\Support\Audit;` at the top of each modified controller.

This change is behavior-preserving: the written rows are identical. No migration needed.

**Decisions & defaults**
- `final class` with a `static` method — no DI container needed; consistent with the existing `Totp` and `ReportSvg` support classes.
- `Auth::user()?->name` (nullable) is safe even in queue jobs if they are ever added later.
- `Request::ip()` via facade (not `request()->ip()`) so it resolves correctly in test environments where `app()->request` is the right binding.

**Test plan**
- Feature: `AuditWriterTest` — `Audit::log('test.action', 'admission', '42', ['k' => 'v'])` while acting as a user creates one `audit_log` row with all five fields matching. `actor_id` matches `Auth::id()`. `details` JSON contains `k`.
- All existing audit-row assertions in `AuthorizationTest`, `ClinicalFlowTest`, `HandoverTest`, `WaveBTest` etc. continue to pass unmodified (behavior-preserving requirement).

**Build sequence**
1. Write failing `AuditWriterTest`.
2. Create `Audit.php` → green.
3. Replace private `audit()` in `PatientActionController` → run existing tests.
4. Replace private `audit()` in `HandoverController` → run.
5. Replace `AuditLog::create` in remaining 6 controllers one-by-one, running tests after each.
6. Commit.

---

### Item 5 — Unify readmission subquery (effort S, risk Low)

**Goal / user value**
The whereExists readmission check is written three times by hand: `PatientsController::boardGroups` (~line 138), `RegistryController::admissionResults` (~line 164), and `RegistryController::admissionQuery` (~line 118). A definition drift between them (e.g. someone adds a new real-discharge type) would silently produce inconsistent badge/filter results. `Admission::readmissionJoin()` already exists as the canonical join form; the missing piece is a `whereExists` scope that uses it.

**Files**
- MODIFY `laravel/app/Models/Admission.php` — add `scopeReadmission` and `readmissionExists`
- MODIFY `laravel/app/Http/Controllers/PatientsController.php` (~line 138)
- MODIFY `laravel/app/Http/Controllers/RegistryController.php` (~lines 118, 164)

**Design**

Add to `Admission` model, immediately after `readmissionJoin()`:
```php
/**
 * Scope: only admissions that are readmissions within $window days.
 * Uses REAL_DISCHARGE_TYPES + readmissionJoin — single source of truth.
 * $alias must be a unique table alias for "prev" when used inside a nested sub-select.
 */
public static function readmissionExists(int $window, string $alias = 'prev'): \Closure
{
    return fn ($s) => $s->selectRaw('1')
        ->from("admissions as {$alias}")
        ->whereColumn("{$alias}.patient_id", 'admissions.patient_id')
        ->whereColumn("{$alias}.id", '<>', 'admissions.id')
        ->whereColumn("{$alias}.discharge_date", '<=', 'admissions.admit_date')
        ->whereRaw("DATEDIFF(admissions.admit_date, {$alias}.discharge_date) BETWEEN 0 AND ?", [$window])
        ->where(fn ($w) => $w->whereIn("{$alias}.transfer_type", self::REAL_DISCHARGE_TYPES)
            ->orWhereNull("{$alias}.transfer_type"));
}
```

This is the `whereExists(fn($s) => ...)` inner closure, consistent with the existing `readmissionJoin()` which is the JOIN form used by Statistics/Reports.

Replacement in `PatientsController::boardGroups` (~line 138):
```php
// BEFORE: 8-line inline whereExists block
$readmitIds = Admission::query()->whereIn('id', $admissions->pluck('id'))
    ->whereExists(fn ($s) => $s->selectRaw('1')->from('admissions as prev')
        ->whereColumn('prev.patient_id', 'admissions.patient_id')...
    ->pluck('id')->flip();

// AFTER:
$readmitIds = Admission::query()->whereIn('id', $admissions->pluck('id'))
    ->whereExists(Admission::readmissionExists($readmitWindow))
    ->pluck('id')->flip();
```

Replacement in `RegistryController::admissionResults` (~line 164):
```php
$readmitIds = $ids->isEmpty() ? collect() : Admission::whereIn('id', $ids)
    ->whereExists(Admission::readmissionExists($window))
    ->pluck('id')->flip();
```

Replacement in `RegistryController::admissionQuery` (~line 118):
```php
->when($request->boolean('readmit72'), fn ($q) => $q->whereExists(
    Admission::readmissionExists(
        max(0, (int) (\App\Models\Setting::current()->readmission_window_days ?? 3))
    )
))
```

**Decisions & defaults**
- The `$alias` parameter defaults to `'prev'` to match all three existing uses exactly. A second alias is only needed if two readmission subqueries appear in the same query.
- `readmissionExists` returns a closure (not a scope method) so it can be passed to `whereExists()` directly — consistent with how `tbExists` is constructed in `PatientsController`.

**Test plan**
- All existing tests that assert readmission behavior pass unchanged: `Round11N1Test` (board badge), `StatisticsValueTest` (KPI readmission %), `GapWave2Test` (registry filter). These are the guards — no new tests needed beyond a basic unit assertion.
- ADD: `Round11N1Test::test_readmission_exists_matches_board_badge` — create patient with a prior discharge within window; confirm `Admission::whereExists(Admission::readmissionExists($window))->whereIn('id', [$admissionId])->exists()` returns true. Create one outside window; confirm false.

**Build sequence**
1. Add `readmissionExists` to `Admission.php`.
2. Write the value test asserting it returns true/false correctly → green.
3. Replace `PatientsController` → run `Round11N1Test`.
4. Replace `RegistryController::admissionResults` → run `StatisticsValueTest`.
5. Replace `RegistryController::admissionQuery` → run `GapWave2Test`.
6. Commit.

---

### Item 6 — Unify canManage (effort S, risk Low)

**Goal / user value**
The `canManage(Admission $a): bool` rule (admin OR `can_manage` OR primary consultant, never observer) is duplicated as identical private methods in `PatientActionController` (~line 47) and `HandoverController` (~line 35), and as an inline closure in `ConsultationsController::signoff` (~line 109). A single method on `User` eliminates the risk of the two controller copies drifting.

**Files**
- MODIFY `laravel/app/Models/User.php` — add `canManageAdmission(Admission $a): bool`
- MODIFY `laravel/app/Http/Controllers/PatientActionController.php` — delete `private function canManage`, delegate to `User`
- MODIFY `laravel/app/Http/Controllers/HandoverController.php` — delete `private function canManage`, delegate to `User`
- MODIFY `laravel/app/Http/Controllers/ConsultationsController.php` — replace inline signoff check

**Design**

Add to `User.php` after `isObserver()`:
```php
/**
 * A user may manage (discharge/transfer/sign-off/edit) an admission when they are:
 *   - the admin, OR
 *   - have the can_manage capability flag, OR
 *   - are the primary consultant on the admission.
 * Observers are globally read-only and are rejected before the other conditions are checked.
 */
public function canManageAdmission(Admission $a): bool
{
    if ($this->isObserver()) {
        return false;
    }
    return $this->isAdmin() || $this->can_manage || (int) $a->consultant_id === (int) $this->id;
}
```

In `PatientActionController`, delete the `private function canManage()` method. Replace its call sites:
```php
// BEFORE: if (! $this->canManage($admission)) { throw new AccessDeniedHttpException(...); }
// AFTER:
if (! Auth::user()->canManageAdmission($admission)) {
    throw new AccessDeniedHttpException('You are not authorized to perform this action.');
}
```

Same for `HandoverController`.

In `ConsultationsController::signoff`, the inline check is:
```php
// approximate current form at ~line 109 (signoff method)
$u = Auth::user();
if ($u->isObserver() || (! $u->isAdmin() && ! $u->can_manage && $consultation->consultant_id !== $u->id)) {
    throw new AccessDeniedHttpException(...);
}
```
The consultation signoff rule is CONSULTANT-centric, not admission-centric. Introduce a parallel `canManageConsultation(Consultation $c): bool` on `User` using the same Observer-first pattern:
```php
public function canManageConsultation(\App\Models\Consultation $c): bool
{
    if ($this->isObserver()) { return false; }
    return $this->isAdmin() || $this->can_manage || (int) $c->consultant_id === (int) $this->id;
}
```
Replace the inline check with `Auth::user()->canManageConsultation($consultation)`.

**Decisions & defaults**
- Placing the rule on `User` makes it testable without a controller request. The method signature takes the model directly (not an ID) so type safety is enforced at call sites.
- The observer guard is always first — this is load-bearing (capability flags must never override it per J1-9).

**Test plan**
- Extend `AuthorizationTest`: `test_observer_canManageAdmission_returns_false_regardless_of_can_manage` — create observer with `can_manage=true`; assert `$user->canManageAdmission($admission)` is false.
- `test_primary_consultant_canManageAdmission_returns_true` — non-admin, no can_manage; admission `consultant_id = $user->id`; assert true.
- `test_other_consultant_without_flag_returns_false` — different consultant_id, no flag; assert false.
- All existing action-authorization tests in `AuthorizationTest`, `ClinicalFlowTest`, `WaveBTest` continue to pass.

**Build sequence**
1. Write failing `canManageAdmission` assertions in `AuthorizationTest`.
2. Add `canManageAdmission` + `canManageConsultation` to `User.php` → green.
3. Delete `private function canManage` from `PatientActionController`; replace calls → run tests.
4. Delete `private function canManage` from `HandoverController`; replace calls → run tests.
5. Replace inline signoff check in `ConsultationsController` → run tests.
6. Commit.

---

### Item 7 — Dashboard perf: consultations loop → GROUP BY (effort S, risk Low)

**Goal / user value**
`DashboardController::index` lines 70–77 run a 6-iteration PHP loop that fires 2 DB queries per month (12 queries total) to build the consultations-vs-sign-offs 6-month bar chart. The admissions trend uses a single `GROUP BY` + PHP zip pattern (lines 58–66) that costs 2 queries regardless of range. Apply the same pattern to consultations.

**Files**
- MODIFY `laravel/app/Http/Controllers/DashboardController.php` (lines 68–77)

**Design**

Replace the loop with two `DATE_FORMAT(%Y-%m)` GROUP BY queries and a PHP zip:
```php
// consultations vs sign-offs — 6 calendar months including current, 2 queries total
$sixMonthsAgo = Carbon::today()->startOfMonth()->subMonths(5)->toDateString();
$consByMonth = DB::table('consultations')
    ->selectRaw("DATE_FORMAT(consultation_date, '%Y-%m') ym, COUNT(*) c")
    ->where('consultation_date', '>=', $sixMonthsAgo)
    ->groupBy('ym')->pluck('c', 'ym');
$signedByMonth = DB::table('consultations')
    ->selectRaw("DATE_FORMAT(signoff_date, '%Y-%m') ym, COUNT(*) c")
    ->where('signoff_date', '>=', $sixMonthsAgo)
    ->groupBy('ym')->pluck('c', 'ym');

$cons = ['labels' => [], 'new' => [], 'signed' => []];
for ($i = 5; $i >= 0; $i--) {
    $m = Carbon::today()->subMonths($i);
    $ym = $m->format('Y-m');
    $cons['labels'][] = $m->format('M');
    $cons['new'][]    = (int) ($consByMonth[$ym] ?? 0);
    $cons['signed'][] = (int) ($signedByMonth[$ym] ?? 0);
}
```

This reduces 12 queries to 2. The output shape (`$cons`) is identical; the Vue layer (`Dashboard.vue`) is untouched. The `DATE_FORMAT` aggregation is the same pattern as `DashboardController`'s existing trend block uses `GROUP BY admit_date`.

**Decisions & defaults**
- `>=` on `DATE_FORMAT(consultation_date, '%Y-%m') >= sixMonthsAgo` would be string comparison — safer to filter by the raw date column with `>=` and let GROUP BY bucket into months.
- `startOfMonth()->subMonths(5)` gives months M-5 through M (current month), 6 labels total, matching the legacy 6-iteration loop.

**Test plan**
- ADD `DashboardValueTest::test_consultations_chart_matches_db`:
  - Create 3 consultations this month, 2 last month, 1 signed off last month.
  - Assert the Inertia response at `/` has `consults.new[5] == 3` (current month), `consults.new[4] == 2`, `consults.signed[4] == 1`.
  - This is a value-pinning test following the `StatisticsValueTest` pattern.
- Existing dashboard smoke test (`AuthorizationTest::test_admin_dashboard_renders`) must still pass.

**Build sequence**
1. Write failing `DashboardValueTest::test_consultations_chart_matches_db`.
2. Replace the loop in `DashboardController` → green.
3. Run full Feature suite.
4. Commit.

---

### Item 8 — ShuffleService tests: TB asymmetry, ICU-only, overflow (effort S, risk Low)

**Goal / user value**
`ShuffleServiceTest.php` covers balanced-fill and ICU-exclusion but misses three clinically significant edge cases: TB patients raising subspecialist load (but not hospitalist load), an ICU-only consultant having zero ward-load for shuffle purposes, and the round-5 overflow (a patient that exceeds every consultant's max falls through to the catch-all).

**Files**
- MODIFY `laravel/tests/Feature/ShuffleServiceTest.php` — add 3 test methods, reuse existing helpers

**Design**

The existing test helpers (lines 20–43 of `ShuffleServiceTest.php`) provide `consultant()`, `admission()`, and `settings()`. The new tests build on exactly those helpers.

`test_tb_patient_raises_subspecialist_load_not_hospitalist`:
- Create hospitalist A (specialty=1) and subspecialist B (specialty=2), both on service.
- `settings(['min_hospitalist' => 2, 'max_hospitalist' => 5, 'min_subs' => 2, 'max_subs' => 5])`.
- Assign 1 existing TB admission to B (so B's ward load = 1).
- Place 1 new unassigned admission.
- Run shuffle.
- Assert the new admission goes to A (hospitalist, ward load = 0), not B (subs, ward load = 1 from the TB patient).
- The key invariant: the ShuffleService counts all active non-ICU patients toward a consultant's load, TB included.

`test_icu_only_consultant_load_is_zero_for_ward_shuffle`:
- Create hospitalist A and hospitalist B, both on service.
- B has 2 existing ICU admissions (current_location = 'ICU', no discharge_date).
- A has 0 patients.
- `settings(['min_hospitalist' => 1, 'max_hospitalist' => 3, 'min_subs' => 0, 'max_subs' => 0])`.
- Place 1 new unassigned ward admission.
- Run shuffle.
- Assert it goes to A (ward load 0) not B (ward load 0 too — ICU excluded — but lower id wins tie): specifically assert B's ward count stays at 0 and A gains 1. Since A and B both have ward load 0, the algorithm assigns to the lower-id consultant; just assert the total unassigned becomes 0 and one of them has 1 new ward assignment.

`test_round5_overflow_assigns_to_least_loaded_when_all_at_max`:
- Create 2 hospitalists, both on service, both already carrying `max_hospitalist` ward admissions.
- `settings(['min_hospitalist' => 1, 'max_hospitalist' => 2, 'min_subs' => 0, 'max_subs' => 0])`.
- Place 1 new unassigned admission.
- Run shuffle.
- Assert `r['assigned'] == 1` (the overflow round assigned it to the least-loaded, even though both are at max).
- Assert `r['skipped'] == 0`.

These tests verify the published ShuffleService behavior: TB patients count toward the ward load (correctly so — they occupy a bed), ICU patients do not (they are managed by ICU staff), and the overflow round ensures no patient is permanently stranded.

**Test plan**
The three tests above ARE the test plan. They use `RefreshDatabase` and the existing helper methods. Run against `dmc_test` MySQL DB per `phpunit.xml`.

**Build sequence**
1. Add `test_tb_patient_raises_subspecialist_load_not_hospitalist` → run → green (confirms existing behavior) or identify a bug to fix first.
2. Add `test_icu_only_consultant_load_is_zero_for_ward_shuffle` → run.
3. Add `test_round5_overflow_assigns_to_least_loaded_when_all_at_max` → run.
4. Commit.

---

### Item 9 — Vitest harness (effort M, risk Low)

**Goal / user value**
There are currently no JavaScript unit tests. Adding Vitest + `@vue/test-utils` establishes the test infrastructure needed for Items 1, 2, 3 above and for all future frontend work. The first meaningful tests cover the two composables and the key computed properties that guard role-based UI visibility.

**Files**
- MODIFY `laravel/package.json` — add dev dependencies + `"test"` script
- CREATE `laravel/vite.config.js` — add Vitest config block (or `laravel/vitest.config.js` if kept separate)
- CREATE `laravel/resources/js/__tests__/useChartTheme.test.js`
- CREATE `laravel/resources/js/__tests__/useConfirm.test.js` (from Item 1)
- CREATE `laravel/resources/js/__tests__/useModalA11y.test.js` (from Item 3)
- CREATE `laravel/resources/js/__tests__/PatientsIndex.computeds.test.js`
- CREATE `laravel/resources/js/__tests__/AppLayout.notifications.test.js`
- MODIFY `laravel/.github/workflows/ci.yml` (if exists) or CREATE `laravel/.github/workflows/vitest.yml`

**Design**

`package.json` additions:
```json
"devDependencies": {
    "@vue/test-utils": "^2.4",
    "jsdom": "^25.0",
    "vitest": "^3.0"
},
"scripts": {
    "test": "vitest run",
    "test:watch": "vitest"
}
```

`vitest.config.js` (separate file to avoid Vite plugin interference):
```js
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';
export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        globals: true,
        alias: { '@': resolve(__dirname, 'resources/js') },
    },
});
```

The `@` alias must match `vite.config.js`'s resolve alias (currently set by `laravel-vite-plugin`; replicate in the Vitest config).

**`useChartTheme.test.js`**:
- Mock `getComputedStyle` to return known CSS var values.
- Mount a component that uses `useChartTheme()`.
- Assert initial `gridColor.value` equals the mock return.
- Dispatch `dmc-theme-change` custom event on `document`.
- Assert `gridColor.value` updates.

**`PatientsIndex.computeds.test.js`**:
Test the three role-gate computeds from `Patients/Index.vue` (lines 11–14) without mounting the full page. Extract them into a small helper or test the component with `shallowMount` + a mocked `usePage`.
- `canAssign`: observer role → false; admin → true; non-observer + can.assign → true.
- `canReassign`: observer → false; admin → true; can_manage → true; neither → false.
- `isObserver`: role 5 → true; role 3 → false.
- `canManage(row)`: admin → true; can_manage → true; `row.consultant_id === me.id` → true; none → false.

The mock `usePage()` returns `{ props: { auth: { user: { role, is_admin, can: {assign, manage}, id } } } }`.

**`AppLayout.notifications.test.js`**:
Mock `fetch` using Vitest's `vi.fn()`. Shallow-mount `AppLayout`.
- `toggleBell` when `bellOpen=false`: calls `fetch('/api/notifications')`, sets `notifications`, sets `readOverride=true`.
- When `d.unread > 0`: calls `fetch('/notifications/read-all', { method: 'POST' })`.
- When `d.unread == 0`: does NOT call the read-all endpoint.
- `unread` computed: returns `unreadNotifications` prop when `readOverride=false`; returns 0 when `readOverride=true`.

**CI job** (`vitest.yml`):
```yaml
name: Vitest
on: [push, pull_request]
jobs:
  vitest:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '22', cache: 'npm', cache-dependency-path: laravel/package-lock.json }
      - run: npm ci
        working-directory: laravel
      - run: npm test
        working-directory: laravel
```

**Decisions & defaults**
- Vitest 3 + `@vue/test-utils` 2 are both compatible with Vue 3.5 and Vite 8 (the current versions in `package.json`).
- `jsdom` environment (not `happy-dom`) for broader API compatibility.
- Keeping `vitest.config.js` separate avoids importing `vitest/config` into the production Vite build.
- The `globals: true` setting makes `describe/it/expect` available without explicit imports — consistent with the existing PHP PHPUnit usage style.

**Test plan**
The tests described above ARE the test plan for this item. They collectively cover composable logic, computed role gates, and async fetch flows.

**Build sequence**
1. Install deps: `npm install --save-dev vitest@^3 @vue/test-utils@^2.4 jsdom@^25` in `laravel/`.
2. Create `vitest.config.js`.
3. Add `"test"` script to `package.json`.
4. Write and run `useChartTheme.test.js` — green.
5. Write `PatientsIndex.computeds.test.js` — green.
6. Write `AppLayout.notifications.test.js` with mocked fetch — green.
7. Add remaining composable tests (from items 1 and 3 — they are already written as part of those items).
8. Create CI workflow.
9. Commit (`package.json`, `package-lock.json`, config, test files).

---

### Item 10 — Print stylesheet (effort S, risk Low)

**Goal / user value**
`Dashboard.vue` and `Statistics/Index.vue` have no print styles. Printing them yields the full sidebar, header, and `pl-64` offset — the sidebar obscures content. `ActiveList.vue` already uses `no-print` + `report` classes for its print-optimised output; extend the same CSS layer to cover Dashboard and Statistics.

**Files**
- MODIFY `laravel/resources/css/app.css` — extend the `@layer utilities` / `@media print` block
- MODIFY `laravel/resources/js/Pages/Dashboard.vue` — add a "Print" button + `no-print` classes on interactive elements
- MODIFY `laravel/resources/js/Pages/Statistics/Index.vue` — same

**Design**

`app.css` — add a `@media print` block in `@layer utilities` (or at the bottom of the file after the theme definitions):

```css
@media print {
    /* Hide chrome */
    aside, header, .no-print { display: none !important; }
    /* Remove the sidebar offset */
    .lg\:pl-64 { padding-left: 0 !important; }
    /* White background, black text */
    body, .bg-card, .bg-app { background: #fff !important; color: #000 !important; }
    /* Full width for chart containers */
    [data-apexcharts-chart], .apexcharts-canvas { max-width: 100% !important; width: 100% !important; }
    /* Page breaks between major sections */
    .print-break-before { break-before: page; }
    /* Shadow / ring removal (print doesn't need depth) */
    .shadow-card, .shadow-card-lg, .ring-1, .ring-line { box-shadow: none !important; border: 1px solid #e0e0e0 !important; }
    /* Keep the brand header in the ActiveList report visible */
    .report header { display: block !important; }
}
```

Note: `ActiveList.vue` already uses `.no-print` (line 29) and `.report` (line 38); those selectors are preserved and extended by the block above.

**Dashboard.vue** — add a print button to the toolbar (visible on screen, hidden on print):
```html
<button @click="window.print()" class="no-print inline-flex items-center gap-2 rounded-xl bg-card px-4 py-2 text-sm font-semibold text-ink-500 shadow-sm ring-1 ring-line transition hover:bg-ink-50">
    <svg ...print-icon... />
    Print
</button>
```
Wrap the auto-refresh visibility controller (`document.addEventListener('visibilitychange', ...)`) in `class="no-print"` on the badge that shows "Live".

**Statistics/Index.vue** — add the same print button alongside the existing filter bar. The chart containers get `print-break-before` on the second and subsequent chart rows so ApexCharts doesn't split mid-render.

Fixed chart width for print: ApexCharts v5 supports a `width: '100%'` option; on print, the CSS override above enforces 100% viewport width which is already applied. No JS change needed.

**Decisions & defaults**
- Not adding a "Print view" route (server-side render) — the instruction calls it optional and the existing `ActiveList.vue` print-CSS approach is already deployed and working.
- ApexCharts renders to SVG inside a `<canvas>` container; the CSS `max-width` override is sufficient for basic print fidelity. For exact pixel-perfect PDF output, that belongs to Phase 2 (server-side PDF via dompdf).
- The `@media print` block goes at the end of `app.css` so it has higher specificity than the Tailwind utilities above it.

**Test plan**
- No automated tests applicable (print rendering is a visual/manual concern).
- Manual: load Dashboard in Chrome; Cmd/Ctrl+P; confirm sidebar absent, content full-width, charts visible.
- Manual: load Statistics; same check.

**Build sequence**
1. Add `@media print` block to `app.css`.
2. Rebuild; check Dashboard screenshot via `window.print()` preview.
3. Add print buttons to `Dashboard.vue` and `Statistics/Index.vue`.
4. Rebuild + commit.

---

## Risks & sequencing notes

- **Item 1 before Items 2–3**: `ConfirmDialog` must be registered in `AppLayout` before the page replacements proceed; otherwise the page pages will call `ask()` before the component is mounted.
- **Item 4 before Phase 2**: The centralised `Audit::log` is required by all Phase 2 items that add new audit actions. Complete it early in the sprint.
- **Items 5 and 6 are independent**: each touches different models/controllers. They can be developed in parallel.
- **Item 9 test infrastructure**: install the npm packages before writing any Vitest test. The `package-lock.json` must be committed along with `package.json` since the server has no npm; the CI installs from the lockfile.
- **Build commits**: every item that touches Vue/CSS files requires `npm run build` in `laravel/` and a commit of the updated `public/build/` artefacts (the production server has no npm). Flag this at the start of every PR.
- **No schema migrations** in this phase. All PHP changes are model methods, a support class, and controller in-place replacements. `php artisan test` is the only server-side command needed to verify correctness.
- **Items 7 and 8** are the lowest-risk items (pure PHP, no frontend): do them first if the Vitest harness (Item 9) blocks other work.
- **Item 3 a11y scope**: `useModalA11y` patches the attribute layer only. If a modal is subsequently re-implemented as a `<dialog>` element (a future refactor), the composable becomes unnecessary but causes no harm.

---

## Phase 1 — Clinical flow & dashboard actionability

This phase transforms the dashboard from a read-only data display into an actionable operational command centre. Every item adds clickable linkage back to the patient board, surfaces time-sensitive clinical signals (boarding patients, load imbalance, threshold breaches), and gives the charge consultant a "my unit right now" lens — all without touching any existing metric definition or breaking the established auto-refresh, chart theme, or StatisticsValueTest discipline. The phase is fully additive: no existing controller methods are removed or signature-changed; only the `index` response shape of `DashboardController` and the `index` query string of `PatientsController` expand.

---

### Item 1 — Boarding worklist (effort M, risk Low)

- **Goal / user value**: Surfaces patients who are medically cleared but still occupying a bed (`medical_discharge_date IS NOT NULL AND discharge_date IS NULL`). The count appears as a KPI chip; a ranked worklist (longest boarding first) links directly to the filtered board, enabling a charge nurse or consultant to act without hunting.

- **Files**
  - Modify: `laravel/app/Http/Controllers/DashboardController.php`
  - Modify: `laravel/app/Http/Controllers/PatientsController.php`
  - Modify: `laravel/resources/js/Pages/Dashboard.vue`
  - Create: `laravel/tests/Feature/Phase1DashboardValueTest.php` (new value-test file for this phase)

- **Design**

  *DashboardController — new boarding block (add after the `$deathsMonth` line, ~line 41):*
  ```php
  $boardingCount = (int) DB::table('admissions')
      ->whereNull('discharge_date')
      ->whereNotNull('medical_discharge_date')
      ->count();

  $boardingWorklist = DB::table('admissions as a')
      ->join('patients as p', 'p.id', '=', 'a.patient_id')
      ->leftJoin('users as u', 'u.id', '=', 'a.consultant_id')
      ->whereNull('a.discharge_date')
      ->whereNotNull('a.medical_discharge_date')
      ->selectRaw('a.id, p.name, p.mrn, a.medical_discharge_date,
                   DATEDIFF(CURDATE(), a.medical_discharge_date) delay_days,
                   COALESCE(u.full_name, u.name) consultant')
      ->orderByDesc('delay_days')
      ->limit(10)
      ->get();
  ```
  Add `boardingCount` and `boardingWorklist` to the Inertia response. The `delay_days` calculation uses `DATEDIFF(CURDATE(), medical_discharge_date)`, consistent with how `Admission::lengthOfStay()` uses Carbon diff for live admissions.

  *PatientsController — new `view=boarding` filter (add to `boardGroups`, ~line 127):*
  ```php
  ->when(($filters['view'] ?? null) === 'boarding',
      fn ($q) => $q->whereNotNull('medical_discharge_date'))
  ```
  The boarding filter does NOT apply the D1 consultant-scope exemption (unlike `longterm`/`tb`) because boarding patients belong to specific consultants and the D1 rule must hold. Add `boarding` to the existing view toggle in `PatientsController::index()` filter pass-through (the `$filters = $request->only(...)` line at line 24 already picks up any `view` value; no schema change needed).

  *Dashboard.vue — new boarding card and worklist:*

  Add `boardingCount` and `boardingWorklist` to `defineProps`. Add a seventh KPI card (reuse the existing `kpiCards` computed array):
  ```js
  { label: 'Boarding', value: props.boardingCount,
    sub: 'medically cleared · bed still occupied',
    icon: 'boarding', tone: 'warning',
    link: '/patients?view=boarding' }
  ```
  Add `boarding` to `kpiIcons` and `toneClass`. Make the KPI card `<div>` a `<button @click="router.visit(c.link)" v-if="c.link">` or wrap in a `<component :is="c.link ? 'button' : 'div'">` pattern — consistent with how the `activity24h` bars will link in Item 3.

  Below the KPI row, add a collapsible `BoardingWorklist` inline section (not a separate component file, to keep this phase self-contained):
  ```html
  <div v-if="boardingWorklist.length" class="mt-5 rounded-2xl bg-card p-5 shadow-card ring-1 ring-warning-300/60">
    <div class="mb-3 flex items-center justify-between">
      <h3 class="font-semibold text-ink-700">Boarding patients
        <span class="ml-2 nums rounded-full bg-warning-100 px-2 py-0.5 text-xs font-bold text-warning-600">
          {{ boardingCount }}
        </span>
      </h3>
      <button @click="router.visit('/patients?view=boarding')"
              class="text-xs font-semibold text-brand-600 hover:underline">
        View board →
      </button>
    </div>
    <table class="w-full text-sm">
      <thead>...</thead>  <!-- Name / MRN / Consultant / Delay (days) -->
      <tbody>
        <tr v-for="r in boardingWorklist" :key="r.id"
            class="cursor-pointer hover:bg-warning-50/40"
            @click="router.visit('/patients?view=boarding')">
          ...
        </tr>
      </tbody>
    </table>
  </div>
  ```
  Use `bg-warning-300/60` ring and `text-warning-600` numerals to distinguish boarding from normal KPI cards.

- **Decisions & defaults**
  - Worklist capped at 10 rows on the dashboard; full list via the `/patients?view=boarding` board filter.
  - No clinician sign-off needed: `medical_discharge_date IS NOT NULL AND discharge_date IS NULL` is the existing "Disch. still in" badge logic already used in `PatientsController::boardGroups` (line 182) and rendered in `Patients/Index.vue` (line 366) — this item just promotes it to a KPI.
  - `delay_days` is `DATEDIFF(CURDATE(), medical_discharge_date)` — not `lengthOfStay()` which measures from admit date. This matches the clinical meaning: delay since medical clearance.
  - The boarding view filter is visible to all roles that can see the board (same gate as `longterm`/`tb`).

- **Test plan** — in `Phase1DashboardValueTest.php`:
  - `test_boarding_count_matches_independent_sql` — seed 3 admitted patients: one fully active, one with `medical_discharge_date` set (boarding), one fully discharged. Assert `kpis.boardingCount === 1` via Inertia assertion AND re-run the same `whereNull/whereNotNull` query directly and compare counts.
  - `test_boarding_worklist_ranks_by_delay_desc` — seed two boarding patients with different `medical_discharge_date` values. Assert `boardingWorklist[0].delay_days >= boardingWorklist[1].delay_days`.
  - `test_boarding_board_filter_returns_only_medically_discharged` — GET `/patients?view=boarding` as admin. Assert every group's patients all have `medically_discharged === true` in the response.
  - `test_boarding_count_zero_when_none` — clean DB, assert `boardingCount === 0`.

- **Build sequence**
  1. Write `Phase1DashboardValueTest::test_boarding_count_matches_independent_sql` — red.
  2. Add `$boardingCount` and `$boardingWorklist` queries to `DashboardController::index`; add both to the Inertia response array.
  3. Green. Commit `feat: boarding count + worklist in DashboardController`.
  4. Write `test_boarding_board_filter_returns_only_medically_discharged` — red.
  5. Add `view=boarding` branch to `PatientsController::boardGroups`.
  6. Green. Commit `feat: view=boarding filter in PatientsController`.
  7. Add `boardingCount`/`boardingWorklist` props and the worklist UI block to `Dashboard.vue`. Add `boarding` icon path to `kpiIcons`.
  8. Run Vite build, commit built assets. Commit `feat: boarding card + worklist in Dashboard.vue`.

---

### Item 2 — KPI period-over-period deltas (effort S, risk Low)

- **Goal / user value**: Converts static numbers into directional signals. A charge consultant can instantly see "admissions today are above the 7-day average" without mental arithmetic.

- **Files**
  - Modify: `laravel/app/Http/Controllers/DashboardController.php`
  - Modify: `laravel/resources/js/Pages/Dashboard.vue`
  - Modify: `laravel/tests/Feature/Phase1DashboardValueTest.php`

- **Design**

  The `$admBy` and `$disBy` keyed collections are already built in memory at DashboardController lines 58–59 for the 31-day trend. Reuse them directly:

  ```php
  // 7-day trailing mean (days -7..-1, excluding today)
  $trailAdm = array_sum(array_map(
      fn ($i) => (int) ($admBy[Carbon::today()->subDays($i)->toDateString()] ?? 0),
      range(1, 7)
  )) / 7;
  $trailDis = array_sum(array_map(
      fn ($i) => (int) ($disBy[Carbon::today()->subDays($i)->toDateString()] ?? 0),
      range(1, 7)
  )) / 7;

  // deaths: current month vs prior calendar month
  $priorMonthStart = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
  $priorMonthEnd   = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
  $deathsPrior = (int) DB::table('admissions')
      ->where('outcome', 'Dead')
      ->whereBetween('discharge_date', [$priorMonthStart, $priorMonthEnd])
      ->count();

  // occupancy: prior-week mean ward census (active ward patients at end of each of past 7 days)
  // Approximate via: active ward discharges as a proxy isn't clean; simplest correct approach is
  // a point-in-time census for each prior day using DATEDIFF logic. Keep it cheap: one query.
  $priorWeekOccupancy = round((float) (DB::table('admissions')
      ->whereRaw('admit_date <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)')
      ->whereRaw('(discharge_date IS NULL OR discharge_date > DATE_SUB(CURDATE(), INTERVAL 7 DAY))')
      ->whereRaw(Admission::NON_ICU_SQL)
      ->count()) / $wardBeds * 100, 1);

  $deltas = [
      'admissions' => [
          'value' => $admissionsToday,
          'mean7' => round($trailAdm, 1),
          'delta' => round($admissionsToday - $trailAdm, 1),
          'direction' => $admissionsToday >= $trailAdm ? 'up' : 'down',
          'good_up' => true,   // more admissions = unit is busy, neutral signal
      ],
      'discharges' => [
          'value' => $dischargesToday,
          'mean7' => round($trailDis, 1),
          'delta' => round($dischargesToday - $trailDis, 1),
          'direction' => $dischargesToday >= $trailDis ? 'up' : 'down',
          'good_up' => true,
      ],
      'deathsMonth' => [
          'value' => $deathsMonth,
          'prior' => $deathsPrior,
          'delta' => $deathsMonth - $deathsPrior,
          'direction' => $deathsMonth > $deathsPrior ? 'up' : ($deathsMonth < $deathsPrior ? 'down' : 'flat'),
          'good_up' => false,   // more deaths = bad
      ],
      'occupancy' => [
          'value' => $occupancy,
          'prior7mean' => $priorWeekOccupancy,
          'delta' => round($occupancy - $priorWeekOccupancy, 1),
          'direction' => $occupancy > $priorWeekOccupancy ? 'up' : 'down',
          'good_up' => null,   // occupancy is neutral (high = busy / over-census — the alert handles the threshold)
      ],
  ];
  ```
  Add `deltas` to the Inertia response.

  The prior-week occupancy query uses a point-in-time census for exactly 7 days ago as a single-query proxy (a true rolling mean would require 7 sub-queries or a snapshot table; the point-in-time proxy is clinically adequate and keeps the page load fast). This can be promoted to a true 7-day mean in the caching item (Item 7) if needed.

  *Dashboard.vue — delta chip component (inline, not extracted):*
  ```js
  // In <script setup>
  const deltaChip = (d) => {
    if (!d || d.delta === undefined) return null;
    const up = d.direction === 'up';
    const isGood = d.good_up === true ? up : d.good_up === false ? !up : null;
    return {
      label: (up ? '▲' : '▼') + ' ' + Math.abs(d.delta),
      cls: isGood === true  ? 'bg-success-100 text-success-600'
         : isGood === false ? 'bg-danger-100 text-danger-600'
         :                    'bg-ink-100 text-ink-500',
    };
  };
  ```
  In the KPI card template, add a chip below the sub-label:
  ```html
  <span v-if="chip(c)" class="mt-1.5 inline-block rounded-full px-2 py-0.5 text-[10px] font-bold"
        :class="chip(c).cls">{{ chip(c).label }}</span>
  ```
  Map each KPI card to its delta key via a `deltaKey` property on each `kpiCards` entry (e.g. `{ ..., deltaKey: 'admissions' }`). `chip(c)` calls `deltaChip(props.deltas[c.deltaKey])`.

  The `deathsMonth` delta shows "vs prior month"; `admissions`/`discharges` show "vs 7d avg"; `occupancy` shows "vs 1w ago". Sub-label text already handles the description.

- **Decisions & defaults**
  - `good_up: null` for occupancy means the chip is neutral grey — clinically, high occupancy is neither good nor bad in isolation; the threshold alert (Item 4) handles the over-census signal.
  - Admissions `good_up: true` is debatable clinically (more admissions isn't inherently good), but the chip is purely informational ("busier than average") not a quality signal. Flag for clinician review if they want a different polarity.
  - `deathsMonth` compares to the prior calendar month (not a rolling 30-day window) for consistency with how `deathsMonth` itself is computed month-to-date.
  - The `admBy`/`disBy` reuse means zero extra queries for the admissions/discharges deltas — they are computed in PHP from the already-fetched maps.

- **Test plan**
  - `test_deltas_admissions_uses_7day_mean_from_admBy_map` — seed 7 prior days with 2 admissions each, today with 4. Assert `deltas.admissions.mean7 === 2.0`, `delta === 2.0`, `direction === 'up'`.
  - `test_deltas_deaths_month_compares_to_prior_calendar_month` — seed 1 death this month, 3 deaths prior month. Assert `deltas.deathsMonth.delta === -2`, `direction === 'down'`.
  - `test_deltas_flat_when_equal` — seed today with same count as 7-day mean. Assert `delta === 0.0`.

- **Build sequence**
  1. Write delta value tests — red.
  2. Add PHP delta block to `DashboardController::index`, add `deltas` key to response.
  3. Green. Commit `feat: KPI period-over-period deltas in DashboardController`.
  4. Add `deltaKey` to `kpiCards` entries and `deltaChip` helper + chip template to `Dashboard.vue`.
  5. Vite build + commit.

---

### Item 3 — Drill-through navigation (effort M, risk Low)

- **Goal / user value**: Every number on the dashboard is a link to the matching patient board view, eliminating the need to mentally reconstruct the filter after clicking through.

- **Files**
  - Modify: `laravel/app/Http/Controllers/PatientsController.php`
  - Modify: `laravel/resources/js/Pages/Dashboard.vue`
  - Modify: `laravel/tests/Feature/Phase1DashboardValueTest.php`

- **Design**

  *PatientsController — add `consultant_id` and `specialty_id` filters:*

  The existing filter extraction at line 24 is `$filters = $request->only('search', 'location', 'view')`. Extend to:
  ```php
  $filters = $request->only('search', 'location', 'view', 'consultant_id', 'specialty_id');
  ```
  In `boardGroups`, the `$scope` closure already handles the D1 consultant-only restriction. When `consultant_id` is passed as a query-string filter (from a dashboard drill-through), it should be applied as an additional WHERE on top of the D1 scope — not replacing it, so the consultant's own D1 restriction still holds. Add to the `$admissions` query chain (after the `->tap($scope)` line 123):
  ```php
  ->when($filters['consultant_id'] ?? null, fn ($q, $id) => $q->where('consultant_id', (int) $id))
  ->when($filters['specialty_id'] ?? null, fn ($q, $id) =>
      $q->whereHas('consultant', fn ($u) => $u->where('specialty_id', (int) $id)))
  ```
  Pass `consultant_id` and `specialty_id` through to the Vue props inside `'filters' => $filters`.

  *Dashboard.vue — make tiles and chart elements navigate:*

  Add a `drillTo` helper:
  ```js
  const drillTo = (params) => router.visit('/patients', { data: params });
  ```
  Apply to:

  1. **KPI tiles** — add an optional `href` to each `kpiCards` entry:
     ```js
     { label: 'Active Census', value: props.kpis.census, ..., href: '/patients' },
     { label: 'Boarding',      value: props.boardingCount, ..., href: '/patients?view=boarding' },
     ```
     Wrap the card in `<button @click="c.href && drillTo(parseHref(c.href))" :class="c.href ? 'cursor-pointer hover:ring-brand-400' : ''">`; non-linked cards get `cursor-default`.

  2. **Census donut slices** — add `dataPointSelection` to `donutOptions`:
     ```js
     chart: { ..., events: { dataPointSelection: (_e, _c, config) => {
       const labels = ['hospitalist', 'subspecialty', 'longterm'];
       const label = labels[config.dataPointIndex];
       // specialty_id=1 for hospitalist, view=longterm for longterm, or filter is too coarse
       if (label === 'longterm') drillTo({ view: 'longterm' });
       // hospitalist/subspecialty → specialty_id filter only meaningful with a specialty list
       // Use view drill for now; defer specialty-id mapping to Item 6
     } } }
     ```

  3. **Active Load by Consultant bars** (the `perConsultant` list, line 248 of Dashboard.vue) — wrap each row in a button:
     ```html
     <div v-for="c in perConsultant" :key="c.name"
          class="flex items-center gap-3 cursor-pointer hover:bg-brand-50/40 rounded-lg px-1"
          @click="drillToConsultant(c.name)">
     ```
     `drillToConsultant` resolves the name to an id via a `consultantNameMap` computed from `props.consultantBoard` (which includes `id` after Item 3 adds it — see below). Add `id` to the `perConsultant` map in `DashboardController`:
     ```php
     $perConsultant = DB::table('admissions as a')->join('users as u', ...)
         ->selectRaw('u.id consultant_id, COALESCE(u.full_name, u.name) consultant, COUNT(*) c')
         ...
         ->map(fn ($r) => (object) ['id' => (int) $r->consultant_id, 'name' => $r->consultant, 'c' => (int) $r->c]);
     ```

  4. **Per-consultant board table rows** (lines 306–313 of Dashboard.vue) — the consultant name is already available; add `@click="drillTo({ consultant_id: c.id })"` and expose `id` from `consultantBoard` (already present as the GROUP BY key in DashboardController line 141, but not yet mapped — add `'id' => (int) DB::table('users')->where(COALESCE...) ...`). The cleanest approach: add `u.id` to the `consultantBoard` SELECT and map it:
     ```php
     ->selectRaw("u.id, COALESCE(u.full_name, u.name) consultant, u.on_service, u.specialty_id, ...")
     ...
     ->map(fn ($r) => [ 'id' => (int) $r->id, 'name' => $r->consultant, ... ]);
     ```
     The GROUP BY must include `u.id` (add alongside the existing `consultant, u.on_service, u.specialty_id`).

  *Patients/Index.vue — display active filters:* The `view`, `location`, `search`, `consultant_id`, `specialty_id` filters are all already passed into `apply()` via `router.get('/patients', { ... })`. The new filter keys just need to be included in the reactive state and the `apply()` call. Add:
  ```js
  const consultantId = ref(props.filters.consultant_id || '');
  const specialtyId  = ref(props.filters.specialty_id || '');
  ```
  Include them in `apply()` payload. No new UI controls needed for drill-through (the filter is set programmatically from the dashboard); a "Clear filters" chip in the toolbar (already implied by the existing `search`/`location`/`view` pattern) will clear all.

- **Decisions & defaults**
  - `consultant_id` filter on the board does NOT bypass the D1 scoping rule. A consultant drilling through to their own bar (same id) gets their own patients. An admin drilling to any consultant's bar gets that consultant's full list. This is intentional and consistent with `boardScope`.
  - The `specialty_id` filter scopes via `consultant.specialty_id` (a user attribute), not a per-admission field — this matches the census donut logic.
  - No new routes are needed; all filters pass as query string to the existing `GET /patients` route.

- **Test plan**
  - `test_patients_board_accepts_consultant_id_filter` — seed 2 consultants each with 2 patients. GET `/patients?consultant_id={c1.id}` as admin. Assert all returned groups have `id === c1.id`, total = 2.
  - `test_patients_board_consultant_id_filter_respects_d1_scope` — Consultant-A GETs `/patients?consultant_id={consultant_B.id}`. Assert groups contain only Consultant-A's patients (D1 wins).
  - `test_patients_board_specialty_id_filter` — seed one hospitalist (specialty 1) and one subspecialist (specialty 2). GET `/patients?specialty_id=2` as admin. Assert only subspecialist's group appears.
  - `test_perConsultant_includes_id` — GET `/` as admin. Assert each `perConsultant` entry has a non-null integer `id`.

- **Build sequence**
  1. Write `test_patients_board_accepts_consultant_id_filter` — red.
  2. Extend `$filters` extraction and add `consultant_id`/`specialty_id` `->when()` clauses to `boardGroups`.
  3. Green. Write and pass `test_patients_board_consultant_id_filter_respects_d1_scope`.
  4. Write `test_perConsultant_includes_id` — red. Add `u.id` to `perConsultant` and `consultantBoard` selects in `DashboardController`.
  5. Green. Commit `feat: consultant_id + specialty_id board filters; id on perConsultant/consultantBoard`.
  6. Add `drillTo` helper and click handlers to `Dashboard.vue`. Add `consultantId`/`specialtyId` refs and pass through `apply()` in `Patients/Index.vue`.
  7. Vite build + commit.

---

### Item 4 — Threshold alert strip (effort M, risk Low)

- **Goal / user value**: A dismissible, severity-coloured banner atop the dashboard fires when operationally significant thresholds are crossed, without requiring the charge consultant to read every KPI tile. Thresholds are admin-tunable so clinicians own the definitions.

- **Files**
  - Create: `laravel/database/migrations/2026_06_14_000001_add_alert_thresholds_to_settings.php`
  - Modify: `laravel/app/Http/Controllers/ControlController.php` (add new fields to `updateSettings` validation)
  - Modify: `laravel/app/Http/Controllers/DashboardController.php`
  - Modify: `laravel/resources/js/Pages/Control/Index.vue` (add fields to `sForm` and the settings form template)
  - Modify: `laravel/resources/js/Pages/Dashboard.vue`
  - Modify: `laravel/tests/Feature/Phase1DashboardValueTest.php`

- **Design**

  *Migration — add four threshold columns to `settings`:*
  ```php
  $table->unsignedInteger('alert_overcensus_pct')->default(100);   // occupancy % at which over-census fires
  $table->unsignedInteger('alert_boarding_max')->default(5);        // boarding count above which alert fires
  $table->unsignedInteger('alert_readmit_rate_pct')->default(10);   // 30-day readmit rate % threshold
  $table->unsignedInteger('alert_deaths_delta_pct')->default(50);   // % rise in deaths vs prior month to alert
  ```
  Defaults are intentionally conservative: 100% occupancy is factual over-census; 5 boarding is operationally significant; 10% readmit rate is a commonly used clinical threshold; 50% mortality rise flags a sharp upturn. All MUST be reviewed by clinicians before production — flag in migration comment.

  *DashboardController — compute alerts array (add after the deltas block):*
  ```php
  $alerts = [];

  // Over-census: occupancy at or above threshold
  if ($occupancy >= $settings->alert_overcensus_pct) {
      $alerts[] = [
          'key' => 'overcensus',
          'severity' => 'danger',
          'message' => "Over-census: {$occupancy}% occupancy ({$activeWard} ward patients, {$wardBeds} beds).",
          'link' => '/patients',
      ];
  }

  // Boarding: boarding count above threshold
  if ($boardingCount > $settings->alert_boarding_max) {
      $alerts[] = [
          'key' => 'boarding',
          'severity' => 'warning',
          'message' => "{$boardingCount} patients medically cleared but still boarding (threshold: {$settings->alert_boarding_max}).",
          'link' => '/patients?view=boarding',
      ];
  }

  // Deaths month delta: % rise vs prior month
  if ($deathsPrior > 0) {
      $deathsDeltaPct = (int) round(($deathsMonth - $deathsPrior) / $deathsPrior * 100);
      if ($deathsDeltaPct >= $settings->alert_deaths_delta_pct) {
          $alerts[] = [
              'key' => 'deaths_delta',
              'severity' => 'danger',
              'message' => "Mortality this month ({$deathsMonth}) is {$deathsDeltaPct}% higher than last month ({$deathsPrior}).",
              'link' => null,
          ];
      }
  } elseif ($deathsMonth > 0 && $deathsPrior === 0) {
      // prior month was zero — any death this month fires the alert
      $alerts[] = ['key' => 'deaths_delta', 'severity' => 'warning',
          'message' => "{$deathsMonth} death(s) this month vs 0 last month.", 'link' => null];
  }

  // Readmission rate (YTD admissions > 0 guard)
  if ($ytd['admissions'] > 0) {
      $readmitYtd = (int) DB::table('admissions as a')
          ->join('admissions as prev', Admission::readmissionJoin($settings->readmission_window_days ?? 3))
          ->whereBetween('a.admit_date', [$yearStart, $today])
          ->whereRaw(Admission::NON_ICU_SQL)
          ->count();
      $readmitRate = round($readmitYtd / $ytd['admissions'] * 100, 1);
      if ($readmitRate >= $settings->alert_readmit_rate_pct) {
          $alerts[] = [
              'key' => 'readmits',
              'severity' => 'warning',
              'message' => "Readmission rate YTD: {$readmitRate}% ({$readmitYtd} of {$ytd['admissions']} non-ICU admissions).",
              'link' => '/registry',
          ];
      }
  }
  ```
  The readmission rate query reuses `Admission::readmissionJoin()` (defined in `Admission.php` line 55) and `Admission::NON_ICU_SQL` (line 17) — single source of truth guaranteed.

  Add `alerts` to the Inertia response. The `$deathsPrior` value computed in Item 2 is reused here; ensure the delta block runs before the alert block in the controller.

  *ControlController::updateSettings — add the four new fields to validation:*
  ```php
  'alert_overcensus_pct'    => ['required', 'integer', 'min:50', 'max:200'],
  'alert_boarding_max'      => ['required', 'integer', 'min:0', 'max:100'],
  'alert_readmit_rate_pct'  => ['required', 'integer', 'min:1', 'max:100'],
  'alert_deaths_delta_pct'  => ['required', 'integer', 'min:10', 'max:500'],
  ```

  *Control/Index.vue — add the four fields to `sForm` with sensible labels* (append to `fieldLabels` and `sForm` initialization, and add four `<input type="number">` rows in the settings form template section, grouped under an "Alert thresholds" sub-heading).

  *Dashboard.vue — alert strip:*
  ```html
  <!-- Alert strip — rendered before the KPI row, dismissible per-key via sessionStorage -->
  <div v-for="alert in visibleAlerts" :key="alert.key"
       class="mb-4 flex items-start justify-between gap-3 rounded-2xl px-5 py-3 ring-1"
       :class="alert.severity === 'danger'
           ? 'bg-danger-100/80 ring-danger-300/60 text-danger-700'
           : 'bg-warning-100/80 ring-warning-300/60 text-warning-600'">
    <div class="flex items-center gap-3">
      <!-- severity icon inline SVG -->
      <span class="text-sm font-semibold">{{ alert.message }}</span>
      <a v-if="alert.link" :href="alert.link"
         class="ml-2 text-xs font-bold underline hover:no-underline">View →</a>
    </div>
    <button @click="dismiss(alert.key)" aria-label="Dismiss alert" class="shrink-0 text-inherit hover:opacity-60">✕</button>
  </div>
  ```
  Dismissal state:
  ```js
  const dismissed = ref(new Set(
    JSON.parse(sessionStorage.getItem('dmc-alerts-dismissed') || '[]')
  ));
  const dismiss = (key) => {
    dismissed.value.add(key);
    dismissed.value = new Set(dismissed.value);
    sessionStorage.setItem('dmc-alerts-dismissed', JSON.stringify([...dismissed.value]));
  };
  // Re-fire on the next auto-refresh: clear dismissed keys that are no longer in the alerts list
  // (the alert resolved itself); re-show any newly fired one by not persisting across page loads
  // Use sessionStorage (tab-scoped) not localStorage so a ward screen re-shows on reload.
  const visibleAlerts = computed(() =>
    (props.alerts || []).filter(a => !dismissed.value.has(a.key))
  );
  ```

- **Decisions & defaults**
  - `sessionStorage` (not `localStorage`) so alerts re-appear on page reload/refresh — a ward monitor that reloads hourly will re-surface the alert. A nurse who dismisses it during a shift won't be re-nagged within the same tab session.
  - All four defaults are intentionally conservative and require clinical review before production (noted in migration).
  - The readmission rate alert query uses `Admission::readmissionJoin()` not an inline formula — single-source-of-truth rule is met.
  - `alert_deaths_delta_pct` requires `$deathsPrior > 0` guard to prevent division-by-zero; the edge case of zero prior month deaths fires a separate flat alert at any death.

- **Test plan**
  - `test_alert_fires_when_occupancy_at_threshold` — `ward_beds=10`, `alert_overcensus_pct=100`, seed 10 active ward patients. Assert `alerts` contains an entry with `key=overcensus`.
  - `test_alert_does_not_fire_below_threshold` — same setup, 9 ward patients. Assert no `overcensus` alert.
  - `test_alert_boarding_fires_above_max` — `alert_boarding_max=3`, seed 4 boarding patients. Assert `key=boarding` in alerts.
  - `test_alert_deaths_delta_fires_on_sharp_rise` — 2 deaths prior month, 4 this month (100% rise). `alert_deaths_delta_pct=50`. Assert `key=deaths_delta`.
  - `test_alert_deaths_delta_no_fire_within_threshold` — 3 prior, 4 this month (33% rise, threshold=50). Assert no `deaths_delta` alert.
  - `test_alert_readmit_rate_fires` — seed admissions with readmit rate above threshold. Assert `key=readmits` in alerts. Uses `Admission::readmissionJoin()` independently to verify the count.
  - `test_alerts_empty_when_all_within_bounds` — healthy fixture. Assert `alerts === []`.

- **Build sequence**
  1. Write migration, run it.
  2. Write alert boundary tests — red.
  3. Add alert computation to `DashboardController::index`.
  4. Green. Commit `feat: threshold alerts computed in DashboardController`.
  5. Add four fields to `ControlController::updateSettings` validation + `Control/Index.vue` form.
  6. Write test `test_control_saves_alert_thresholds` — green immediately (existing updateSettings pattern).
  7. Add alert strip to `Dashboard.vue`. Vite build + commit.

---

### Item 5 — 'My unit today' consultant lens (effort M, risk Low)

- **Goal / user value**: When a Consultant logs in, the dashboard immediately shows their personal census without any navigation. The toggle persists per-user preference so the default is useful without being invasive for non-consultants.

- **Files**
  - Modify: `laravel/app/Http/Controllers/DashboardController.php`
  - Modify: `laravel/resources/js/Pages/Dashboard.vue`
  - Modify: `laravel/tests/Feature/Phase1DashboardValueTest.php`

- **Design**

  *DashboardController — add `myUnit` block (append to `index`, before `return Inertia::render`):*
  ```php
  $myUnit = null;
  $viewer = auth()->user();
  if ((int) $viewer->role === User::ROLE_CONSULTANT) {
      $myId = $viewer->id;
      $myActive = DB::table('admissions')
          ->whereNull('discharge_date')->where('consultant_id', $myId);

      $myBoarding = (clone $myActive)->whereNotNull('medical_discharge_date')->count();
      $myWard = (clone $myActive)->whereRaw(Admission::NON_ICU_SQL)->count();
      $myIcu  = (clone $myActive)->where('current_location', 'ICU')->count();
      $myTotal = (clone $myActive)->count();

      // "New since yesterday" = assigned_at >= yesterday
      $myNew = (clone $myActive)
          ->where('assigned_at', '>=', Carbon::today()->subDay())
          ->count();

      // Pending handover signatures for this consultant
      // Reuse the same logic as PatientsController line 154-157
      $mySignPending = DB::table('handover_signatures as hs')
          ->join('admissions as a', 'a.id', '=', 'hs.admission_id')
          ->where('hs.to_consultant_id', $myId)
          ->whereNull('hs.signed_at')
          ->whereNull('hs.voided_at')
          ->count();

      // My active consultations awaiting sign-off (this consultant is the consultant_id)
      $myConsults = DB::table('consultations')
          ->where('consultant_id', $myId)
          ->whereNull('signoff_date')
          ->count();

      $myUnit = [
          'total'        => $myTotal,
          'ward'         => $myWard,
          'icu'          => $myIcu,
          'boarding'     => $myBoarding,
          'new'          => $myNew,
          'signPending'  => $mySignPending,
          'myConsults'   => $myConsults,
      ];
  }
  ```
  Add `myUnit` (nullable) to the Inertia response.

  The boarding count reuses the same `whereNull('discharge_date')->whereNotNull('medical_discharge_date')` pattern from Item 1 — no drift.

  *Dashboard.vue — 'My unit today' strip:*

  ```js
  const myToggle = ref(
    localStorage.getItem('dmc-my-unit') !== 'off'  // default ON for consultants
  );
  const isConsultant = computed(() => page.props.auth.user.role === 3);
  const setMyToggle = (v) => {
    myToggle.value = v;
    localStorage.setItem('dmc-my-unit', v ? 'on' : 'off');
  };
  ```

  Render the strip only when `isConsultant && props.myUnit`:
  ```html
  <div v-if="isConsultant && myUnit && myToggle"
       class="mb-5 rounded-2xl bg-card p-5 shadow-card-lg ring-1 ring-brand-200/60">
    <div class="mb-3 flex items-center justify-between">
      <h3 class="font-bold text-ink-800">My patients today</h3>
      <button @click="setMyToggle(false)" class="text-xs text-ink-400 hover:text-ink-600">Hide</button>
    </div>
    <div class="grid grid-cols-3 gap-3 sm:grid-cols-6">
      <div v-for="[label, value, tone, link] in myUnitCards" :key="label"
           class="cursor-pointer rounded-xl p-3 ring-1 ring-line hover:ring-brand-300"
           :class="tone"
           @click="link && router.visit(link)">
        <p class="nums text-2xl font-extrabold">{{ value }}</p>
        <p class="text-xs text-ink-400">{{ label }}</p>
      </div>
    </div>
    <Link v-if="myUnit.signPending" href="/handovers"
          class="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 hover:bg-brand-100">
      {{ myUnit.signPending }} pending handover {{ myUnit.signPending === 1 ? 'signature' : 'signatures' }} →
    </Link>
  </div>
  <!-- show toggle when hidden -->
  <div v-else-if="isConsultant && myUnit && !myToggle" class="mb-5">
    <button @click="setMyToggle(true)"
            class="text-xs font-semibold text-brand-600 hover:underline">Show my patients →</button>
  </div>
  ```
  ```js
  const myUnitCards = computed(() => props.myUnit ? [
    ['Active', props.myUnit.total,   'bg-brand-50',   '/patients'],
    ['Ward',   props.myUnit.ward,    'bg-ink-50',     '/patients?location=Ward'],
    ['ICU',    props.myUnit.icu,     'bg-danger-50',  '/patients?location=ICU'],
    ['Boarding', props.myUnit.boarding, 'bg-warning-50', '/patients?view=boarding'],
    ['New (24h)', props.myUnit.new,  'bg-info-50',    null],
    ['Consults', props.myUnit.myConsults, 'bg-accent-50', '/consultations'],
  ] : []);
  ```

  The `myUnit` prop is `null` for non-consultant roles, so the strip renders nothing for Admin/Registrar/Resident/Observer without any role check in the template.

- **Decisions & defaults**
  - Default ON for consultants (`localStorage.getItem(...) !== 'off'`). Persistent per browser via `localStorage` (not session), so a consultant who turns it off stays off across logins.
  - The boarding count and sign-pending count reuse the exact same predicates as Item 1 and `PatientsController` lines 154–157 respectively — no drift possible.
  - The drill-through links from the 'My unit' strip use the existing board filters and will automatically scope to the consultant's own patients via D1 (the board's `boardScope` fires for all consultant GET requests).
  - No new endpoint. All data is server-side rendered in `DashboardController::index` (conditionally computed for consultants only, skipped for other roles).

- **Test plan**
  - `test_my_unit_null_for_non_consultant` — GET `/` as Admin. Assert `myUnit === null`.
  - `test_my_unit_populated_for_consultant` — consultant with 3 active patients (1 boarding, 1 ICU, 1 new yesterday). Assert `myUnit.total===3`, `myUnit.boarding===1`, `myUnit.icu===1`, `myUnit.new===1`.
  - `test_my_unit_sign_pending_counts_only_this_consultant` — seed 2 pending signatures for consultant-A, 1 for consultant-B. Consultant-A GETs `/`. Assert `myUnit.signPending===2`.
  - `test_my_unit_consults_awaiting_signoff` — seed 2 unsigned consultations for this consultant, 1 for another. Assert `myUnit.myConsults===2`.

- **Build sequence**
  1. Write `test_my_unit_null_for_non_consultant` and `test_my_unit_populated_for_consultant` — red.
  2. Add `$myUnit` block to `DashboardController::index`.
  3. Green. Commit `feat: myUnit block in DashboardController`.
  4. Add `myUnit` prop, `myToggle` ref, and the strip template to `Dashboard.vue`.
  5. Vite build + commit.

---

### Item 6 — Consultant load-fairness on chart (effort S, risk Low)

- **Goal / user value**: Gives charge staff an immediate visual signal of load imbalance — bars below the minimum band are flagged, bars above maximum are flagged, and the chart self-documents the unit's staffing policy. A 'rebalance' hint links to shuffle/reassign.

- **Files**
  - Modify: `laravel/resources/js/Pages/Dashboard.vue`
  - Modify: `laravel/app/Http/Controllers/DashboardController.php` (pass `minHosp`/`maxHosp`/`minSubs`/`maxSubs` from settings)
  - Modify: `laravel/tests/Feature/Phase1DashboardValueTest.php`

- **Design**

  The settings fields `min_hospitalist`, `max_hospitalist`, `min_subs`, `max_subs` already exist in the `settings` table (migration `2026_06_08_120006`) and are used by `ShuffleService`. They are already read via `Setting::current()` in `DashboardController` (line 27). Pass them to Vue:
  ```php
  // Add to the Inertia response alongside kpis:
  'loadBands' => [
      'minHosp' => (int) $settings->min_hospitalist,
      'maxHosp' => (int) $settings->max_hospitalist,
      'minSubs' => (int) $settings->min_subs,
      'maxSubs' => (int) $settings->max_subs,
  ],
  ```

  The "Active Load by Consultant" section (Dashboard.vue lines 244–256) currently renders a custom bar list (not an ApexCharts chart). This section is kept as-is (custom bars are readable and compact). Apply coloring per bar:

  ```js
  const barTone = (c) => {
    const isHosp = c.specialty_id === 1;
    const min = isHosp ? props.loadBands.minHosp : props.loadBands.minSubs;
    const max = isHosp ? props.loadBands.maxHosp : props.loadBands.maxSubs;
    if (c.c < min) return 'from-warning-400 to-warning-500';
    if (c.c > max) return 'from-danger-400 to-danger-600';
    return 'from-brand-400 to-brand-600';
  };
  ```
  Replace the static gradient class `from-brand-400 to-brand-600` at line 252 with `:class="barTone(c)"`. Also add a thin grey reference band behind each bar showing the min–max range:
  ```html
  <div class="relative h-2.5 flex-1 overflow-hidden rounded-full bg-ink-50">
    <!-- min-max band -->
    <div class="absolute top-0 h-full rounded-full bg-brand-100/60"
         :style="{ left: (loadBands.minHosp / consultantMax * 100) + '%',
                   width: ((loadBands.maxHosp - loadBands.minHosp) / consultantMax * 100) + '%' }">
    </div>
    <!-- actual bar -->
    <div class="relative h-full rounded-full bg-gradient-to-r"
         :class="barTone(c)"
         :style="{ width: (c.c / consultantMax * 100) + '%' }">
    </div>
  </div>
  ```
  This requires `specialty_id` on each `perConsultant` entry — add it to the SELECT in `DashboardController` (already available as `u.specialty_id`):
  ```php
  ->selectRaw('u.id consultant_id, u.specialty_id, COALESCE(u.full_name, u.name) consultant, COUNT(*) c')
  ->map(fn ($r) => (object) ['id' => (int) $r->consultant_id, 'specialty_id' => (int) $r->specialty_id,
                               'name' => $r->consultant, 'c' => (int) $r->c]);
  ```

  *Load spread summary + rebalance hint:*
  ```js
  const overloaded = computed(() =>
    props.perConsultant.filter(c => {
      const max = c.specialty_id === 1 ? props.loadBands.maxHosp : props.loadBands.maxSubs;
      return c.c > max;
    }).length
  );
  const underloaded = computed(() =>
    props.perConsultant.filter(c => {
      const min = c.specialty_id === 1 ? props.loadBands.minHosp : props.loadBands.minSubs;
      return c.c < min;
    }).length
  );
  ```
  Below the consultant list, add a summary line:
  ```html
  <div class="mt-3 flex items-center gap-3 text-xs">
    <span v-if="overloaded" class="text-danger-600 font-semibold">
      {{ overloaded }} over max
    </span>
    <span v-if="underloaded" class="text-warning-600 font-semibold">
      {{ underloaded }} below min
    </span>
    <button v-if="canShuffle && (overloaded || underloaded)"
            @click="router.visit('/patients')"
            class="ml-auto text-brand-600 hover:underline font-semibold">
      Rebalance (Shuffle / Reassign) →
    </button>
  </div>
  ```
  `canShuffle` is true for roles that can see the shuffle button: `auth.user.role !== 5 && (auth.user.is_admin || auth.user.can.assign)`. Access `auth` via `usePage().props.auth.user`.

  Note: the band overlay uses `loadBands.minHosp`/`maxHosp` for ALL bars in the current implementation since `perConsultant` groups all consultants together. A mixed hospitalist/subspecialist unit with different bands would need two separate band overlays. The simplest correct approach is to keep one band per specialty type (show two legends). Since the custom bar list is not an ApexCharts chart, this is straightforward — use the `specialty_id` on each row to pick the right band for the overlay.

- **Decisions & defaults**
  - The band overlay uses `consultantMax` as the 100% width reference (already computed at line 128) — bars always show their actual proportion, the band is overlaid absolutely.
  - The `perConsultant` list (line 113 of DashboardController) is limited to 8 rows by `limit(8)`. This is unchanged — the load chart is a summary. For the full per-consultant board table below, no band overlay is shown (it would clutter the table).

- **Test plan**
  - `test_load_bands_passed_to_dashboard` — GET `/` as admin. Assert `loadBands.minHosp`, `maxHosp`, `minSubs`, `maxSubs` all present and equal the settings defaults.
  - `test_perConsultant_includes_specialty_id` — GET `/` as admin. Assert each `perConsultant` entry has an integer `specialty_id`.
  - *(Visual coloring is a Vue computed, not server-side — no Feature test needed; add a Vitest unit test if a vitest setup is later added.)*

- **Build sequence**
  1. Write tests — red.
  2. Add `specialty_id` and `id` to `perConsultant` SELECT; add `loadBands` to Inertia response.
  3. Green. Commit `feat: loadBands + specialty_id on perConsultant`.
  4. Update `Dashboard.vue` bar rendering with band overlay and `barTone`. Vite build + commit.

---

### Item 7 — Cache heavy dashboard payload (effort S, risk Medium)

- **Goal / user value**: Cuts the dashboard load from ~15 queries to ~4 on a warm cache, making the 5-minute auto-refresh (already wired) feel instantaneous on a busy ward screen. The cache is busted on every patient-flow mutation so clinical data is never stale for operationally meaningful events.

- **Files**
  - Modify: `laravel/app/Http/Controllers/DashboardController.php`
  - Modify: `laravel/app/Http/Controllers/PatientActionController.php`
  - Modify: `laravel/tests/Feature/Phase1DashboardValueTest.php`

- **Design**

  The dashboard data divides into two freshness tiers:
  - **Live (never cached):** `$active`, `$activeIcu`, `$activeWard`, `$admissionsToday`, `$dischargesToday`, `$activeConsults`, `$boardingCount`, `$boardingWorklist`, `$myUnit`, `$alerts`, `$occupancy`, `$occupancyGauge`, `$generatedAt`. These are the numbers displayed in the hero KPI row — they must reflect the last action.
  - **Heavy (cache 5 min):** `$consultantBoard`, `$perConsultant`, `$cons` (6-month consultations), `$topDxWeek`, `$ytd`, `$trend`, `$losBuckets`. These are chart data and historical aggregations that change slowly.

  ```php
  use Illuminate\Support\Facades\Cache;

  // In DashboardController::index():
  $heavyTtl = 300; // 5 minutes — matches the auto-refresh interval
  $heavy = Cache::remember('dashboard.heavy', $heavyTtl, function () use ($today, $monthStart, $yearStart, $start30) {
      // Move the $admBy, $disBy, $trend, $cons, $losRows/$losBuckets, $topDxWeek, $ytd,
      // $perConsultant, $consultantBoard, $activity24h block here.
      // These queries are the ones that run joins on the full admissions table.
      return compact('trend', 'cons', 'losBuckets', 'topDxWeek', 'ytd',
                     'perConsultant', 'consultantBoard', 'activity24h', 'admBy', 'disBy');
  });
  // Unpack: extract($heavy) or reference by key
  ```

  The `$admBy`/`$disBy` maps must remain in `$heavy` since the delta computation (Item 2) consumes them. Pass them out of the cache closure and extract before the delta block.

  *Cache busting in `PatientActionController` — add a single helper called at the end of every state-changing method:*
  ```php
  private function bustDashboardCache(): void
  {
      Cache::forget('dashboard.heavy');
  }
  ```
  Call `$this->bustDashboardCache()` at the end of: `assign`, `bulkReassign`, `assignToMe`, `shuffle`, `medicalDischarge`, `completeDischarge`, `icuDischarge`, `transfer`, `modify`, `destroy`, `toggleLongterm`. These are all the methods that mutate `admissions` rows, which is what the heavy cache aggregates.

  The live KPI block (census, today's counts, boarding) stays outside the cache and runs on every request — approximately 6–8 fast single-table COUNT queries.

  **Cache key strategy:** `dashboard.heavy` is a single global key (not per-user) because all heavy data is unit-wide and role-independent. The `myUnit` data is per-user and is already computed live.

- **Decisions & defaults**
  - TTL = 300 seconds = 5 minutes, matching the auto-refresh interval. On a bust (patient action), the next request regenerates immediately. No "cache stampede" risk at this scale (one hospital, one dashboard tab typically).
  - The cache driver is whatever Laravel default is configured (file cache is fine for this scale; Redis is better on production — not specified here, ops concern).
  - Do NOT cache `$alerts`, `$myUnit`, or any live KPI. These change with patient actions that bust the cache, but they are cheap single-table COUNTs.
  - The `$donutTb`, `$donutTotal`, `$mix`, `$recent` blocks are borderline. Include `$mix` and `$donutTb`/`$donutTotal` in the heavy cache (they join the full admissions table). Keep `$recent` outside (it's a simple ORDER BY DESC LIMIT 8 on admissions — fast and should show the most recent admission).

- **Test plan**
  - `test_cache_is_busted_after_medical_discharge` — GET `/` (primes cache). Then POST medical-discharge. GET `/` again. Assert the boarding count in the response reflects the updated state (i.e., the cache was not served stale).
  - `test_heavy_data_is_served_from_cache_on_second_request` — prime then verify the same response is returned without hitting the heavy query block (use `DB::enableQueryLog()` before second GET and assert fewer queries than the first).
  - `test_live_kpis_always_reflect_current_state` — seed 1 active patient, GET `/`, assert `census===1`. Add another admission directly via DB (bypassing cache bust). GET `/` again. Assert `census===2` (live tier is never cached).

- **Build sequence**
  1. Write `test_cache_is_busted_after_medical_discharge` — red (cache isn't wired yet, so it may accidentally pass; verify it fails after a manual check).
  2. Wrap heavy block in `Cache::remember` in `DashboardController`.
  3. Add `bustDashboardCache()` to `PatientActionController` and call it in all mutation methods.
  4. Green. Commit `feat: cache dashboard heavy payload; bust on patient mutations`.

---

### Item 8 — Value tests for new derived metrics (effort S, risk Low)

- **Goal / user value**: Extends the `StatisticsValueTest` discipline to every new metric in this phase. Any future refactor of the boarding, delta, or alert computation breaks a test before it breaks production.

- **Files**
  - Modify: `laravel/tests/Feature/Phase1DashboardValueTest.php` (consolidates all value tests from Items 1–7 above into one file)

- **Design**

  All value tests are written inline in the descriptions above. This item collects them into a single test class with a shared fixture. The fixture design:

  ```php
  // Phase1DashboardValueTest fixture:
  //   P1: Ward, active (no discharge), no medical_discharge_date  → active, non-boarding
  //   P2: Ward, active, medical_discharge_date = 5 days ago       → active + BOARDING, delay=5
  //   P3: Ward, active, medical_discharge_date = 2 days ago       → active + BOARDING, delay=2
  //   P4: Ward, fully discharged (discharge_date set)             → not active
  //   P5: ICU, active                                             → ICU, not boarding
  //   P6-prev: Ward, discharged 8d ago (real discharge)           → readmit anchor
  //   P6: Ward, admitted 7d ago (within 8d of P6-prev discharge)  → READMISSION
  //   DeathA: discharged this month, outcome=Dead
  //   DeathB: discharged prior month, outcome=Dead
  // Settings: ward_beds=10, alert_overcensus_pct=60 (so 6 ward non-ICU / 10 = 60% triggers it)
  ```

  Full test list (consolidating all test method names from Items 1–7 above):

  1. `test_boarding_count_matches_independent_sql`
  2. `test_boarding_worklist_ranks_by_delay_desc`
  3. `test_boarding_count_zero_when_none`
  4. `test_boarding_board_filter_returns_only_medically_discharged`
  5. `test_deltas_admissions_uses_7day_mean_from_admBy_map`
  6. `test_deltas_deaths_month_compares_to_prior_calendar_month`
  7. `test_deltas_flat_when_equal`
  8. `test_alert_fires_when_occupancy_at_threshold`
  9. `test_alert_does_not_fire_below_threshold`
  10. `test_alert_boarding_fires_above_max`
  11. `test_alert_deaths_delta_fires_on_sharp_rise`
  12. `test_alert_deaths_delta_no_fire_within_threshold`
  13. `test_alert_readmit_rate_fires` — uses `Admission::readmissionJoin()` independently (same discipline as `StatisticsValueTest::test_null_typed_prior_discharge_counts_as_readmission_anchor`)
  14. `test_alerts_empty_when_all_within_bounds`
  15. `test_my_unit_null_for_non_consultant`
  16. `test_my_unit_populated_for_consultant`
  17. `test_my_unit_sign_pending_counts_only_this_consultant`
  18. `test_my_unit_consults_awaiting_signoff`
  19. `test_load_bands_passed_to_dashboard`
  20. `test_perConsultant_includes_specialty_id`
  21. `test_patients_board_accepts_consultant_id_filter`
  22. `test_patients_board_consultant_id_filter_respects_d1_scope`
  23. `test_patients_board_specialty_id_filter`
  24. `test_perConsultant_includes_id`
  25. `test_cache_is_busted_after_medical_discharge`
  26. `test_live_kpis_always_reflect_current_state`

  Each boarding/alert value test asserts both the Inertia prop value AND independently re-runs the defining SQL to confirm they match — the same "value test" pattern established in `StatisticsValueTest.php`.

- **Build sequence**
  1. Create `Phase1DashboardValueTest.php` with `seedFixture()` and the shared `admin()`/`consultant()` helpers.
  2. Write all tests red-first before implementing each item (TDD discipline — tests for Items 1–6 are written as the first step of each item's build sequence above).
  3. After all items are implemented, run the full suite: `php artisan test --filter Phase1DashboardValueTest`.
  4. Commit `test: Phase 1 dashboard value tests (boarding, deltas, alerts, myUnit, load-fairness, cache)`.

---

## Risks & sequencing notes

- **Build order matters for shared data:** Items 2 (deltas) and 4 (alerts) both consume `$deathsPrior`. Item 4 also consumes `$boardingCount` from Item 1. Implement in order 1 → 2 → 4 to avoid referencing undefined variables in the controller.

- **`consultantBoard` GROUP BY expansion (Item 3):** Adding `u.id` to the SELECT requires adding it to the GROUP BY. The existing alias `consultant` groups by the computed name — adding a concrete `u.id` to GROUP BY is a safe, non-breaking change, but must be verified on MariaDB 10.11 (production) where `only_full_group_by` is ON by default. The existing comment at DashboardController line 112 already documents this constraint.

- **Cache TTL vs auto-refresh interval:** The 5-minute cache TTL (Item 7) matches the 5-minute auto-refresh in `Dashboard.vue` (line 150). If the TTL is shortened, the refresh interval should match to avoid over-fetching. If a patient action busts the cache, the next auto-refresh (up to 5 min later) gets fresh data — the live KPI tier (census, boarding count) always reflects current state, so the only staleness is in chart history.

- **`specialty_id` on `perConsultant` (Item 6):** The `perConsultant` query's GROUP BY currently groups by `consultant` alias and `u.on_service, u.specialty_id` (DashboardController line 141). `u.specialty_id` is already in the SELECT for `consultantBoard` but NOT in `perConsultant`. Item 6 adds it. Verify the LIMIT 8 still fires after the GROUP BY expansion — it does, as LIMIT applies after aggregation.

- **`alert_readmit_rate_pct` query (Item 4):** The readmission count inside the alert block runs `Admission::readmissionJoin()` which is a self-join on `admissions`. On a 15,000-row table this is fast (the join keys are indexed via `patient_id` and `admit_date`). With the Item 7 cache, this alert-tier query runs on every request since alerts are in the live tier. If it becomes slow, move the readmit alert into the heavy cache tier (it changes slowly).

- **Observer role (role=5):** The `$myUnit` block checks `(int) $viewer->role === User::ROLE_CONSULTANT` and returns `null` for all other roles including Observer. The boarding filter added to `PatientsController::boardGroups` is read-only (GET only) and Observers can already access `/patients`. No write surface is added in this phase.

- **Vite build + commit on every Vue change:** Per the project constraint ("public/build assets are committed, server has no npm"), every Dashboard.vue or Patients/Index.vue change must be followed by `npm run build` and a commit of the compiled `public/build` directory. This applies to Items 1, 2, 3, 4, 5, 6 — plan for a single final build pass after all Vue changes are done in a feature branch to avoid redundant rebuild commits mid-item.

---

## Phase 2 — Audit & accountability

Phase 0 established that every state-changing endpoint writes an `AuditLog` row via inline `AuditLog::create([...])` calls. Phase 2 builds the visibility layer on top of that foundation: a paginated, filterable admin viewer with Excel export; a per-patient activity panel embedded in the existing Modify modal and the new admission detail; PHI-read logging on registry search and export; field-level before/after diffs captured by a reusable helper; and an optional tamper-evident hash chain for the audit table. Nothing in this phase alters the writing side — all items are additive.

---

### Item 1 — Audit-log viewer (effort M, risk Low)

- **Goal / user value**: Admins get a single, searchable, exportable view of every audit event in the system — who did what to which patient, when, and from which IP. Meets HIPAA/CBAHI accountability requirements.

- **Files**
  - CREATE `laravel/app/Http/Controllers/AuditController.php`
  - CREATE `laravel/app/Http/Requests/AuditFilterRequest.php`
  - CREATE `laravel/resources/js/Pages/Audit/Index.vue`
  - MODIFY `laravel/routes/web.php` — add two routes inside the existing `admin` middleware group
  - MODIFY `laravel/resources/js/Layouts/AppLayout.vue` — add "Audit Log" entry to the `admin` nav array

- **Design**

  **Migration** — none required; the `audit_log` table already exists (`2026_06_08_120006_create_settings_and_audit.php`, line 23). Add one composite index via a new migration:

  ```
  laravel/database/migrations/2026_06_14_000001_add_audit_log_composite_index.php
  ```
  The index is `(entity_type, entity_id)` — needed for Item 2's per-patient lookup (see Item 2 below). Add it here rather than in a separate migration to keep Phase 2 migrations together.

  **Routes** (inside the existing `Route::middleware('admin')->group(...)` block, after `/control`):
  ```
  GET  /audit          AuditController@index     audit.index
  GET  /audit/export   AuditController@export    audit.export
  ```

  **`AuditFilterRequest`** carries `authorize()` (calls `$this->user()->isAdmin()`) and `rules()`:
  - `actor_id` — nullable, exists:users,id
  - `action` — nullable, string, max:64
  - `entity_type` — nullable, string, max:64
  - `entity_id` — nullable, string, max:64
  - `from` — nullable, date
  - `to` — nullable, date
  - `ip` — nullable, string, max:45

  **`AuditController@index`** — Inertia response to `Audit/Index`:
  ```php
  public function index(AuditFilterRequest $request): Response
  ```
  Query: `AuditLog::query()` with `->when()` chains on each filter field; `->with('actor:id,full_name,name')` eager-load (actor_id FK already exists); `->latest('created_at')->paginate(50)->withQueryString()`. Map each row to a plain array: `id, actor_name, actor_id, action, entity_type, entity_id, details (decoded), ip, created_at`. Pass `actors` (distinct actor names for the actor filter dropdown) and `entityTypes` (distinct entity_type values) as option lists — both use `AuditLog::distinct()->orderBy(...)->pluck(...)` calls, capped at 100 rows.

  Category grouping: derive `category` from the `action` prefix on the PHP side before mapping (`explode('.', $action)[0]`). Pass a `categories` prop listing the unique categories present.

  **`AuditController@export`** — reuse `RegistryController`'s `writeExport` pattern exactly: a `writeExport(AuditFilterRequest $request, callable $write): void` private method shared by `export()` (CSV via `response()->streamDownload`) and a future xlsx variant. The same query as `index` but without pagination: `->chunk(500, ...)`. Columns: `ID, Date/Time, Actor, Action, Entity Type, Entity ID, Details (JSON), IP`. `AuditLog` rows carry no PHI directly in their columns (PHI is in `details` JSON) — export the raw JSON string; downstream tools can parse it. Filename: `Audit-Export-DD-MM-YYYY.csv` (legacy filename convention from `RegistryController::exportFilename()`).

  **`Pages/Audit/Index.vue`** props: `{ logs: Object (paginated), filters: Object, actors: Array, entityTypes: Array, categories: Array }`. Filter bar: actor dropdown, action text input, entity_type select, entity_id text, date-range pair (from/to), IP text. Apply button calls `router.get('/audit', { ...f }, { preserveState: true })`. Table columns: Date/Time, Actor, Action (badge coloured by category), Entity Type + ID (linked if entity_type is `admission` or `consultation`), Details (collapsible inline JSON viewer: `<pre>` with `JSON.stringify(details, null, 2)`), IP. Pagination via standard `<Link>` component pattern (as used in `Registry/Index.vue`). Export button: `<a :href="'/audit/export?' + qs">` where `qs` is computed from current filters — same pattern as the registry export link. Dark-mode-safe detail panel: use `bg-ink-50 dark:bg-ink-900 font-mono text-xs` tokens.

- **Decisions & defaults**
  - Page size 50 (audit rows are more uniform than registry rows; 50 keeps the table scannable).
  - No per-row delete or edit — the model will be write-protected in Item 5; any purge is a DBA operation.
  - The `actor` eager load is a single JOIN; no N+1.
  - Details JSON rendered as a collapsible `<pre>` — not a structured table — because `details` schema varies per action. Keep it simple and correct.

- **Test plan**

  File: `laravel/tests/Feature/AuditViewerTest.php`

  - `test_non_admin_cannot_access_audit_log` — Consultant `GET /audit` → 403.
  - `test_admin_can_paginate_audit_log` — create 3 `AuditLog` rows, `GET /audit` as admin → `assertInertia` `logs.data` has 3 entries.
  - `test_filter_by_action` — create rows with actions `admission.discharge` and `user.update`; `GET /audit?action=user.update` → only user.update rows in `logs.data`.
  - `test_filter_by_entity_type_and_id` — create rows for entity_type `admission` id `42` and `consultation` id `7`; filter both → correct subsets.
  - `test_filter_by_date_range` — rows at 2024-01-10 and 2024-03-05; `from=2024-02-01` → only the March row returned.
  - `test_export_returns_csv` — `GET /audit/export` as admin → 200 `text/csv`, content contains header row `ID,Date/Time,Actor,...`.
  - `test_export_applies_same_filters` — create 2 rows, export with `action=user.update` filter → CSV contains exactly 1 data row.

- **Build sequence**
  1. Write `AuditViewerTest` with all test stubs marked `markTestIncomplete`. Run: all incomplete (green baseline).
  2. Create migration `2026_06_14_000001_add_audit_log_composite_index.php` adding `(entity_type, entity_id)` index on `audit_log`. Run `php artisan migrate`. Implement `AuditFilterRequest`. Tests still incomplete.
  3. Implement `AuditController@index` skeleton returning `Inertia::render('Audit/Index', [...])`. Create `Pages/Audit/Index.vue` with minimal table. Remove `markTestIncomplete` from paginate/filter tests — they should go green.
  4. Implement `AuditController@export`. Remove CSV test stubs — green.
  5. Add routes to `routes/web.php` and nav entry to `AppLayout.vue`. `php artisan route:list` smoke check. Run full suite.
  6. Build front-end (`npm run build`), commit `public/build`.

---

### Item 2 — Per-patient activity panel (effort S, risk Low)

- **Goal / user value**: Anyone opening the Modify modal or the admission edit page sees a chronological strip of every action taken on that admission — essential for clinical accountability and debugging.

- **Files**
  - MODIFY `laravel/app/Http/Controllers/AdmissionsController.php` — extend the `edit` JSON response
  - MODIFY `laravel/resources/js/Pages/Registry/Index.vue` — render the activity strip in the edit modal
  - MODIFY `laravel/resources/js/Pages/Patients/Index.vue` — render the same strip in the board Modify modal (if one exists; otherwise the Patients board uses the same `AdmissionsController@edit` fetch)
  - The migration from Item 1 (`2026_06_14_000001`) already adds the composite index needed here

- **Design**

  **Index** — `(entity_type, entity_id)` composite index added in Item 1 migration covers the lookup `WHERE entity_type = 'admission' AND entity_id = ?`. This replaces a full-table scan on what will become a 100k+ row table.

  **`AdmissionsController@edit`** currently returns a JSON object for the Modify form pre-fill (fetched via `fetch('/admissions/{id}/edit', {Accept:'application/json'})` at `Registry/Index.vue:49`). Extend its response with an `activity` key:

  ```php
  'activity' => AuditLog::where('entity_type', 'admission')
      ->where('entity_id', (string) $admission->id)
      ->latest('created_at')
      ->limit(50)
      ->get(['id', 'action', 'actor_name', 'details', 'created_at'])
      ->map(fn ($row) => [
          'id'         => $row->id,
          'action'     => $row->action,
          'actor'      => $row->actor_name,
          'details'    => $row->details,   // already cast to array via AuditLog::$casts
          'at'         => $row->created_at->toIso8601String(),
      ])
      ->all(),
  ```

  Limit 50 rows (a busy admission will have ~20 events; 50 covers edge cases without pagination overhead).

  **Vue component** — CREATE `laravel/resources/js/Components/ActivityPanel.vue`:

  ```
  props: { items: Array }   // the 'activity' array from the edit fetch
  ```

  Renders a `<ol>` timeline list. Each entry: `action` as a short label (map `admission.discharge` → "Discharged", etc. via a `ACTION_LABELS` const object in the component — ~25 entries covering every action emitted by `PatientActionController`, `ConsultationsController`, `ControlController`), `actor` name, relative timestamp (formatted as "DD Mon HH:MM"), and a collapsible `<details>` element containing the decoded `details` object as a `<dl>` of key/value pairs. For field-level diffs (Item 4), if a details key ends in `_was`, pair it with the base key and render as "Field: old → new".

  Import `ActivityPanel` in both `Registry/Index.vue` (inside the edit modal, below the form fields, in a collapsible section "Activity") and `Patients/Index.vue` (in the Modify modal if it performs the same `/admissions/{id}/edit` fetch — inspect: the board's Modify modal uses the same fetch pattern set up in `PatientActionController`; if the board modal does not yet fetch from `edit`, add the `activity` key to that fetch as well under the same endpoint).

  **Styling**: the timeline uses `bg-card rounded-xl p-4` outer wrapper, `border-l-2 border-brand-200 dark:border-brand-800 pl-4` for the item track, `text-ink-400 text-xs .nums` for timestamps. Actions tagged as destructive (`*.delete`, `*.reverse*`) use `text-danger-600`; PHI reads (Item 3) use `text-warning-500`.

- **Decisions & defaults**
  - Limit 50 rows at the server — if a specific case needs the full trail, the admin viewer (Item 1) filters by entity.
  - The `edit` endpoint is already admin-or-can_modify gated via `ModifyAdmissionRequest::authorize()` (`laravel/app/Http/Requests/ModifyAdmissionRequest.php:19-23`), so the activity data is behind the same gate with no additional authorization needed.
  - `ActivityPanel` is a pure presentational component — no router calls, no props mutation.

- **Test plan**

  File: `laravel/tests/Feature/AuditViewerTest.php` (extend the same file)

  - `test_edit_endpoint_returns_activity_for_admission` — create an admission, create 2 `AuditLog` rows with `entity_type=admission, entity_id={id}`, and 1 row with a different entity_id. `GET /admissions/{id}/edit` as admin with `Accept: application/json` → response JSON `activity` has exactly 2 entries, ordered latest-first.
  - `test_edit_endpoint_activity_limited_to_50` — create 55 audit rows for one admission → `activity` count is 50.
  - `test_composite_index_used_for_activity_lookup` — verify the index exists via `Schema::hasIndex('audit_log', 'audit_log_entity_type_entity_id_index')`.

- **Build sequence**
  1. Add composite index tests → red (index doesn't exist yet, but Item 1 step 2 creates it → green after that step).
  2. Write `test_edit_endpoint_returns_activity_for_admission` → red. Extend `AdmissionsController@edit` to append `activity`. → green.
  3. Create `ActivityPanel.vue`. Integrate into `Registry/Index.vue` edit modal. Visual verification in browser.
  4. Integrate into `Patients/Index.vue` Modify modal if applicable. Run full suite.

---

### Item 3 — Log PHI reads (break-glass) (effort S, risk Low)

- **Goal / user value**: Every bulk export and registry search that returns patient-identifiable data is logged with the actor, applied filters, and result count — satisfying break-glass / PHI-access audit requirements without logging every individual record open.

- **Files**
  - MODIFY `laravel/app/Http/Controllers/RegistryController.php` — add three `AuditLog::create(...)` calls

- **Design**

  Three insertion points, each writing **one row per request**:

  **1. Registry search (`index` method, end of method after `$results` is built)**

  The `index` method already returns a paginated result. The `total` count is available on the `LengthAwarePaginator`. Add after `$results` is assigned:

  ```php
  AuditLog::create([
      'actor_id'    => Auth::id(),
      'actor_name'  => Auth::user()->name,
      'action'      => 'registry.search',
      'entity_type' => 'registry',
      'entity_id'   => null,
      'details'     => [
          'mode'         => $mode,
          'filters'      => $this->redactedFilters($request),
          'result_count' => $results->total(),
      ],
      'ip' => $request->ip(),
  ]);
  ```

  `redactedFilters()` is a private helper that returns `$request->only([...all filter keys...])` but replaces the `search` value (patient name/MRN free text) with the character count: `['search' => strlen($search) . ' chars']`. This prevents PHI from appearing in the audit log itself while preserving the fact that a search was made with a term. All other filters (dates, outcomes, consultant IDs) are kept verbatim as they contain no PHI.

  **2. CSV export (`export` method)**

  The `writeExport` helper performs a `chunk(500, ...)` loop; the total is not computed separately. Run `$query->count()` before the chunk loop (one extra COUNT query — cheap). Add after stream setup:

  ```php
  AuditLog::create([
      'actor_id'   => Auth::id(), 'actor_name' => Auth::user()->name,
      'action'     => 'registry.export',
      'entity_type'=> 'registry', 'entity_id' => null,
      'details'    => ['mode' => $this->mode($request), 'filters' => $this->redactedFilters($request), 'row_count' => $rowCount],
      'ip'         => $request->ip(),
  ]);
  ```

  **3. XLSX export (`exportXlsx` method)** — identical structure, action `registry.export_xlsx`.

  `redactedFilters()` private helper:
  ```php
  private function redactedFilters(Request $request): array
  {
      $f = $request->only([/* all registry filter keys as listed in index() */]);
      if (!empty($f['search'])) {
          $f['search'] = mb_strlen((string)$f['search']) . ' chars';
      }
      if (!empty($f['keyword'])) {
          $f['keyword'] = mb_strlen((string)$f['keyword']) . ' chars';
      }
      return array_filter($f, fn($v) => $v !== null && $v !== '' && $v !== [] && $v !== false);
  }
  ```

  No migration required.

- **Decisions & defaults**
  - Log on every search (including zero-result queries) — deliberate, matches break-glass intent.
  - Do not log per-record opens from the expandable row detail (the row data is already in the paginated result). The setting mentioned in the task brief is dropped as it adds complexity without clinical value — the search-level log covers the access event.
  - PHI in the `search` and `keyword` fields is redacted to length only. All other filters are non-PHI (dates, outcome enums, consultant IDs) and kept in full for investigative usefulness.

- **Test plan**

  File: `laravel/tests/Feature/AuditViewerTest.php` (extend)

  - `test_registry_search_writes_audit_row` — `GET /registry?mode=admissions` as admin → `AuditLog::where('action','registry.search')->count()` === 1; `details.mode` === 'admissions'.
  - `test_registry_search_redacts_search_term` — `GET /registry?search=JohnDoe` → audit row `details.filters.search` === '7 chars', not 'JohnDoe'.
  - `test_registry_export_writes_audit_row` — `GET /registry/export` as admin → audit row `action=registry.export`, `details.row_count` is an integer.
  - `test_registry_export_xlsx_writes_audit_row` — `GET /registry/export-xlsx` → audit row `action=registry.export_xlsx`.
  - `test_single_audit_row_per_search` — hit `/registry` three times → exactly 3 `registry.search` rows (one per request).

- **Build sequence**
  1. Write all five test stubs → red.
  2. Add `redactedFilters()` private method to `RegistryController`. Add `AuditLog::create(...)` to `index()`. → 2 tests green.
  3. Add `AuditLog::create(...)` to `export()` and `exportXlsx()`. → remaining tests green.
  4. Run full suite. Commit.

---

### Item 4 — Field-level before/after diffs (effort M, risk Low–Medium)

- **Goal / user value**: Audit log entries for modify/update actions show exactly which fields changed and what the old and new values were — making the activity panel actionable for clinical governance (was the admit date corrected? was the outcome changed?).

- **Files**
  - CREATE `laravel/app/Support/AuditDiff.php` — the diff helper
  - MODIFY `laravel/app/Http/Controllers/PatientActionController.php` — use in `modify()`
  - MODIFY `laravel/app/Http/Controllers/ConsultationsController.php` — use in `update()`
  - MODIFY `laravel/app/Http/Controllers/ControlController.php` — use in `updateUser()`
  - MODIFY `laravel/resources/js/Components/ActivityPanel.vue` — render diffs visually

- **Design**

  **`AuditDiff`** static helper class:

  ```php
  namespace App\Support;

  class AuditDiff
  {
      /**
       * Compute {field: {from: oldVal, to: newVal}} for all fields that changed.
       * $before = assoc array of old values (keyed by field name).
       * $after  = assoc array of new values (same keys; extras in $after are included as new fields).
       * $omit   = field names to skip entirely (passwords, internal flags).
       */
      public static function diff(array $before, array $after, array $omit = []): array
      {
          $changes = [];
          $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
          foreach ($keys as $key) {
              if (in_array($key, $omit, true)) continue;
              $old = $before[$key] ?? null;
              $new = $after[$key] ?? null;
              // cast both sides to string for comparison (avoids int/string false positives)
              if ((string)($old ?? '') !== (string)($new ?? '')) {
                  $changes[$key] = ['from' => $old, 'to' => $new];
              }
          }
          return $changes;
      }

      /**
       * Convenience: diff an Eloquent model's dirty fields using getOriginal() / getAttributes().
       * Call BEFORE ->save() / ->update() to capture the original values.
       * Returns the same {field:{from,to}} shape.
       */
      public static function fromModel(\Illuminate\Database\Eloquent\Model $model, array $omit = []): array
      {
          $dirty = array_keys($model->getDirty());
          $before = array_intersect_key($model->getOriginal(), array_flip($dirty));
          $after  = array_intersect_key($model->getAttributes(), array_flip($dirty));
          return self::diff($before, $after, $omit);
      }

      /**
       * Diagnosis code set diff: {added:[...codes], removed:[...codes]}.
       * $oldCodes and $newCodes are flat arrays of ICD-10 code strings.
       */
      public static function diagnosisDiff(array $oldCodes, array $newCodes): ?array
      {
          $added   = array_values(array_diff($newCodes, $oldCodes));
          $removed = array_values(array_diff($oldCodes, $newCodes));
          if (!$added && !$removed) return null;
          return ['added' => $added, 'removed' => $removed];
      }
  }
  ```

  **Integration points:**

  **`PatientActionController::modify()`** — currently the audit call at line 199 passes `['mrn' => ..., 'admit_date_was' => ..., 'consultant_id' => ..., 'consultant_was' => ...]`. Replace the bespoke detail-building block with `AuditDiff`:

  Capture before the `DB::transaction(...)`:
  ```php
  // snapshot before mutation (model not yet dirty)
  $oldPatient = $patient->only(['mrn', 'name', 'age', 'gender', 'nationality']);
  $oldAdmission = $admission->only(['bed', 'admit_date', 'admitted_from', 'current_location', 'consultant_id']);
  $oldCodes = $admission->diagnoses->pluck('icd10_code')->sort()->values()->all();
  ```
  After the transaction, build details:
  ```php
  $admission->refresh(); $patient->refresh();
  $newCodes = $admission->diagnoses()->orderBy('seq')->pluck('icd10_code')->all();
  $details = array_merge(
      AuditDiff::diff($oldPatient, $patient->only(['mrn','name','age','gender','nationality'])),
      AuditDiff::diff($oldAdmission, $admission->only(['bed','admit_date','admitted_from','current_location','consultant_id'])),
  );
  $dxDiff = AuditDiff::diagnosisDiff($oldCodes, $newCodes);
  if ($dxDiff) $details['diagnoses'] = $dxDiff;
  $this->audit('patient.modify', $admission, $details);
  ```
  The existing `$oldAdmitDate`/`$oldConsultant` snapshot variables are superseded by `AuditDiff::diff()` — remove them.

  **`ConsultationsController::update()`** — currently logs `action=consultation.modify` with no details (line 140). Add diff:
  ```php
  $before = $consultation->only(['patient_name','mrn','age','bed','current_location',
      'consultation_from','to_service','consultant_id','indication','other_indication']);
  $data = $request->validated();
  $consultation->update([...$data, 'indication' => $data['indication'] ?? []]);
  $after = $consultation->fresh()->only(array_keys($before));
  $diff = AuditDiff::diff($before, $after);
  AuditLog::create([..., 'details' => $diff, ...]);
  ```

  **`ControlController::updateUser()`** — currently logs all `$data` fields (line 128, includes sensitive fields like `can_*`). Replace with a targeted diff:
  ```php
  $before = $user->only(['username','full_name','email','role','active','on_service',
      'specialty_id','can_assign','can_add','can_manage','can_modify']);
  $user->update($data);
  $after = $user->fresh()->only(array_keys($before));
  $diff = AuditDiff::diff($before, $after, ['password']);
  AuditLog::create([..., 'details' => $diff, ...]);
  ```

  **`ActivityPanel.vue`** — update the `<details>` rendering: if a detail value is an object with `from` and `to` keys, render it as `<span class="text-danger-500 line-through">old</span> → <span class="text-brand-600">new</span>`. If the key is `diagnoses` and value has `added`/`removed` arrays, render two badge lists. For all other detail values, render as a plain `key: value` pair (existing behavior).

- **Decisions & defaults**
  - `fromModel()` is provided but not used in the primary three callsites — the `only()` snapshot pattern is safer because those callsites do complex multi-step mutations inside transactions. `fromModel()` is available for simpler future callsites.
  - `omit` defaults to `[]`. The three callsites do not include passwords (those are changed via `ProfileController`, not `ControlController::updateUser`).
  - Diagnosis diff is stored as `{added:[codes], removed:[codes]}` — code strings only, not names. The `ActivityPanel` renders them as code strings; the ICD-10 name lookup is a separate concern not needed in the audit trail.
  - Boolean capability-flag changes (`can_assign: {from: false, to: true}`) appear literally — acceptable for an admin-only audit viewer.

- **Test plan**

  File: `laravel/tests/Feature/AuditDiffTest.php`

  - `test_diff_detects_changed_fields` — `AuditDiff::diff(['a'=>1,'b'=>2], ['a'=>1,'b'=>3])` → `['b'=>['from'=>1,'to'=>3]]`.
  - `test_diff_omits_unchanged_fields` — `AuditDiff::diff(['a'=>1], ['a'=>1])` → `[]`.
  - `test_diff_omits_excluded_keys` — pass `omit=['password']`, password change → not in output.
  - `test_diagnosis_diff_added_and_removed` — `diagnosisDiff(['A01','A02'], ['A02','A03'])` → `added=['A03'], removed=['A01']`.
  - `test_diagnosis_diff_returns_null_when_unchanged` — same codes both sides → null.
  - `test_patient_modify_audit_contains_diff` (Feature) — create admission, POST `modify` changing `bed` and `admit_date`, check `AuditLog::latest()->first()->details` contains `bed` and `admit_date` keys with `from`/`to` shape.
  - `test_consultation_update_audit_contains_diff` (Feature) — `PUT /consultations/{id}` changing `to_service` → audit `details.to_service.from` / `.to` present.
  - `test_user_update_audit_contains_diff` (Feature) — `PUT /control/users/{id}` changing `role` → audit `details.role.from` / `.to` present.

- **Build sequence**
  1. Write `AuditDiffTest` unit tests → red. Implement `AuditDiff` class → unit tests green.
  2. Write Feature tests for `patient.modify`, `consultation.modify`, `user.update` diffs → red.
  3. Integrate `AuditDiff` into `PatientActionController::modify()`. → `test_patient_modify_audit_contains_diff` green.
  4. Integrate into `ConsultationsController::update()`. → consultation diff test green.
  5. Integrate into `ControlController::updateUser()`. → user diff test green.
  6. Update `ActivityPanel.vue` to render diff keys. Visual verification.
  7. Run full suite. Commit.

---

### Item 5 — Tamper-evident hash chain (effort L, risk Medium)

- **Goal / user value**: Each audit row carries a SHA-256 hash of its content chained to the previous row's hash. An artisan command walks the chain and flags any gap or mismatch — providing cryptographic evidence that audit rows have not been silently deleted or edited after the fact. The viewer shows "integrity verified through {date}" when the chain is intact.

- **Files**
  - CREATE `laravel/database/migrations/2026_06_14_000002_add_hash_chain_to_audit_log.php`
  - MODIFY `laravel/app/Models/AuditLog.php` — `creating` observer writes `row_hash`; model-level guard blocks `update()`/`delete()`
  - CREATE `laravel/app/Console/Commands/AuditVerify.php`
  - MODIFY `laravel/app/Http/Controllers/AuditController.php` — expose `integrityThrough` prop
  - MODIFY `laravel/resources/js/Pages/Audit/Index.vue` — display the verified-through badge

- **Design**

  **Migration** `2026_06_14_000002`:
  ```php
  Schema::table('audit_log', function (Blueprint $table) {
      $table->string('prev_hash', 64)->nullable()->after('ip');    // null for the first row
      $table->string('row_hash', 64)->nullable()->after('prev_hash'); // sha256 of canonical form
  });
  ```
  Both nullable to allow the backfill pass on existing rows.

  **Canonical form** (`AuditLog::canonical()`): a deterministic JSON string of the fixed fields in alphabetical key order:
  ```php
  public function canonical(): string
  {
      return json_encode([
          'action'      => $this->action,
          'actor_id'    => $this->actor_id,
          'actor_name'  => $this->actor_name,
          'created_at'  => $this->created_at?->toIso8601String(),
          'details'     => $this->details,   // already array-cast
          'entity_id'   => $this->entity_id,
          'entity_type' => $this->entity_type,
          'id'          => $this->id,
          'ip'          => $this->ip,
          'prev_hash'   => $this->prev_hash,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  ```

  **Model observer** in `AuditLog::booted()`:
  ```php
  protected static function booted(): void
  {
      static::creating(function (self $row) {
          // Fetch the previous row's hash inside a lock to avoid a race on concurrent writers.
          // A single advisory lock per table is acceptable: audit writes are infrequent
          // (< 1 per second) and never on the critical-path of a clinical action.
          $prev = DB::table('audit_log')->lockForUpdate()->latest('id')->value('row_hash');
          $row->prev_hash = $prev;
          // id is not yet assigned — set it after INSERT via created event, or
          // compute hash after insert in the 'created' hook below.
      });

      static::created(function (self $row) {
          // Now id is assigned; compute the final hash and stamp it back.
          $hash = hash('sha256', $row->canonical());
          DB::table('audit_log')->where('id', $row->id)->update(['row_hash' => $hash]);
          $row->row_hash = $hash;   // keep in-memory copy consistent
      });

      // Block any subsequent update or delete at the model layer.
      static::updating(function () {
          throw new \RuntimeException('AuditLog rows are immutable — use append-only insert.');
      });
      static::deleting(function () {
          throw new \RuntimeException('AuditLog rows cannot be deleted via the ORM.');
      });
  }
  ```

  The `updating` / `deleting` guards throw on any ORM-level mutation. DBA-level SQL bypasses this — that is acceptable; the chain walk detects it.

  **`AuditVerify` artisan command** (`php artisan audit:verify [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]`):

  Walks rows in ascending `id` order in chunks of 1000. For each row:
  1. Re-compute `hash('sha256', $row->canonical())`.
  2. Compare to `$row->row_hash` — mismatch → report row id + store in `$broken[]`.
  3. Compare `$row->prev_hash` to the previous row's `row_hash` — gap or mismatch → report.
  4. On pass: output "Chain intact: {n} rows verified through {last created_at}."
  5. On fail: output each broken row and exit code 1 (CI-friendly).

  **`AuditController@index`** — add an `integrityThrough` prop: the `created_at` of the latest row whose `row_hash` is non-null, fetched cheaply as `AuditLog::whereNotNull('row_hash')->latest('id')->value('created_at')`. This is not a full chain walk — just a "last verified timestamp" indicator. The full walk is the artisan command (run nightly by the ops cron).

  **`Pages/Audit/Index.vue`** — show a small badge at the top of the page: "Hash chain intact through {date}" in `text-brand-600` if `integrityThrough` is set, or "No integrity data yet" in `text-ink-400` if null.

- **Decisions & defaults**
  - The `creating`/`created` two-step (set prev_hash in `creating`, compute row_hash in `created`) is the only way to include `id` in the canonical form without a second pass. The `DB::table()->update()` call in `created` is deliberately direct (bypassing the model) to avoid triggering the `updating` guard.
  - `lockForUpdate()` on the `prev_hash` fetch serializes concurrent inserts at the DB level, preventing two rows from claiming the same `prev_hash`. MariaDB 10.11 and MySQL both support `SELECT ... FOR UPDATE`. The lock scope is the single-row `latest('id')` lookup — negligible contention in a clinical app with < 5 concurrent writers.
  - Existing rows will have NULL `row_hash` after the migration. Run `php artisan audit:backfill-hashes` (a simple one-off command, not built here) or accept that the chain starts from the first post-migration row. The viewer shows "chain started {date}" for clarity. Flag for ops: run backfill before first production deploy of Phase 2.
  - The `updating` guard will break any code path that calls `$auditLog->update(...)` — confirmed by grep above that no such call exists in the codebase. Safe to add.
  - This item is marked optional in the spec. It should be the last item built in Phase 2 and can be deferred to a Phase 3 deployment if operational complexity is unwelcome.

- **Test plan**

  File: `laravel/tests/Feature/AuditHashChainTest.php`

  - `test_audit_row_gets_row_hash_on_create` — `AuditLog::create([...])` → `assertNotNull($row->fresh()->row_hash)`.
  - `test_sequential_rows_chain_correctly` — create two rows; second row's `prev_hash` equals first row's `row_hash`.
  - `test_canonical_form_is_deterministic` — create a row, call `canonical()` twice → identical strings.
  - `test_model_update_throws` — `$row->update(['action' => 'tampered'])` → `RuntimeException`.
  - `test_model_delete_throws` — `$row->delete()` → `RuntimeException`.
  - `test_artisan_verify_passes_on_intact_chain` — create 5 rows, `artisan('audit:verify')` → exit code 0, output contains 'intact'.
  - `test_artisan_verify_fails_on_tampered_row` — create 3 rows, `DB::table('audit_log')->where('id', $rows[1]->id)->update(['action' => 'tampered'])`, `artisan('audit:verify')` → exit code 1.

- **Build sequence**
  1. Write `AuditHashChainTest` → all red.
  2. Create migration `2026_06_14_000002`. Run migrate. Add `canonical()` method to `AuditLog`.
  3. Add `booted()` observer hooks (`creating`, `created`, `updating`, `deleting`). → `test_audit_row_gets_row_hash`, `test_model_update_throws`, `test_model_delete_throws` green.
  4. → `test_sequential_rows_chain_correctly` and `test_canonical_form_is_deterministic` green.
  5. Implement `AuditVerify` command. Register in `Console/Kernel.php` (or `bootstrap/app.php` command discovery). → verify artisan tests green.
  6. Extend `AuditController@index` with `integrityThrough`. Update `Audit/Index.vue` badge.
  7. Run full suite — confirm `PatientActionController` tests still pass (the `creating`/`created` hooks must not break the existing write path).
  8. `npm run build`, commit `public/build`.

---

## Risks & sequencing notes

- **Item 1 must land first** — Items 2, 3, and the viewer badge of Item 5 all depend on the `AuditController` scaffolding and the composite index migration (`2026_06_14_000001`).
- **Item 4 before Item 2 (optional)** — The activity panel (Item 2) is more useful if diffs are already in the `details` JSON. Consider merging their build sequences: implement `AuditDiff` (Item 4, steps 1–2) before wiring the `ActivityPanel.vue` (Item 2, step 3).
- **Item 5 `updating`/`deleting` guards** — Although grep confirms no `AuditLog->update()` call exists today, any future Phase writing code that accidentally calls `update()` on an `AuditLog` instance will throw at runtime. Document this in `AuditLog.php` docblock. The two-step `creating`/`created` pattern uses `DB::table()` directly for the `row_hash` stamp to deliberately bypass the guard.
- **`lockForUpdate()` in `AuditLog::booted()`** — on a development SQLite test database this becomes a no-op (SQLite does not support `FOR UPDATE`). The test database must be MySQL (`dmc_test`) as specified in the codebase constraints, so this is safe for production and tests. Confirm `phpunit.xml` DB connection before running hash-chain tests.
- **Registry search logging (Item 3) fires on every paginated navigation** — expected behavior for break-glass compliance, but will produce high audit-log volume for heavy registry users. Accept this; the audit viewer filters by action so it does not interfere with other log types.
- **Build order across items**: Item 1 → Item 3 → Item 4 → Item 2 → Item 5. Items 1 and 3 are low-risk and deliver immediate value; Item 5 is the highest-complexity/risk item and should be gated on a successful staging deploy of Items 1–4.

---

## Phase 3 — Reports & analytics depth

Phase 3 converts the existing statistics and reports infrastructure from a read-only screen experience into a full analytics distribution layer. Every item builds directly on already-proven abstractions: `ReportsController::gatherBooklet` and `gather`, `StatisticsController::physician` and `seriesBy`/`buckets`, `Admission::readmissionJoin`/`REAL_DISCHARGE_TYPES`/`NON_ICU_SQL`, `ReportSvg::groupedBar`/`hBar`/`doughnut`, `RegistryController::writeExport`, and the openspout `XlsxWriter` pattern. No new metric definitions are introduced; every calculation routes through the existing single-source helpers. The phase is designed to be built in strict item order because items 4, 5, 6, 7, and 8 depend on the query machinery proven by items 1–3, and item 8 (tests) validates all previous items end-to-end.

---

### 3.1 Per-consultant scorecard PDF (effort M, risk Low)

**Goal / user value**
Admin can download a crisp 2-page A4 landscape PDF summarising one consultant over a date range, with headline KPIs and a monthly trend chart. A "Download scorecard" button appears on the Statistics page when a consultant is selected in the drill-down picker.

**Files**

- Create `laravel/app/Http/Requests/ConsultantScorecardRequest.php`
- Modify `laravel/app/Http/Controllers/ReportsController.php` — add `consultantPdf` method
- Create `laravel/resources/views/reports/consultant-pdf.blade.php`
- Modify `laravel/resources/js/Pages/Statistics/Index.vue` — add download button in the physician drill-down section
- Modify `laravel/routes/web.php` — add route inside the `admin` middleware group
- Create `laravel/tests/Feature/ConsultantScorecardTest.php`

**Design**

Route:
```
GET /reports/consultant/{user}/pdf
```
Added inside the existing `Route::middleware('admin')->group(...)` block in `routes/web.php`. The `{user}` segment is a Laravel model-bound `User`; the `EnsureAdmin` middleware already guards the group.

`ConsultantScorecardRequest` rules:
```php
'from' => ['nullable', 'date'],
'to'   => ['nullable', 'date'],
```
`authorize()` returns `$this->user()->isAdmin()`. Dates fall back to start-of-current-year / today when absent, matching the `StatisticsController::index` default logic.

`ReportsController::consultantPdf(ConsultantScorecardRequest $request, User $user)`:
1. Parse and clamp `$f`/`$t` identically to `StatisticsController::index` (copy the two-line Carbon parse + swap guard).
2. Resolve `$readmitWindow` from `Setting::current()->readmission_window_days ?? 3` (same as `StatisticsController`).
3. Build `$buckets` and `$keys` by calling the **private** helpers — extract `buckets()` and `keyExpr()` to a shared `ReportMetricTrait` (see §3.4 for the trait; for now, duplicate is acceptable as a first step and extracted in §3.4). **Default interval: month**.
4. Call `$this->physician(...)` — this method is currently private. Promote it to `protected` and move shared helpers (`buckets`, `keyExpr`, `seriesBy`) to the trait so `ReportsController` can call them.
5. The gathered `$physician` array (already contains `numbers`, `series`, `destinations`, `dischargedTo`, `topDx`) is the data contract. Add two additional fields:
   - `'unitMedianLos'` — unit-wide avg ward LOS over the same range, pulled from `ReportsController::consultantLos` (call the private method or reproduce the two-line query: `DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(discharge_date,admit_date)>=0')->avg(DB::raw('DATEDIFF(discharge_date,admit_date)')))`).
   - `'monthlyTrend'` — the `$physician['series']` data is already month-bucketed; rename/alias to make the blade template unambiguous.
6. `Pdf::loadView('reports.consultant-pdf', [...$data, 'from' => $f, 'to' => $t])->setPaper('a4', 'landscape')->download("scorecard-{$user->id}-{$f}-{$t}.pdf")`

`consultant-pdf.blade.php` structure (2 pages, mirrors annual-pdf.blade.php conventions):
- **Page 1**: `reportheader1.png` banner; consultant name + range as subtitle; 8 KPI counter cards (admissions, discharges, transToIcu, deaths, avgLos vs unit median, readmissions, consultations, signoffs) using `.counter` CSS from the existing blade; `ReportSvg::groupedBar` for the monthly trend (4-series: admissions/discharges/consultations/signoffs using `ReportSvg::SERIES_COLORS`).
- **Page 2** (`.page-last`): `ReportSvg::doughnut($physician['destinations']['labels'] zipped with data)` for discharge destinations; `ReportSvg::doughnut` for discharged-to; top-5 diagnosis table.

The unit-median LOS comparison renders as: `"{{ $physician['numbers']['avgLos'] }}d (unit median {{ round($unitMedianLos,1) }}d)"` in the counter card value field.

Vue change in `Statistics/Index.vue`: inside the existing `v-if="physician"` block, below the physNumbers grid, add:
```html
<a :href="`/reports/consultant/${physician.id}/pdf?from=${from}&to=${to}`"
   class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">
  Download scorecard PDF
</a>
```

**Decisions & defaults**
- Default date range when omitted: start of the current year to today (matches StatisticsController defaults — no clinical sign-off required).
- Interval is hard-coded to `month` for the PDF (a day-interval trend over a long range is unreadable on A4).
- Readmission window sourced from `Setting::current()` — consistent with all other controllers.
- The "unit median LOS vs consultant LOS" comparison does not require clinical sign-off: it is already rendered on the Statistics screen (`physician.numbers.avgLos` vs the unit-wide `kpis.avgLos`); the PDF just makes it printable.

**Test plan** (`ConsultantScorecardTest.php`):
- `test_consultant_scorecard_pdf_requires_admin`: non-admin GET returns 403.
- `test_consultant_scorecard_pdf_returns_pdf_content_type`: admin GET for a seeded consultant and date range returns 200 with `Content-Type: application/pdf`.
- `test_consultant_scorecard_pdf_404_for_nonexistent_user`: GET `/reports/consultant/99999/pdf` returns 404.
- `test_consultant_scorecard_pdf_invalid_dates_rejected`: `from=notadate` returns 422 (FormRequest validation).
- `test_consultant_scorecard_reuses_physician_method_numbers`: seed the `StatisticsValueTest` fixture, pick a consultant, assert the PDF download succeeds and the `physician` array computed by the shared method is consistent (admissions count matches a direct DB query on the same range — value test pattern).

**Build sequence**
1. Promote `StatisticsController::physician`, `buckets`, `keyExpr`, `seriesBy` to a new `app/Http/Controllers/Concerns/MetricQueries.php` trait (or make `physician` protected and duplicate the helpers temporarily). Write a failing test: `consultant_scorecard_pdf_requires_admin`. Implement the empty route + controller stub returning 403 for non-admin. Green.
2. Implement `ConsultantScorecardRequest`. Write failing test: invalid dates return 422. Green.
3. Implement `consultantPdf` data gathering (call `physician()`). Write failing test: 200 with `application/pdf` content-type for a valid consultant. Create minimal blade. Green.
4. Build the full 2-page blade with `ReportSvg` calls. Write the value-test asserting the numbers match a direct DB count. Green. Commit.
5. Add the Vue download button in `Statistics/Index.vue`. Run Vite build. Commit.

---

### 3.2 Governance / M&M pack PDF (effort M, risk Medium)

**Goal / user value**
Admin generates a de-identified Morbidity & Mortality pack for a chosen month or quarter — headline safety KPIs, trend bars, and line lists of every death and every 72h readmission in the period — as a single downloadable PDF.

**Files**

- Create `laravel/app/Http/Requests/GovernanceReportRequest.php`
- Modify `laravel/app/Http/Controllers/ReportsController.php` — add `governancePdf` method
- Create `laravel/resources/views/reports/governance-pdf.blade.php`
- Create `laravel/resources/js/Pages/Reports/Governance.vue` (admin screen form — month/quarter picker + download button)
- Modify `laravel/routes/web.php` — add `GET /reports/governance` (screen) and `GET /reports/governance/pdf` (download)
- Create `laravel/tests/Feature/GovernancePdfTest.php`

**Design**

`GovernanceReportRequest` rules:
```php
'period_type' => ['required', 'in:month,quarter'],
'year'        => ['required', 'integer', 'min:2000', 'max:' . now()->year],
'month'       => ['required_if:period_type,month', 'nullable', 'integer', 'min:1', 'max:12'],
'quarter'     => ['required_if:period_type,quarter', 'nullable', 'integer', 'min:1', 'max:4'],
```
`authorize()` returns `$this->user()->isAdmin()`.

Date range derivation from validated data:
```php
if ($data['period_type'] === 'quarter') {
    $startMonth = ($data['quarter'] - 1) * 3 + 1;
    $f = Carbon::createFromDate($data['year'], $startMonth, 1)->startOfMonth()->toDateString();
    $t = Carbon::createFromDate($data['year'], $startMonth, 1)->addMonths(2)->endOfMonth()->toDateString();
} else {
    $f = Carbon::createFromDate($data['year'], $data['month'], 1)->startOfMonth()->toDateString();
    $t = Carbon::createFromDate($data['year'], $data['month'], 1)->endOfMonth()->toDateString();
}
```

`ReportsController::governancePdf(GovernanceReportRequest $request)`:

Headline KPI block — reuse the same queries already in `StatisticsController::index` for the period:
- `mortalityRate`: deaths/discharges (same formula as `$totals['mortalityRate']` in `gather()`).
- `readmissions`: via `Admission::readmissionJoin(Setting::current()->readmission_window_days ?? 3)`.
- `longStayPct`: from `censusFamilies()` (call the same private method, or expose as protected — same extraction done for §3.1).
- `weekendPct`: from `byMonth('discharge_date', ...)` with `DAYOFWEEK IN (6,7)` (copy of the `$weekendByMonth` call in `gather()`).

Trend chart — 3-month rolling window centred on the period using the same `byMonth` calls. For month periods, show the prior month, current month, and next month (if elapsed). For quarter periods, show 3 monthly bars. Build as `ReportSvg::groupedBar` with 4 series (admissions/discharges/deaths/readmits) using `SERIES_COLORS`.

Death line list — DE-IDENTIFIED:
```php
$deaths = DB::table('admissions as a')
    ->join('patients as p', 'p.id', '=', 'a.patient_id')
    ->leftJoin('users as u', 'u.id', '=', 'a.consultant_id')
    ->whereBetween('a.discharge_date', [$f, $t])
    ->where('a.outcome', 'Dead')
    ->select('p.mrn', 'p.age', DB::raw('DATEDIFF(a.discharge_date, a.admit_date) los'),
             'a.current_location', DB::raw("COALESCE(u.full_name, u.name) consultant"))
    ->orderBy('a.discharge_date')
    ->get();
```
Diagnosis names for each death: load `admission_diagnoses` + `icd10` for this small set (at most a few dozen rows per month) using `whereIn`. The blade renders `MRN | Age | LOS | Location | Primary Dx | Consultant`.

Readmission line list — DE-IDENTIFIED, reuses `Admission::readmissionJoin`:
```php
$readmits = DB::table('admissions as a')
    ->join('admissions as prev', Admission::readmissionJoin($window))
    ->join('patients as p', 'p.id', '=', 'a.patient_id')
    ->leftJoin('users as u', 'u.id', '=', 'a.consultant_id')
    ->whereBetween('a.admit_date', [$f, $t])
    ->distinct()
    ->select('p.mrn', 'p.age', 'a.admit_date', DB::raw('DATEDIFF(a.admit_date, prev.discharge_date) gap_days'),
             DB::raw("COALESCE(u.full_name, u.name) consultant"))
    ->orderBy('a.admit_date')
    ->get();
```

`governance-pdf.blade.php` (3 pages, A4 landscape, reuses the same `.counter`/`.panel`/`.data`/`.foot` CSS as `annual-pdf.blade.php`):
- **Page 1**: banner, period title, 4 KPI counter cards (mortalityRate, readmissions, longStayPct, weekendPct), trend grouped-bar chart.
- **Page 2**: Death line list (table with `MRN | Age | LOS | Location | Primary Dx | Consultant`). Empty state: "No deaths recorded in this period."
- **Page 3** (`.page-last`): Readmission line list (table with `MRN | Age | Admit Date | Gap (days) | Consultant`). Empty state.

`Reports/Governance.vue` — simple screen form: two radio buttons (Month / Quarter), year dropdown (from `availableYears` passed as an Inertia prop), conditional month/quarter selectors, and a download anchor pointing to `/reports/governance/pdf?...`. No live charts — the PDF is the deliverable.

Route additions in the `admin` group:
```php
Route::get('/reports/governance', [ReportsController::class, 'governance'])->name('reports.governance');
Route::get('/reports/governance/pdf', [ReportsController::class, 'governancePdf'])->name('reports.governance.pdf');
```

**Decisions & defaults**
- De-identification: MRN (clinical identifier) is included, as it is in all registry exports (`RegistryController::writeExport` comment: "Exports are DE-IDENTIFIED (no patient name — legacy parity; MRN is the clinical identifier)"). Patient name is NOT included.
- Period default: current month.
- Flag for clinician sign-off: the definition of "consultant credited for a death" (is it the episode's `consultant_id`?) is consistent with `StatisticsController`'s existing `physician()` method (`deaths` = `scoped()->where('outcome','Dead')`). No change required.

**Test plan** (`GovernancePdfTest.php`):
- `test_governance_pdf_requires_admin`: 403 for non-admin.
- `test_governance_pdf_invalid_period_type_rejected`: `period_type=year` returns 422.
- `test_governance_pdf_missing_month_for_month_type_rejected`: period_type=month without `month` returns 422.
- `test_governance_pdf_returns_pdf`: valid month request returns 200 + `application/pdf`.
- `test_governance_pdf_death_list_includes_all_deaths`: seed 2 deaths and 1 non-death discharge in the period; assert the rendered PDF body contains both MRNs (use `Pdf::shouldReceive` mock or assert the blade receives `deaths` collection of size 2).
- `test_governance_pdf_readmit_list_reuses_readmission_join`: seed the `StatisticsValueTest` readmission fixture (A1 → A4 pattern); assert `readmits` collection has 1 row and the transfer-continuation is excluded (value test).

**Build sequence**
1. Write failing test `governance_pdf_requires_admin`. Create `GovernanceReportRequest` + empty controller methods + routes. Green.
2. Implement validation rules; write failing test for invalid period_type. Green.
3. Implement `governancePdf` data gathering (headline KPIs + trend). Minimal blade (page 1 only). Write failing test `returns_pdf`. Green.
4. Implement death + readmit line lists. Add pages 2–3 to blade. Write value tests. Green.
5. Implement `governance` screen method returning `Reports/Governance` Inertia page. Create `Governance.vue`. Build Vite. Commit.

---

### 3.3 Scheduled monthly report email (effort M, risk Medium)

**Goal / user value**
On the 1st of each month, recipients automatically receive the prior month's monthly-booklet PDF as an email attachment — the same PDF produced by `ReportsController::monthlyPdf` — with no manual action required.

**Files**

- Create migration `laravel/database/migrations/2026_06_14_000001_create_report_recipients_table.php`
- Create `laravel/app/Models/ReportRecipient.php`
- Create `laravel/app/Jobs/GenerateMonthlyReport.php`
- Create `laravel/app/Mail/MonthlyReportMail.php`
- Create `laravel/resources/views/mail/monthly-report.blade.php` (plain email body)
- Modify `laravel/app/Http/Controllers/ControlController.php` — add `reportRecipients`, `addReportRecipient`, `removeReportRecipient` actions
- Modify `laravel/resources/js/Pages/Control/Index.vue` — add a "Monthly report recipients" section
- Modify `laravel/routes/web.php` — 3 new routes in the `admin` group
- Modify `laravel/routes/console.php` — schedule the job
- Create `laravel/tests/Feature/MonthlyReportMailTest.php`

**Design**

Migration `report_recipients`:
```
id (bigIncrements)
email (varchar 255, unique)
active (boolean, default true)
added_by_id (foreignId -> users, nullOnDelete)
created_at (timestamp useCurrent)
```
No `updated_at` — append-only style matches `audit_log`. Index on `active`.

`ReportRecipient` model: `$guarded = ['id']`; `casts: ['active' => 'boolean']`.

`GenerateMonthlyReport` (implements `ShouldQueue`):
```php
public function __construct(public int $year, public int $month) {}

public function handle(ReportsController $reports): void
{
    // call gatherBooklet on the PRIOR month's year
    // gatherBooklet is protected after §3.1 trait extraction
    $data = $reports->gatherBooklet($this->year);
    // filter to only the target month (gatherBooklet already skips future months)
    $pdf = Pdf::loadView('reports.monthly-pdf', $data)->setPaper('a4', 'landscape');
    $pdfContent = $pdf->output();

    $recipients = ReportRecipient::where('active', true)->get();
    foreach ($recipients as $r) {
        Mail::to($r->email)->queue(new MonthlyReportMail($this->year, $this->month, $pdfContent));
    }
}
```
The job calls `gatherBooklet` (promoted to `public` or kept `protected` and accessed via a controller injection) which is the exact same code path as the `monthlyPdf` HTTP endpoint — the emailed PDF and the downloadable PDF are byte-identical because both hit the same method and the same blade.

`MonthlyReportMail`:
```php
public function __construct(
    private int $year, private int $month, private string $pdfContent
) {}

public function build(): self
{
    $name = Carbon::createFromDate($this->year, $this->month, 1)->format('F Y');
    return $this->subject("DMC Internal Medicine — Monthly Report {$name}")
        ->view('mail.monthly-report')
        ->with(['year' => $this->year, 'month' => $this->month, 'name' => $name])
        ->attachData($this->pdfContent, "dmc-monthly-{$this->year}-{$this->month:02d}.pdf",
            ['mime' => 'application/pdf']);
}
```

`mail/monthly-report.blade.php`: plain-text email body — "Please find attached the Internal Medicine monthly activity report for {$name}. This is an automated message."

`routes/console.php` schedule:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    $prior = \Illuminate\Support\Carbon::today()->subMonth();
    \App\Jobs\GenerateMonthlyReport::dispatch($prior->year, $prior->month);
})->monthly()->name('monthly-report-email')->withoutOverlapping();
```
The `Schedule` facade is available in Laravel 13 `console.php` without a full `Kernel.php`.

Control panel additions in `ControlController`:
- `reportRecipients()`: returns `ReportRecipient::orderBy('created_at', 'desc')->get(['id','email','active'])` — passed as an Inertia prop alongside the existing `control` page data.
- `addReportRecipient(Request $request)`: validate `email` (required, email, max:255, unique:report_recipients); create row; write `audit_log` (`action='report_recipient.add'`, `entity_type='report_recipient'`); return redirect back.
- `removeReportRecipient(ReportRecipient $recipient)`: `$recipient->delete()`; audit log; redirect back.

Routes (inside `admin` group):
```php
Route::post('/control/report-recipients', [ControlController::class, 'addReportRecipient'])->name('control.recipients.add');
Route::delete('/control/report-recipients/{recipient}', [ControlController::class, 'removeReportRecipient'])->name('control.recipients.destroy');
```
The `reportRecipients()` data is merged into the existing `control.index` Inertia response (no new screen route needed).

`Control/Index.vue` addition: a small section below the existing settings form — email input + "Add" button using `useForm`, and a list of current recipients with remove buttons. Mirrors the existing specialty/reason add-panels in the same page.

Environment documentation (add to `.env.example` and deployment docs):
```
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=reports@dmc-im.com
MAIL_FROM_NAME="DMC Internal Medicine"

QUEUE_CONNECTION=database   # jobs table already exists via the core migration
```
The `jobs` table already exists (migration `0001_01_01_000002_create_jobs_table.php` confirmed).

**Decisions & defaults**
- Recipients in a dedicated `report_recipients` table rather than a comma-separated settings column: supports per-recipient enable/disable without corrupting the settings row. No schema change to `settings`.
- The job dispatches one `MonthlyReportMail` per recipient rather than CC-ing all — this is the safer pattern (recipient privacy, individual delivery failure isolation).
- `QUEUE_CONNECTION=database` is the safe default for a shared-hosting environment with no Redis. The `jobs` table already exists.
- Mailable stores the PDF as an in-memory string (`$pdf->output()`) rather than writing to `storage/` for simplicity; item 3.6 covers the case where generation time becomes problematic.
- Flag: if the monthly booklet for a month with zero elapsed days is requested, `gatherBooklet` returns an empty `$months` array and the blade renders the "No elapsed months" cover page — this is already handled.

**Test plan** (`MonthlyReportMailTest.php`):
- `test_job_dispatches_to_active_recipients_only`: seed 2 active + 1 inactive recipient; dispatch `GenerateMonthlyReport`; assert `Mail::fake()` queued exactly 2 `MonthlyReportMail` messages.
- `test_job_dispatches_no_mail_when_no_recipients`: dispatch job with empty table; assert 0 queued mails.
- `test_add_recipient_requires_admin`: POST from non-admin returns 403.
- `test_add_recipient_validates_email`: POST `email=notanemail` returns 422.
- `test_add_recipient_unique_constraint`: POST duplicate email returns 422.
- `test_remove_recipient_requires_admin`: DELETE from non-admin returns 403.
- `test_control_index_includes_recipients`: admin GET `/control` includes `reportRecipients` key in Inertia props.
- `test_emailed_pdf_uses_same_blade_as_download`: assert `MonthlyReportMail` attachment filename matches the expected pattern and MIME is `application/pdf`.

**Build sequence**
1. Create migration + model. Write failing test `add_recipient_requires_admin`. Add empty routes + controller stubs. Green.
2. Implement `addReportRecipient` + `removeReportRecipient` with validation and audit. Write failing tests for validation and uniqueness. Green.
3. Merge `reportRecipients` into `control.index` response. Update `Control/Index.vue`. Build Vite. Commit.
4. Create `GenerateMonthlyReport` job + `MonthlyReportMail`. Write `test_job_dispatches_to_active_recipients_only` using `Mail::fake()`. Implement. Green.
5. Add `Schedule::call(...)` in `console.php`. Write `test_job_dispatches_no_mail_when_no_recipients`. Green. Commit.

---

### 3.4 Statistics Excel/PDF export (effort M, risk Low)

**Goal / user value**
Admin can download the currently-filtered statistics as either a multi-sheet XLSX or a printable PDF directly from the Statistics toolbar, without re-entering filters.

**Files**

- Create `laravel/app/Http/Requests/StatisticsExportRequest.php`
- Modify `laravel/app/Http/Controllers/StatisticsController.php` — add `exportXlsx` and `exportPdf` methods; extract shared query helpers to the `MetricQueries` trait
- Create `laravel/resources/views/reports/statistics-pdf.blade.php`
- Modify `laravel/resources/js/Pages/Statistics/Index.vue` — add export buttons to the toolbar
- Modify `laravel/routes/web.php` — 2 new routes in the `admin` group
- Create `laravel/tests/Feature/StatisticsExportTest.php`

**Design**

`StatisticsExportRequest` rules (mirrors `StatisticsController::index` inline validate, now a FormRequest):
```php
'from'          => ['nullable', 'date'],
'to'            => ['nullable', 'date'],
'interval'      => ['nullable', 'in:day,month,quarter'],
'consultant_id' => ['nullable', 'integer', 'exists:users,id'],
```
`authorize()` returns `$this->user()->isAdmin()`.

`StatisticsController::exportXlsx(StatisticsExportRequest $request)`:
Uses the `XlsxWriter` pattern from `RegistryController::exportXlsx`. Three sheets:
1. **KPI Grid** — columns: `Period | Adm | Disch | ICU adm | →ICU | ICU deaths | Ward deaths | Readmits | Consults | Sign-offs | Avg LOS`. Each row maps directly from `$kpiGrid` as built in `index()`. The `$kpiGrid` construction is extracted into a private `buildKpiGrid(string $f, string $t, string $interval): array` method that both `index()` and `exportXlsx()` call.
2. **Per Consultant** — columns: `Consultant | Admissions | Discharges | Avg LOS | Readmits | Consultations | Sign-offs`. Built from the `$perConsultant` collection (same query already in `index()`).
3. **Interval Series** — columns: `Period | Admissions | Discharges | Deaths | Consultations | Sign-offs`. One row per bucket from `$monthly`.

Writer usage (exact pattern from `RegistryController::exportXlsx`, lines 315–324):
```php
$tmp = tempnam(sys_get_temp_dir(), 'stat') . '.xlsx';
$writer = new XlsxWriter();
$writer->openToFile($tmp);
// Sheet 1 — openspout does not have explicit sheet naming in the
// default writer; rows are sequential. Use comment headers for sheet demarcation.
$writer->addRow(Row::fromValues(['KPI Grid — ' . $f . ' to ' . $t]));
$writer->addRow(Row::fromValues(['Period','Adm','Disch',...]);
// ... rows
$writer->close();
return response()->download($tmp, 'dmc-statistics-' . $f . '-' . $t . '.xlsx')->deleteFileAfterSend();
```

`StatisticsController::exportPdf(StatisticsExportRequest $request)`:
Builds the same data arrays as `index()` (all queries must be refactored out of `index()` into private methods that both `index()` and `exportPdf()` call — this is the same refactor as `buildKpiGrid`; extend it to `buildHeadlineKpis`, `buildPerConsultant`, `buildMonthlyChart`). Renders `reports.statistics-pdf.blade.php`.

`statistics-pdf.blade.php` (2 pages A4 landscape, same CSS pattern as `annual-pdf.blade.php`):
- **Page 1**: header, KPI counter cards (8 tiles matching the screen's `kpiCards`), KPI grid table (same columns as the XLSX sheet).
- **Page 2** (`.page-last`): per-consultant table + `ReportSvg::hBar` for the per-consultant LOS column.

Routes in the `admin` group:
```php
Route::get('/statistics/export', [StatisticsController::class, 'exportXlsx'])->name('statistics.export.xlsx');
Route::get('/statistics/export/pdf', [StatisticsController::class, 'exportPdf'])->name('statistics.export.pdf');
```

`Statistics/Index.vue` toolbar addition: two buttons after the Apply button, both as `<a>` tags that append the current filter values as query-string parameters:
```js
const exportHref = computed(() => '/statistics/export?' + new URLSearchParams(
    Object.entries({ from: from.value, to: to.value, interval: interval.value,
                     ...(physChoice.value ? { consultant_id: physChoice.value } : {}) })
        .filter(([,v]) => v !== '' && v != null)).toString());
```
Two links: `exportHref` for XLSX, `exportHref.replace('/export', '/export/pdf')` for PDF.

**Decisions & defaults**
- Sheet-level naming: the openspout `XlsxWriter` used by `RegistryController` writes a single sheet; multi-sheet output requires `openspout/openspout` to also call `addNewSheetAndMakeItCurrent()`. Check at implementation time — if the installed openspout version does not support multi-sheet, emit all three tables sequentially in one sheet with blank-row separators (same as `writeExport` fallback; no new dependency).
- The `buildKpiGrid` extraction is the only mandatory refactor — it enables the "can't drift" contract in item 3.8.
- PDF export is synchronous (same as the existing `reports/pdf` route) because the KPI queries are already tested to be fast over bounded ranges.

**Test plan** (`StatisticsExportTest.php`):
- `test_statistics_xlsx_requires_admin`: 403 for non-admin.
- `test_statistics_xlsx_returns_xlsx_content_type`: admin GET returns 200 with `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
- `test_statistics_pdf_requires_admin`: 403.
- `test_statistics_pdf_returns_pdf`: 200 + `application/pdf`.
- `test_statistics_export_invalid_interval_rejected`: `interval=week` returns 422.
- `test_statistics_xlsx_kpi_grid_matches_index_response` (value test — the "can't drift" contract): seed the `StatisticsValueTest` fixture; GET `/statistics?from=2024-06-01&to=2024-06-30` and capture the `kpiGrid` Inertia prop; GET `/statistics/export?from=2024-06-01&to=2024-06-30`; parse the XLSX headers and first data row (use openspout Reader); assert the `Adm` column value matches `kpiGrid[0]['admissions']` from the Inertia response. This is the precise "export and index share the same query method" contract.

**Build sequence**
1. Extract `buildKpiGrid` from `StatisticsController::index`. Write failing test `xlsx_kpi_grid_matches_index_response`. Green.
2. Implement `exportXlsx` using the `XlsxWriter` pattern. Write tests for content-type and invalid interval. Green.
3. Implement `exportPdf` + `statistics-pdf.blade.php`. Write PDF test. Green.
4. Add Vue export buttons. Build Vite. Commit.

---

### 3.5 Year-over-year comparison overlay (effort M, risk Medium)

**Goal / user value**
Statistics page gains an optional "compare to" toggle: overlay a dashed prior-period series on the main chart and a delta row on headline KPIs, letting the team compare current performance against the same dates last year or the previous equal period.

**Files**

- Modify `laravel/app/Http/Controllers/StatisticsController.php` — add `compareMode` parameter handling and `$compare` data block
- Modify `laravel/resources/js/Pages/Statistics/Index.vue` — compare toggle control + dashed ghost series rendering + delta row
- Create `laravel/tests/Feature/YoyComparisonTest.php`

**Design**

New query parameter: `compare` with values `prior_year` (same calendar dates, year−1) or `prior_period` (equal-length period immediately before `from`). Validated via the inline `$request->validate` in `index()` (add `'compare' => ['nullable', 'in:prior_year,prior_period']`).

Comparison range derivation in `StatisticsController::index`:
```php
$compare = $data['compare'] ?? null;
if ($compare) {
    $span = $from->diffInDays($to);
    if ($compare === 'prior_year') {
        $cf = $from->copy()->subYear()->toDateString();
        $ct = $to->copy()->subYear()->toDateString();
    } else {
        $cf = $from->copy()->subDays($span + 1)->toDateString();
        $ct = $from->copy()->subDay()->toDateString();
    }
}
```

Comparison data block — reuse the **exact same** series-building helpers already used for the primary period:
```php
$compareData = null;
if ($compare) {
    $cBuckets = $this->buckets(Carbon::parse($cf), Carbon::parse($ct), $interval);
    $cKeys = array_column($cBuckets, 'key');
    $compareData = [
        'labels' => array_column($cBuckets, 'label'),
        'admissions' => array_map(fn ($k) => (int) ($this->seriesBy('admissions','admit_date',$cf,$ct,$interval,$this->nonIcu)[$k] ?? 0), $cKeys),
        'discharges' => array_map(fn ($k) => (int) ($this->seriesBy('admissions','discharge_date',$cf,$ct,$interval,$this->nonIcu)[$k] ?? 0), $cKeys),
        'deaths'     => array_map(fn ($k) => (int) ($this->seriesBy('admissions','discharge_date',$cf,$ct,$interval,"outcome = 'Dead'")[$k] ?? 0), $cKeys),
        'kpis' => [
            'admissions'   => (int) DB::table('admissions')->whereBetween('admit_date', [$cf, $ct])->whereRaw($this->nonIcu)->count(),
            'discharges'   => (int) DB::table('admissions')->whereBetween('discharge_date', [$cf, $ct])->whereRaw($this->nonIcu)->count(),
            'deaths'       => (int) DB::table('admissions')->where('outcome','Dead')->whereBetween('discharge_date',[$cf,$ct])->count(),
            'mortalityRate'=> 0, // computed after
            'avgLos'       => round((float)(DB::table('admissions')->whereBetween('discharge_date',[$cf,$ct])->whereNotNull('admit_date')->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(discharge_date,admit_date)>=0')->avg(DB::raw('DATEDIFF(discharge_date,admit_date)')) ?? 0), 1),
        ],
        'range' => ['from' => $cf, 'to' => $ct],
    ];
    $compareData['kpis']['mortalityRate'] = $compareData['kpis']['discharges'] > 0
        ? round($compareData['kpis']['deaths'] / $compareData['kpis']['discharges'] * 100, 1) : 0.0;
}
```
The `compareData` key is added to the Inertia props alongside the existing keys. `null` when no comparison is active.

Vue changes in `Statistics/Index.vue`:

Compare control — add a `<select>` to the toolbar between the interval buttons and the Apply button:
```html
<select v-model="compareMode" class="rounded-xl border border-ink-200 px-3 py-2 text-sm ...">
    <option value="">No comparison</option>
    <option value="prior_year">vs prior year</option>
    <option value="prior_period">vs prior period</option>
</select>
```
The `apply()` function already passes all reactive refs; add `...(compareMode.value ? { compare: compareMode.value } : {})`.

Ghost series in `monthlySeries`: when `props.compareData` is not null, append 3 additional series (Admissions / Discharges / Deaths for the comparison period) with the same colors but at 40% opacity. ApexCharts supports per-series opacity via `fill.opacity` array or by embedding alpha in the hex color:
```js
const monthlySeries = computed(() => {
    const base = [
        { name: 'Admissions', data: props.monthly.admissions },
        { name: 'Discharges', data: props.monthly.discharges },
        { name: 'Mortality',  data: props.monthly.deaths },
    ];
    if (!props.compareData) return base;
    return [...base,
        { name: `Admissions (${props.compareData.range.from.slice(0,4)})`, data: props.compareData.admissions },
        { name: `Discharges (${props.compareData.range.from.slice(0,4)})`, data: props.compareData.discharges },
        { name: `Deaths (${props.compareData.range.from.slice(0,4)})`,     data: props.compareData.deaths },
    ];
});
```
Ghost series styling: add to `monthlyChart` computed:
```js
stroke: { width: [3,3,2, 2,2,2], curve: 'smooth', dashArray: [0,0,0, 5,5,5] },
```
ApexCharts `stroke.dashArray` renders the comparison series as dashed lines.

Delta row on KPI cards: below the existing `kpiCards` grid, render a second row of delta chips when `compareData` is not null:
```html
<div v-if="compareData" class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8">
    <div v-for="k in deltaCards" :key="k.label" class="rounded-xl bg-app p-2 text-center ring-1 ring-line text-sm">
        <div class="text-[10px] text-ink-400">vs {{ compareData.range.from }}–{{ compareData.range.to }}</div>
        <div class="nums font-bold" :class="k.delta > 0 ? 'text-danger-600' : k.delta < 0 ? 'text-brand-600' : 'text-ink-400'">
            {{ k.delta > 0 ? '+' : '' }}{{ k.delta }}
        </div>
    </div>
</div>
```
`deltaCards` is a `computed` mapping: `admissions`, `discharges`, `deaths`, `avgLos`, `mortalityRate` deltas (current minus comparison, rounded to 1dp for floats).

**Decisions & defaults**
- Default: no comparison (compare='' → null). User explicitly enables via the select.
- The `prior_period` shift uses `diffInDays` so a 30-day range always compares to the prior 30-day window regardless of month boundaries — clinically the most intuitive behaviour.
- Color: the ghost series reuses the primary palette with `dashArray: [0,0,0,5,5,5]` — no new tokens needed.
- Flag for clinician review: the interpretation of "prior year" for a partial-year range (e.g. Jan–Jun compared to Jan–Jun last year) is unambiguous and requires no clinical sign-off.

**Test plan** (`YoyComparisonTest.php`):
- `test_compare_prior_year_returns_compare_data_prop`: seed admissions in 2023 and 2024 (same months); GET `/statistics?from=2024-01-01&to=2024-03-31&compare=prior_year`; assert Inertia prop `compareData.range.from` is `2023-01-01` and `compareData.range.to` is `2023-03-31`.
- `test_compare_prior_period_range_is_equal_length`: GET with `from=2024-04-01&to=2024-06-30&compare=prior_period`; assert `compareData.range.from === '2024-01-01'` and `compareData.range.to === '2024-03-31'`.
- `test_compare_kpis_values_match_independent_query` (value test): seed 3 admissions in 2024-06, 2 admissions in 2023-06; GET `/statistics?from=2024-06-01&to=2024-06-30&compare=prior_year`; assert `compareData.kpis.admissions === 2` (independent SQL count for 2023-06 confirms). This is the drift guard.
- `test_compare_invalid_mode_rejected`: `compare=next_year` returns 422.
- `test_no_compare_when_param_absent`: GET without `compare` param; assert Inertia prop `compareData` is null.

**Build sequence**
1. Add `compare` to `$request->validate()`. Write failing test `compare_invalid_mode_rejected`. Green.
2. Implement range derivation + `compareData` assembly. Write failing `compare_prior_year_returns_compare_data_prop`. Green.
3. Write `compare_kpis_values_match_independent_query` value test. Run — it exercises the already-implemented code. Green.
4. Update `Statistics/Index.vue`: add compare select, ghost series, delta row. Build Vite. Commit.

---

### 3.6 Reliability — queued heavy PDF + export row-count guard (effort M, risk High)

**Goal / user value**
Prevents web-server timeouts and memory exhaustion for the monthly-booklet PDF (the heaviest generation path) and for large registry exports; surfaces row counts to the user before a potentially slow export begins.

**Files**

- Modify `laravel/app/Http/Controllers/ReportsController.php` — `monthlyPdf` becomes async; add `pdfStatus` endpoint
- Create `laravel/app/Jobs/GenerateAnnualPdf.php` (reusable for the annual booklet too)
- Create `laravel/app/Jobs/GenerateMonthlyPdf.php`
- Publish dompdf config: `laravel/config/dompdf.php`
- Modify `laravel/app/Http/Controllers/RegistryController.php` — add `matchCount` endpoint
- Modify `laravel/resources/js/Pages/Registry/Index.vue` — surface match count before export
- Modify `laravel/resources/js/Pages/Reports/Index.vue` + `Monthly.vue` — loading/notify-ready UI
- Modify `laravel/routes/web.php` — new routes
- Create `laravel/tests/Feature/ReliabilityTest.php`

**Design**

Dompdf config — publish with `php artisan vendor:publish --tag=dompdf` to create `laravel/config/dompdf.php`. Set:
```php
'defines' => [
    'DOMPDF_DPI'           => 96,
    'DOMPDF_ENABLE_REMOTE' => false,  // security: no external resource loading
    'DOMPDF_CHROOT'        => base_path(),
],
'options' => [
    'isRemoteEnabled' => false,
    'defaultFont'     => 'DejaVu Sans',
    // raise from the package default of 128MB to 256MB for the monthly booklet
    'memory_limit'    => '256M',
],
```
This is a config change, not a new dependency. The `laravel/config/dompdf.php` file is committed.

Immediate fix for synchronous PDF timeout — wrap the existing `pdf()` and `monthlyPdf()` in `ini_set('max_execution_time', 120)` as a stopgap (safe for a controlled admin-only endpoint). This unblocks production while the queued path is built.

Queued monthly PDF generation:

`GenerateMonthlyPdf` job (implements `ShouldQueue`):
```php
public function __construct(public int $year, public int $key)
{} // key = user_id or a UUID from the request, used to identify the stored file
```
The `handle` method calls `ReportsController::gatherBooklet($this->year)` (now `public`), renders the PDF with dompdf, stores it to `storage/app/reports/monthly-{$year}-{$this->key}.pdf` via `Storage::disk('local')->put(...)`, then creates a `Notification` record for the requesting admin (the `notifications` table and `Notification` model already exist at `laravel/app/Models/Notification.php` confirmed by the Glob scan).

Modified `monthlyPdf` flow:
```php
public function monthlyPdf(Request $request): \Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\Response
{
    $year = (int) ($request->query('year') ?: Carbon::today()->year);
    $async = (bool) $request->query('async', false);

    if ($async) {
        GenerateMonthlyPdf::dispatch($year, auth()->id());
        return back()->with('flash', ['type' => 'info', 'message' => 'Your PDF is being generated — you will be notified when it is ready.']);
    }
    // synchronous path (default — existing behaviour preserved)
    $pdf = Pdf::loadView('reports.monthly-pdf', $this->gatherBooklet($year))->setPaper('a4', 'landscape');
    return $pdf->download("dmc-monthly-report-{$year}.pdf");
}
```

Add a `pdfDownload` endpoint:
```php
Route::get('/reports/pdf-download/{key}', [ReportsController::class, 'downloadGenerated'])->name('reports.pdf.download');
```
`downloadGenerated` streams the stored file and deletes it after send. Admin-only (in the `admin` group).

`Reports/Index.vue` and `Monthly.vue` change: add an `async` query param toggle button next to the existing download buttons, visible when the file would be large (gate: year is current year and more than 6 months have elapsed — simple client-side heuristic). No complex state machine needed.

Registry row-count guard:

`RegistryController::matchCount(Request $request)`:
```php
public function matchCount(Request $request): \Illuminate\Http\JsonResponse
{
    $count = match ($this->mode($request)) {
        'consultations' => $this->consultationQuery($request)->count(),
        'diagnosis'     => $this->diagnosisQuery($request)->count(),
        default         => $this->admissionQuery($request)->count(),
    };
    return response()->json(['count' => $count]);
}
```
Route: `GET /registry/count` (in the `admin` group, before the existing export routes).

`Registry/Index.vue` change: the existing export `qs` computed already builds the query string. Add a `matchCount` ref that is populated on-demand when the user hovers over or focuses the export button:
```js
const matchCount = ref(null);
const loadMatchCount = async () => {
    if (matchCount.value !== null) return;
    const r = await fetch('/registry/count?' + qs.value);
    const d = await r.json();
    matchCount.value = d.count;
};
```
Display: `<span v-if="matchCount !== null" class="text-xs text-ink-400">({{ matchCount.toLocaleString() }} rows)</span>` adjacent to the export buttons. When `matchCount > 20000`, show a `warning-100` banner: "This export contains {{ matchCount.toLocaleString() }} rows and may take several minutes."

The 20,000 row guard threshold is a sensible default (the legacy xlsx-writer handled ~15,000 total admissions; anything above 20k indicates a very broad unfiltered query). No admin setting needed; it is a purely UX advisory.

**Decisions & defaults**
- Queued path is opt-in (`?async=1`) rather than the forced default, preserving existing synchronous behaviour for small ranges. Most common use (prior month) is fast enough synchronously.
- Storage disk: `local` (not `public`). The stored file is accessible only via the authenticated `downloadGenerated` endpoint — no PHI exposure through the public disk.
- The queued export path for registry is deferred (the `matchCount` warning is advisory; a forced async export adds significant complexity for a dataset that openspout handles via `chunk(500)` streaming already).

**Test plan** (`ReliabilityTest.php`):
- `test_registry_match_count_requires_admin`: 403 for non-admin.
- `test_registry_match_count_returns_json_count`: seed 5 admissions; GET `/registry/count?mode=admissions`; assert `{"count":5}`.
- `test_monthly_pdf_async_dispatches_job`: `Mail::fake(); Queue::fake(); GET /reports/monthly/pdf?year=2024&async=1`; assert `GenerateMonthlyPdf` was dispatched once with `year=2024`.
- `test_monthly_pdf_sync_still_returns_pdf`: GET without `async`; assert `application/pdf` response (existing behaviour preserved).
- `test_dompdf_config_published`: assert `config_path('dompdf.php')` file exists (guards against accidental deletion).

**Build sequence**
1. `php artisan vendor:publish --tag=dompdf`. Write failing test `dompdf_config_published`. Confirm file exists. Green. Commit.
2. Add `ini_set` stopgap to `pdf()` + `monthlyPdf()`. Add `matchCount` route + controller method. Write failing test `registry_match_count_requires_admin`. Green.
3. Implement `GenerateMonthlyPdf` job. Write `monthly_pdf_async_dispatches_job` using `Queue::fake()`. Green.
4. Add `?async` branch to `monthlyPdf`. Write `monthly_pdf_sync_still_returns_pdf`. Green.
5. Update `Registry/Index.vue` with match-count fetch + warning banner. Update `Reports/Monthly.vue` with async button. Build Vite. Commit.

---

### 3.7 Param validation + empty states (effort S, risk Low)

**Goal / user value**
Replaces silent failures (empty charts with no explanation, 500s on out-of-range year inputs) with explicit validation responses and "No data for {year}" / "No admissions match" empty states in the Reports and Registry pages.

**Files**

- Create `laravel/app/Http/Requests/ReportYearRequest.php`
- Modify `laravel/app/Http/Controllers/ReportsController.php` — `index()` and `pdf()` use the new FormRequest; clamp `year` to `availableYears`
- Modify `laravel/resources/js/Pages/Reports/Index.vue` — empty state for zero-data year
- Modify `laravel/resources/js/Pages/Registry/Index.vue` — empty state when results are empty
- Modify `laravel/routes/web.php` — no new routes; just request class swap
- Create `laravel/tests/Feature/ParamValidationTest.php`

**Design**

`ReportYearRequest` rules:
```php
public function rules(): array
{
    return [
        'year'  => ['nullable', 'integer', 'min:2000', 'max:' . now()->year],
        'month' => ['nullable', 'integer', 'min:1', 'max:12'],
    ];
}
public function authorize(): bool { return $this->user()->isAdmin(); }
```
The `year` max clamp is `now()->year` — requesting a future year is meaningless and currently silently produces zero-data pages.

`ReportsController::index(ReportYearRequest $request)` — replace the inline `$year = (int)(...)` with:
```php
$year = (int) ($request->validated('year') ?: Carbon::today()->year);
$available = DB::table('admissions')->selectRaw('DISTINCT YEAR(admit_date) y')
    ->whereNotNull('admit_date')->orderByDesc('y')->pluck('y')->filter()->values();
if ($available->isNotEmpty() && !$available->contains($year)) {
    $year = (int) $available->first(); // clamp to most-recent year with data
}
```
The clamp is applied before `gather($year)` so the page always renders with real data rather than all-zeros.

`Reports/Index.vue` empty state: after the existing summary KPI grid, add:
```html
<div v-if="totals.admissions === 0 && totals.discharges === 0"
     class="rounded-2xl bg-app p-10 text-center ring-1 ring-line">
    <p class="text-ink-400">No admissions or discharges recorded for {{ year }}.</p>
    <p v-if="availableYears.length" class="mt-2 text-sm text-ink-400">
        Available years: {{ availableYears.join(', ') }}
    </p>
</div>
```

`Registry/Index.vue` empty state: the existing results are a paginated object; when `results.data` is empty (not the first page load — check via a computed `hasSearched` flag triggered by any non-empty filter value):
```html
<div v-if="hasSearched && results.data.length === 0"
     class="rounded-xl bg-app py-12 text-center ring-1 ring-line">
    <p class="text-ink-500 font-medium">No admissions match the current filters.</p>
</div>
```
`hasSearched` computed: `Object.values(f).some(v => v !== '' && v !== false && !(Array.isArray(v) && v.length === 0))`.

Registry date/age filter validation: `RegistryController::index` currently has no validation at all. Add an inline validate at the top of `index()` (FormRequest would require a new file for minimal gain here):
```php
$request->validate([
    'from'     => ['nullable', 'date'],
    'to'       => ['nullable', 'date'],
    'age_from' => ['nullable', 'integer', 'min:0', 'max:150'],
    'age_to'   => ['nullable', 'integer', 'min:0', 'max:150'],
]);
```
This mirrors the pattern already used in `StatisticsController::index` and prevents garbage values from reaching the query builder.

**Decisions & defaults**
- Year clamp: redirect or silently clamp? Silently clamp (set `$year` to most recent available). A redirect loop is a worse user experience and the Inertia router preserves the URL state.
- Empty state in Reports: shown when both `totals.admissions === 0 && totals.discharges === 0` (a year with ICU-only activity would still show non-zero stats; this edge case is acceptable for the common "no data at all" case).

**Test plan** (`ParamValidationTest.php`):
- `test_reports_index_future_year_is_clamped_to_latest_available`: seed one admission in 2024; GET `/reports?year=2030`; assert Inertia prop `year === 2024`.
- `test_reports_pdf_future_year_rejected`: GET `/reports/pdf?year=2030`; assert 422 (FormRequest).
- `test_reports_pdf_invalid_year_rejected`: GET `/reports/pdf?year=abc`; assert 422.
- `test_registry_invalid_date_rejected`: admin GET `/registry?from=notadate`; assert 422.
- `test_registry_invalid_age_rejected`: admin GET `/registry?age_from=200`; assert 422.
- `test_reports_index_empty_state_prop_when_no_data`: GET `/reports?year=1990` (no data); assert Inertia props `totals.admissions === 0` (the Vue empty state is driven by this value; the prop-level assertion is sufficient).

**Build sequence**
1. Create `ReportYearRequest`. Write `test_reports_pdf_future_year_rejected` and `test_reports_pdf_invalid_year_rejected`. Swap into `pdf()` method. Green.
2. Implement year clamp in `index()`. Write `test_reports_index_future_year_is_clamped_to_latest_available`. Green.
3. Add inline validate to `RegistryController::index`. Write `test_registry_invalid_date_rejected` and `test_registry_invalid_age_rejected`. Green.
4. Add empty states to `Reports/Index.vue` and `Registry/Index.vue`. Build Vite. Commit.

---

### 3.8 Tests — cross-boundary value tests + export contract (effort M, risk Low)

**Goal / user value**
CI safety net: any query change that silently breaks a census, LOS, weekend, readmission, or bed-day metric is caught immediately. The "can't drift" export contract guarantees CSV and XLSX headers+rows are byte-identical.

**Files**

- Create `laravel/tests/Feature/ReportValueTest.php`
- Create `laravel/tests/Feature/ExportContractTest.php`
- Modify `laravel/tests/Feature/StatisticsValueTest.php` — add the YoY comparison value test from §3.5

**Design**

`ReportValueTest.php` — one fixture spanning two calendar months, an ICU episode, and a ward-to-ward transfer:

Fixture (seeded in `setUpFixture()`):
```
P1  Ward admit 2024-01-29, disch 2024-02-04 (outcome Alive, discharge from ward)
    -> spans month boundary; census counts in Jan AND Feb; LOS 6 days
P2  ICU  admit 2024-02-01, disch 2024-02-10 (outcome Dead, discharge from ICU)
    -> excluded from NON_ICU_SQL; ICU LOS 9 days
P3  Ward admit 2024-02-03, disch 2024-02-08 (outcome Alive, discharge from ward)
    -> LOS 5 days; Friday discharge 2024-02-09... adjust to 2024-02-09 (Fri = DAYOFWEEK 6)
P4  Ward admit 2024-01-15, active (no discharge_date)
    -> counts in Jan census; does NOT count in Feb discharges
```
Hand-computed expectations:
- `gather(2024)` month Jan: admissions=2 (P1, P4), discharges=1 (P1 discharges 2024-01-29→2024-02-04: discharged in Feb not Jan, so Jan discharges=0), census=2 (P1 active in Jan, P4 active in Jan).
  - Clarification: P1 admitted 01-29, discharged 02-04 — P1's `admit_date` is in Jan so it counts as a Jan admission; P1's `discharge_date` is in Feb so it counts as a Feb discharge. P4 admitted 01-15, active — Jan admission, Jan census.
  - Jan: admissions=2, discharges=0, deaths=0, icu=0
  - Feb: admissions=2 (P2, P3), discharges=3 (P1, P2, P3), deaths=1 (P2 — all locations), icu=1 (P2), census=3 (P1 active in Feb until 02-04; P2 active in Feb; P3 active in Feb)
- `avgLos` for Feb range (non-ICU discharges in Feb): P1 (LOS 6), P3 (LOS 5) → avg = 5.5
- `weekendByMonth` Feb: P3 discharged 2024-02-09 which is a Friday (DAYOFWEEK=6) → weekendDischarges=1
- `bedDays` Jan: P1 present Jan 29–31 = 3 days; P4 present Jan 15–31 = 17 days → total=20 (subject to the cap=min(last,today) formula in `censusFamilies`; use a seeded `today` Carbon fake or ensure the test runs in a known state using `Carbon::setTestNow`)
- `readmissions` in Feb range: P1 admitted Feb... wait, P1 has no readmission here. P4 active — no prior discharge. No readmissions. Expected=0.

Tests asserting exact values from `ReportsController::gather(2024)` via `GET /reports?year=2024` (Inertia prop assertions):
- `test_report_jan_admissions_count`: `months[0]['admissions'] === 2`
- `test_report_feb_discharges_count`: `months[1]['discharges'] === 3`
- `test_report_feb_icu_count`: `months[1]['icu'] === 1`
- `test_report_feb_deaths_count`: `months[1]['deaths'] === 1`
- `test_report_feb_avg_ward_los`: `avgLos === 5.5` (hand-computed for non-ICU discharges in range 2024-01-01→2024-12-31: (6+5)/2=5.5)
- `test_report_feb_weekend_discharge`: `months[1]['weekend'] === 1` (P3 on Friday 02-09)
- `test_report_jan_census_count`: `months[0]['census'] === 2` (gate: month 01 is fully elapsed)
- `test_report_no_readmissions_in_fixture`: `months[1]['readmits'] === 0`

Use `Carbon::setTestNow('2025-01-01')` in `setUp` so all "month is elapsed" and "cap to today" guards resolve deterministically.

`ExportContractTest.php` — the "can't drift" contract:

```php
public function test_csv_and_xlsx_emit_identical_headers_for_admissions_mode(): void
{
    // seed 3 admissions with known MRNs
    // GET /registry/export?mode=admissions -> parse CSV header row
    // GET /registry/export-xlsx?mode=admissions -> open XLSX with openspout Reader, read row 1
    // assert CSV header === XLSX header (same column order, same case)
}

public function test_csv_and_xlsx_emit_identical_row_values_for_admissions_mode(): void
{
    // same seed; assert row 1 data values match across formats
    // specifically assert MRN, LOS, consultant name
}

public function test_statistics_xlsx_kpi_grid_row_matches_index_kpi_grid(): void
{
    // StatisticsValueTest fixture
    // capture kpiGrid[0] from /statistics Inertia prop
    // parse first data row from /statistics/export XLSX
    // assert Adm column === kpiGrid[0]['admissions']
    // (imported from §3.4 — single test, placed here as the authoritative contract test)
}
```

The XLSX reader for test assertions: openspout is already a runtime dependency (`RegistryController` imports `OpenSpout\Writer\XLSX\Writer`); the reader (`OpenSpout\Reader\XLSX\Reader`) is in the same package and is available in tests without any additional require.

**Build sequence**
1. Write `ExportContractTest` first (it tests already-implemented export code and may catch existing bugs). Run — expect green. Commit as baseline.
2. Write `ReportValueTest` skeleton with `seedFixture()`. Add `Carbon::setTestNow` setup. Write all `test_report_*` tests as failing. Run — confirm they fail.
3. Fix any discrepancies found (e.g. a census formula edge case revealed by the month-boundary row). Each fix is a one-line change in `censusFamilies` or `byMonth`. Green. Commit.
4. Add the `test_statistics_xlsx_kpi_grid_row_matches_index_kpi_grid` test. Run — should already be green after §3.4 refactor. If not, fix `buildKpiGrid` extraction. Commit.

---

## Risks & sequencing notes

- **Items 3.1 and 3.4 must happen before 3.3 and 3.8**: both require `gatherBooklet` and `physician` to be promoted to `public`/`protected` and the `MetricQueries` trait to exist. Attempting item 3.3's job dispatch without that promotion causes a fatal private-method call.

- **The `MetricQueries` trait extraction (§3.1 step 1)** is the highest-leverage refactor in the phase. It enables `ReportsController` to call `physician()`, `buckets()`, `seriesBy()`, and `keyExpr()` without code duplication. Do it as the very first commit of the phase.

- **Item 3.6 (reliability) has the highest technical risk**: dompdf's memory behaviour under the monthly booklet (12 months × per-day grouped chart data) is environment-dependent. The `ini_set` stopgap and the raised `memory_limit` in `config/dompdf.php` must be validated on the production server before the queued path is built, or the queued job will also fail silently. Add a smoke test (time the synchronous monthly PDF against a realistic fixture) in step 1 of item 3.6 before building the async path.

- **Item 3.5 (YoY comparison) risks N+1 query amplification**: the comparison period requires a second full set of `seriesBy` calls. For a year-interval range at monthly bucketing this is 5 additional SQL queries — acceptable. For `?interval=day` over a 365-day comparison, it is 5 queries over ~370 buckets which are still grouped queries (not N+1), so performance is safe. The day-interval cap (370 buckets) already exists in `buckets()` and applies to both the primary and comparison series.

- **Vite build and asset commit**: every item that touches a `.vue` file requires a Vite build run (`npm run build` from `laravel/`) and committing `public/build`. Items 3.1, 3.2, 3.4, 3.5, 3.6, 3.7 all modify Vue files. Group the Vite build at the end of each item rather than mid-build-sequence to minimise churn.

- **`ReportYearRequest` max year clamp**: `now()->year` is evaluated at request time. If the server's timezone differs from the clinical timezone, a request on December 31 at 23:00 local time could be rejected if the server is already in the next year. Safe default: use `now()->year + 1` as the max, accepting a one-year lookahead. Flag for ops: confirm `APP_TIMEZONE` in `.env` matches the hospital's local timezone.

- **openspout multi-sheet**: if `openspout/openspout` v3 (the version likely installed) does not expose `addNewSheetAndMakeItCurrent()` on the `XlsxWriter`, item 3.4 falls back to single-sheet with section headers. Confirm the installed version's API before building the export; add a note to the implementation task.

---

## Phase 4 — Security & data-integrity hardening

Phase 4 addresses the remaining systemic risks that survive after the security perimeter was established in earlier phases: data can still be hard-deleted without a recoverable safety net, sessions have no timeout, login anomalies are invisible to admins, the highest-risk destructive actions have no second confirmation factor, ICD-10 codes are stored without validation, data-quality problems accumulate silently, the database has no structural date-ordering constraint, the import pipeline is incomplete on several rejection criteria, and no tooling exists to merge the duplicate patient identities that the legacy MRN scheme created. Each item below resolves one of those gaps in a way that integrates with the existing patterns: `AuditLog::create` already on every write, `Setting::current()` for tunable thresholds, `Admission::NON_ICU_SQL` / `readmissionJoin()` as the metric single source of truth, `EnsureAdmin` / `isObserver()` for access gates, and `RefreshDatabase` PHPUnit tests against the real MySQL `dmc_test` schema.

---

### 1. Soft-deletes on Admission, Consultation, and User (effort M, risk LOW)

**Goal / user value**
Destructive actions are currently unrecoverable without a DB backup restore. Adding `deleted_at` + Eloquent `SoftDeletes` gives a 30-second admin undo path for misclicks, satisfies any future PDPL data-subject access requirement (a soft-deleted row still appears in exports), and keeps audit provenance intact.

**Files**

- CREATE `laravel/database/migrations/2026_06_14_000001_add_soft_deletes.php`
- MODIFY `laravel/app/Models/Admission.php`
- MODIFY `laravel/app/Models/Consultation.php`
- MODIFY `laravel/app/Models/User.php`
- MODIFY `laravel/app/Http/Controllers/PatientActionController.php` (`destroy`)
- MODIFY `laravel/app/Http/Controllers/ConsultationsController.php` (`destroy`)
- MODIFY `laravel/app/Http/Controllers/ControlController.php` (`destroyUser`)
- CREATE `laravel/app/Http/Controllers/TrashedController.php`
- CREATE `laravel/database/migrations/2026_06_14_000002_fix_analytics_trashed_rows.php` (comment migration, no schema change — this is the "go and add `whereNull('deleted_at')`" checklist enforced by a separate data test)
- ADD to `laravel/routes/web.php` (admin group): trashed routes
- MODIFY `laravel/resources/js/Pages/Control/Index.vue` (new "Recently Deleted" tab)
- CREATE `laravel/tests/Feature/SoftDeleteTest.php`

**Design**

Migration adds `deleted_at TIMESTAMP NULL` to `admissions`, `consultations`, and `users`. The `users` table also needs the existing `cascadeOnDelete` FK references reconsidered — soft-deleting a user must NOT cascade-delete their admissions (it does not; the FK is `nullOnDelete` on the admission side, and `User::delete()` no longer causes a physical DELETE so the FK is never triggered).

```php
// 2026_06_14_000001_add_soft_deletes.php
Schema::table('admissions',    fn (Blueprint $t) => $t->softDeletes());
Schema::table('consultations', fn (Blueprint $t) => $t->softDeletes());
Schema::table('users',         fn (Blueprint $t) => $t->softDeletes());
```

`Admission`, `Consultation`, and `User` each gain `use SoftDeletes;`. Eloquent global scope automatically adds `WHERE deleted_at IS NULL` to every query that goes through these models — existing board/list queries need no change.

The `DB::table('admissions')` calls in `StatisticsController`, `ReportsController`, `DashboardController`, and `RegistryController` bypass the Eloquent scope and must add explicit `->whereNull('admissions.deleted_at')` (and `->whereNull('consultations.deleted_at')` where consultations are joined). The migration `2026_06_14_000002` documents the exhaustive list as commented markers and a failing-test-driven checklist.

`PatientActionController::destroy` changes from hard-delete to soft-delete. The existing pattern (`audit` row first, then `$admission->delete()`) is unchanged; `SoftDeletes::delete()` sets `deleted_at` instead of removing the row. The audit action string stays `'admission.delete'`. Diagnosis rows are NOT soft-deleted (they are children of the admission row and the cascade FK still handles hard-delete on purge; for soft-delete they are naturally hidden with their parent). `ConsultationsController::destroy` and `ControlController::destroyUser` follow the same pattern.

`TrashedController` (admin-only, inside the existing `Route::middleware('admin')` group):

```
GET  /trashed               -> TrashedController::index
POST /trashed/admissions/{id}/restore  -> TrashedController::restoreAdmission
POST /trashed/consultations/{id}/restore -> TrashedController::restoreConsultation
POST /trashed/users/{id}/restore       -> TrashedController::restoreUser
```

`index()` runs three `withTrashed()->whereNotNull('deleted_at')` queries capped at 100 rows each, ordered by `deleted_at DESC`. Restore methods call `$model->withTrashed()->findOrFail($id)->restore()` inside a transaction and write an audit row with action `'admission.restore'` / `'consultation.restore'` / `'user.restore'`. No auto-purge scheduled command is added (default: keep forever + manual restore, as specified); a `--dry-run` purge command can be added in Phase 5 when a PDPL retention policy is decided.

`Control/Index.vue` gains a fourth tab label "Recently Deleted" that `router.visit('/trashed')`, opening onto `Trashed/Index.vue` (a simple three-section list with Restore buttons, no new design tokens needed).

**Decisions & defaults**
- Auto-purge: OFF by default. The setting is not added to `settings` table yet — a future `retention_days` field can be added when a clinical/legal retention policy is confirmed.
- Restoring a soft-deleted admission that would produce a duplicate-active-MRN is rejected with a validation error (same guard as `StoreAdmissionRequest::withValidator`).
- User restore: re-activating a soft-deleted user is allowed only if their `active` flag stays at its stored value (admin can re-activate separately from restore).

**Test plan** (`laravel/tests/Feature/SoftDeleteTest.php`)

- `test_admin_soft_deletes_admission_leaves_row_in_db` — DELETE request; assert `admissions.deleted_at` IS NOT NULL, row count unchanged.
- `test_deleted_admission_invisible_to_board` — GET `/patients`; assert the deleted admission's ID is absent from the Inertia props.
- `test_stats_exclude_soft_deleted_admissions` — write a soft-deleted admission inside the date range; assert KPI counts via direct SQL match counts excluding `deleted_at IS NOT NULL` rows.
- `test_admin_can_restore_admission` — POST `/trashed/admissions/{id}/restore`; assert `deleted_at` IS NULL; board shows the row again.
- `test_restore_blocked_when_duplicate_active_mrn` — soft-delete an active admission, admit a new one with the same MRN, attempt restore; assert 422 / redirect with error.
- `test_audit_row_written_before_delete` — assert `audit_log` has the `admission.delete` row and its `created_at` is before or equal to the `deleted_at` timestamp.

**Build sequence**
1. Write `SoftDeleteTest` (all assertions failing). Run → all red.
2. Migration `add_soft_deletes` — run `php artisan migrate` on `dmc_test`.
3. Add `use SoftDeletes` to three models.
4. Change `PatientActionController::destroy` / `ConsultationsController::destroy` / `ControlController::destroyUser` to use soft-delete.
5. Run tests — board/stats tests may turn red (deleted rows now visible to `DB::table` analytics).
6. Add `whereNull('deleted_at')` to every `DB::table` analytics call in Statistics/Reports/Dashboard/Registry controllers. Run → green.
7. Implement `TrashedController` + routes + Vue page.
8. Run full suite → green. Commit.

---

### 2. Idle + absolute session timeout (effort S, risk LOW)

**Goal / user value**
An unattended clinical workstation with a live session is a PHI exposure. Auto-logout after 30 minutes of inactivity with a 60-second client warning satisfies minimum clinical privacy requirements.

**Files**

- CREATE `laravel/app/Http/Middleware/SessionTimeout.php`
- CREATE `laravel/database/migrations/2026_06_14_000003_add_session_timeout_to_settings.php`
- MODIFY `laravel/app/Http/Controllers/ControlController.php` (`updateSettings` rules + `index` props)
- MODIFY `laravel/resources/js/Layouts/AppLayout.vue` (idle warning overlay)
- MODIFY `laravel/bootstrap/app.php` (register middleware alias `session.timeout`)
- MODIFY `laravel/routes/web.php` (append `session.timeout` to the `auth` group)
- MODIFY `laravel/resources/js/Pages/Control/Index.vue` (two new settings fields)
- CREATE `laravel/tests/Feature/SessionTimeoutTest.php`

**Design**

Migration adds to `settings`:

```sql
idle_timeout_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 30
abs_timeout_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 0   -- 0 = disabled
```

`SessionTimeout` middleware runs on every authenticated request. It reads `session('last_activity_at')` (a Unix timestamp written on the previous request). If `now() - last_activity_at > idle_timeout_minutes * 60` it calls `Auth::logout()`, invalidates the session, and returns `redirect()->route('login')->with('flash', ...)`. Absolute timeout: if `session('session_started_at')` is set and `now() - session_started_at > abs_timeout_minutes * 60` (when `abs_timeout_minutes > 0`) it does the same. After passing the checks it stamps `session(['last_activity_at' => now()->getTimestamp()])`. On fresh login, `session_started_at` is stamped.

`AuthController::login` and `MfaController::verifyChallenge` (the two Auth::login call sites) both stamp `session_started_at` immediately after `$request->session()->regenerate()`. The MFA path already calls `Auth::login($user, false)` — this is preserved unchanged.

`HandleInertiaRequests::share` adds two shared props:

```php
'idleTimeoutMinutes'  => (int) Setting::current()->idle_timeout_minutes,
'absTimeoutMinutes'   => (int) Setting::current()->abs_timeout_minutes,
```

`AppLayout.vue` receives these from `usePage().props`. A `useIdleTimeout` composable (inlined into AppLayout, ~40 lines) tracks `mousemove`, `keydown`, `click`, and `scroll` events to reset a last-activity timestamp in JS. When JS-local idle exceeds `(idleTimeoutMinutes - 1) * 60` seconds it shows a warning overlay ("Session expiring in 60 seconds — click to stay"). After the full `idleTimeoutMinutes * 60` seconds it calls `router.post('/logout')`. The server middleware is the authoritative enforcer; the client overlay is a UX convenience that avoids the abrupt 419-on-next-request experience.

`ControlController::updateSettings` gains `idle_timeout_minutes` (`integer`, `min:5`, `max:480`) and `abs_timeout_minutes` (`integer`, `min:0`, `max:1440`) validation rules. The `fieldLabels` map in `Control/Index.vue` gains entries for these two fields.

**Decisions & defaults**
- Default idle: 30 minutes. Default absolute: 0 (disabled).
- Observers are subject to the same timeout — no role exemption.
- AJAX/Inertia partial requests count as activity (they hit the middleware).
- The 60-second warning countdown is client-only; it does not need server state.

**Test plan** (`laravel/tests/Feature/SessionTimeoutTest.php`)

- `test_request_within_idle_window_succeeds` — stamp `last_activity_at` 5 minutes ago with 30-minute timeout; GET `/` → 200.
- `test_request_after_idle_timeout_is_redirected_to_login` — stamp `last_activity_at` 31 minutes ago; GET `/patients` → assert redirect to `/login`.
- `test_absolute_timeout_logs_out_even_with_recent_activity` — `abs_timeout_minutes = 60`, stamp `session_started_at` 61 minutes ago, `last_activity_at` 1 minute ago; GET `/patients` → redirect to `/login`.
- `test_absolute_timeout_zero_disables_absolute_limit` — `abs_timeout_minutes = 0`, `session_started_at` 999 minutes ago; GET `/patients` → 200.
- `test_login_stamps_session_started_at` — POST `/login` → assert `session('session_started_at')` is set.
- `test_mfa_login_stamps_session_started_at` — POST `/mfa/challenge` → assert `session('session_started_at')` is set.

**Build sequence**
1. Write `SessionTimeoutTest` (all failing).
2. Migration `add_session_timeout_to_settings`. Update `Setting::current()` memoization (no change needed; `once()` is already request-scoped).
3. Implement `SessionTimeout` middleware; register alias in `bootstrap/app.php`; append to `auth` group in `routes/web.php`.
4. Stamp `session_started_at` in `AuthController::login` and `MfaController::verifyChallenge`.
5. Run tests → green.
6. Add `idleTimeoutMinutes`/`absTimeoutMinutes` to `HandleInertiaRequests::share`.
7. Add idle-warning overlay to `AppLayout.vue`.
8. Add settings fields to `ControlController` + `Control/Index.vue`. Commit.

---

### 3. Login-anomaly detection and Security panel (effort M, risk LOW)

**Goal / user value**
Failed logins are currently invisible to admins; an ongoing brute-force or credential-stuffing attack on a clinical account goes undetected. The Security panel gives admins visibility and enables proactive account lockdown.

**Files**

- MODIFY `laravel/app/Http/Controllers/Auth/AuthController.php` (`login`)
- MODIFY `laravel/app/Http/Controllers/MfaController.php` (`verifyChallenge`)
- CREATE `laravel/app/Http/Controllers/SecurityController.php`
- MODIFY `laravel/routes/web.php` (admin group: new security routes)
- CREATE `laravel/resources/js/Pages/Security/Index.vue`
- MODIFY `laravel/resources/js/Layouts/AppLayout.vue` (add Security nav item to admin section)
- MODIFY `laravel/database/migrations/2026_06_14_000003_add_session_timeout_to_settings.php` (or a new migration): add `failed_login_notify_threshold` to `settings`
- CREATE `laravel/tests/Feature/LoginAnomalyTest.php`

**Design**

`AuthController::login` already throws `ValidationException` on bad credentials. Add an audit call before the throw:

```php
AuditLog::create([
    'actor_id'    => null,
    'actor_name'  => $data['username'],
    'action'      => 'login.failed',
    'entity_type' => 'user',
    'entity_id'   => null,
    'details'     => ['reason' => 'bad_credentials'],
    'ip'          => $request->ip(),
]);
```

On success add:

```php
AuditLog::create([
    'actor_id'    => $user->id,
    'actor_name'  => $user->name,
    'action'      => 'login.success',
    'entity_type' => 'user',
    'entity_id'   => (string) $user->id,
    'details'     => ['mfa' => false],
    'ip'          => $request->ip(),
]);
```

`MfaController::verifyChallenge` already audits `'login.mfa'` (existing `$this->audit($user, 'login.mfa')`). Change the action string to `'login.success'` with `details => ['mfa' => true]` for consistency. Failed MFA code attempts audit `'login.failed'` with `details => ['reason' => 'bad_mfa_code', 'attempts' => $attempts]` using `actor_name = $user->name` and `actor_id = $user->id` (the identity is already known at that point).

Migration adds to `settings`:

```sql
failed_login_notify_threshold  TINYINT UNSIGNED NOT NULL DEFAULT 5   -- 0 = disabled
```

`SecurityController` (admin-only, no separate middleware needed beyond the existing `admin` group):

```
GET /security  -> SecurityController::index
```

`index()` queries the existing `audit_log` table:

```php
// Last 24h failed-login clusters per account/IP (top 20)
$failedClusters = DB::table('audit_log')
    ->where('action', 'login.failed')
    ->where('created_at', '>=', now()->subHours(24))
    ->selectRaw('actor_name, ip, COUNT(*) attempts, MAX(created_at) last_at')
    ->groupBy('actor_name', 'ip')
    ->orderByDesc('attempts')
    ->limit(20)
    ->get();

// First-time-seen IPs for known users (new IP since the start of recorded history)
$firstSeenIps = DB::table('audit_log as a')
    ->whereIn('action', ['login.success', 'login.failed'])
    ->whereNotNull('actor_id')
    ->whereNotExists(fn ($q) => $q->from('audit_log as b')
        ->whereColumn('b.actor_id', 'a.actor_id')
        ->whereColumn('b.ip', 'a.ip')
        ->where('b.id', '<', DB::raw('a.id')))
    ->orderByDesc('a.created_at')
    ->limit(25)
    ->selectRaw('a.actor_name, a.ip, a.created_at first_at')
    ->get();

// MFA-noncompliant users (mfa_enforcement on + admin or everyone, depending on level, but no mfa_enrolled_at)
$level = (int) Setting::current()->mfa_enforcement;
$mfaNonCompliant = $level > 0
    ? User::withoutTrashed() // or withTrashed() if soft deletes added — only active users matter
        ->where('active', 1)
        ->whereNull('mfa_enrolled_at')
        ->when($level === 1, fn ($q) => $q->where('role', User::ROLE_ADMIN))
        ->orderBy('role')->orderBy('full_name')
        ->get(['id', 'username', 'full_name', 'role', 'created_at'])
    : collect();
```

**Notification on consecutive failures**: after writing the `login.failed` audit row, if `failed_login_notify_threshold > 0`, count recent failures for that username in the last 10 minutes. If the new count equals the threshold, create a `Notification` row (using the existing `App\Models\Notification` pattern) for every active Admin (`role=0`):

```php
$recentFailures = DB::table('audit_log')
    ->where('action', 'login.failed')
    ->where('actor_name', $data['username'])
    ->where('created_at', '>=', now()->subMinutes(10))
    ->count();
if ($threshold > 0 && $recentFailures === $threshold) {
    foreach (User::where('role', User::ROLE_ADMIN)->where('active', 1)->pluck('id') as $adminId) {
        Notification::create(['user_id' => $adminId, 'type' => 'security.failed_logins', 'created_at' => now(),
            'payload' => ['username' => $data['username'], 'count' => $recentFailures, 'ip' => $request->ip()]]);
    }
}
```

The bell dropdown in `AppLayout.vue` already renders `Notification` rows; a new `notifText` branch handles `type === 'security.failed_logins'`.

`Security/Index.vue` uses the existing design tokens: three sections (Failed Login Clusters, First-Seen IPs, MFA Non-Compliant Users), each as a card with a `<table>`. No new ApexChart needed — raw counts in a table are adequate.

**Decisions & defaults**
- Default notify threshold: 5 consecutive failures in 10 minutes. Zero disables notifications.
- "First-seen IP" is defined as any audit row where no earlier row from the same `actor_id` + `ip` pair exists. This is an approximation (audit log starts from deployment date) but is sufficient for anomaly surfacing. Flag for clinician sign-off: the threshold and time window are admin-tunable.
- The Security panel is read-only. Account lockdown is done via the existing Control Panel user edit (set `active = false`).

**Test plan** (`laravel/tests/Feature/LoginAnomalyTest.php`)

- `test_failed_login_writes_audit_row` — POST `/login` with bad credentials; assert `audit_log` has `action = 'login.failed'` with the submitted username as `actor_name` and correct IP.
- `test_successful_login_writes_audit_row` — POST `/login` correctly; assert `action = 'login.success'`, `actor_id` matches user.
- `test_mfa_failure_writes_audit_row` — POST `/mfa/challenge` with bad code; assert `action = 'login.failed'`, `actor_id` matches pending user.
- `test_notification_fired_at_threshold` — POST `/login` with bad creds `N` times (threshold=N); assert a `Notification` row created for the admin user.
- `test_notification_not_fired_below_threshold` — POST `N-1` times; assert no notification row.
- `test_security_panel_accessible_to_admin` — GET `/security` as admin → 200, Inertia response with `failedClusters`/`firstSeenIps`/`mfaNonCompliant` props.
- `test_security_panel_denied_to_non_admin` — GET `/security` as consultant → 403.

**Build sequence**
1. Write `LoginAnomalyTest` (failing).
2. Migration for `failed_login_notify_threshold`.
3. Add `AuditLog::create` calls to `AuthController::login` (failed + success paths) and `MfaController::verifyChallenge` (failed + success, changing `'login.mfa'` → `'login.success'`).
4. Add the threshold-notification block to `AuthController::login`.
5. Run tests → green.
6. Implement `SecurityController::index` + route + `Security/Index.vue`.
7. Add Security link to `AppLayout.vue` admin nav. Commit.

---

### 4. Step-up re-authentication for highest-risk actions (effort M, risk MEDIUM)

**Goal / user value**
Role escalation (`role -> 0`), account delete, hard admission delete, and reverse-discharge are the four actions with the most severe consequences if performed in error or under a session hijack. Requiring the admin to re-enter their password (or TOTP code if enrolled) provides a second confirmation barrier beyond the session.

**Files**

- CREATE `laravel/app/Http/Middleware/RequireStepUp.php`
- CREATE `laravel/app/Http/Controllers/StepUpController.php`
- MODIFY `laravel/routes/web.php` (step-up routes; add `stepup` middleware to four specific action routes)
- MODIFY `laravel/app/Http/Controllers/PatientActionController.php` (`destroy`, `reverseDischarge`)
- MODIFY `laravel/app/Http/Controllers/ControlController.php` (`updateUser`, `destroyUser`)
- CREATE `laravel/resources/js/Pages/Auth/StepUp.vue`
- CREATE `laravel/tests/Feature/StepUpTest.php`

**Design**

Step-up state lives in the session: `stepup.verified_at` (Unix timestamp) + `stepup.intended` (URL to return to). The window is 5 minutes — short enough to be meaningful but long enough for admin work on the Control panel.

`RequireStepUp` middleware:

```php
// If last step-up was within 5 minutes, pass through.
$verifiedAt = session('stepup.verified_at');
if ($verifiedAt && (now()->getTimestamp() - $verifiedAt) <= 300) {
    return $next($request);
}
// Store the intended destination and redirect to the step-up form.
session(['stepup.intended' => $request->fullUrl()]);
return redirect()->route('stepup.show');
```

`StepUpController`:

```
GET  /stepup  -> show()   — renders StepUp.vue with a password field (+ TOTP field if enrolled)
POST /stepup  -> verify() — verifies credentials then redirects to stepup.intended
```

`verify()` checks `Hash::check($request->password, $user->password)`. If the user has MFA enrolled it also requires a valid TOTP code (using the existing `Totp::verifyWithCounter` from `laravel/app/Support/Totp.php`) and updates `mfa_last_counter` (replay guard). On success: `session(['stepup.verified_at' => now()->getTimestamp()])`, remove `stepup.intended`, write an audit row (`action = 'stepup.verified'`, `entity_type = 'user'`, `entity_id = (string) Auth::id()`), then `return redirect(session('stepup.intended', route('control.index')))`. On failure: `ValidationException` — the step-up form does not throttle independently (it is behind auth + the normal session-timeout middleware, so the session is already limited).

The audit row for the triggering action (e.g. `admission.delete`) already written inside the action carries the normal attribution. An additional `details` field `'step_up' => true` is appended in those four actions to signal the step-up occurred. This is done by reading `session('stepup.verified_at')` inside the action and adding the key if present.

Four protected routes — add `->middleware('stepup')`:

```php
// routes/web.php, inside admin group:
Route::delete('/admissions/{admission}', ...)->middleware('stepup');
Route::post('/admissions/{admission}/reverse-discharge', ...)->middleware('stepup');
Route::delete('/control/users/{user}', ...)->middleware('stepup');
```

For `updateUser` when `role` changes to `0` (role escalation), the step-up cannot be a route-level middleware (the route is shared with non-escalating updates). Instead, `ControlController::updateUser` checks manually:

```php
if ((int)$data['role'] === User::ROLE_ADMIN && (int)$user->role !== User::ROLE_ADMIN) {
    $verifiedAt = session('stepup.verified_at');
    if (!$verifiedAt || (now()->getTimestamp() - $verifiedAt) > 300) {
        session(['stepup.intended' => route('control.index')]);
        return redirect()->route('stepup.show')
            ->with('flash', ['type' => 'error', 'message' => 'Re-authentication required to grant admin access.']);
    }
}
```

`StepUp.vue` is a minimal centered card (reusing existing `bg-card`, `ring-line`, `font-display` tokens): a heading "Confirm your identity", a password field, a TOTP code field (shown only if `auth.user.mfa_enrolled` — add this boolean to `HandleInertiaRequests::share`), a submit button, and a cancel link back to the dashboard. The page reuses the `PasswordMeter` component structure for visual consistency without importing it (step-up does not need strength feedback).

**Decisions & defaults**
- Window: 5 minutes (non-configurable; not worth an admin-tunable setting for four endpoints).
- Throttle: step-up inherits the session timeout; no extra rate limit is added (the session is already authenticated; a brute-force on the step-up form would require an already-authenticated session and would show in the session-timeout logs).
- MFA enrolled admins must provide BOTH password AND current TOTP code (belt and suspenders). Non-MFA admins provide password only.

**Test plan** (`laravel/tests/Feature/StepUpTest.php`)

- `test_delete_admission_without_stepup_redirects` — DELETE `/admissions/{id}` with fresh session (no `stepup.verified_at`) → assert redirect to `/stepup`.
- `test_delete_admission_with_valid_stepup_succeeds` — stamp `stepup.verified_at = now()->getTimestamp()` in session → DELETE → assert soft-deleted (or 302 success).
- `test_delete_admission_with_expired_stepup_redirects` — stamp `stepup.verified_at = now()->subMinutes(6)->getTimestamp()` → DELETE → redirect to `/stepup`.
- `test_stepup_verify_correct_password_stamps_session` — POST `/stepup` with correct password → assert `session('stepup.verified_at')` is recent.
- `test_stepup_verify_wrong_password_fails` — POST `/stepup` with wrong password → `assertSessionHasErrors('password')`.
- `test_stepup_verify_mfa_admin_requires_totp` — MFA-enrolled admin: POST `/stepup` with correct password but no/wrong TOTP → `assertSessionHasErrors('code')`.
- `test_role_escalation_to_admin_requires_stepup` — PUT `/control/users/{id}` with `role=0` and no recent step-up → redirect to `/stepup`.
- `test_audit_row_contains_step_up_flag` — perform a step-up-verified delete; assert `audit_log.details` JSON contains `"step_up": true`.

**Build sequence**
1. Write `StepUpTest` (failing).
2. Implement `RequireStepUp` middleware + `StepUpController` + routes.
3. Attach `stepup` middleware to the three DELETE routes.
4. Add inline step-up guard to `ControlController::updateUser` for role-to-admin escalation.
5. Add `step_up` detail flag in the four action methods when `stepup.verified_at` is in session.
6. Run tests → green.
7. Implement `StepUp.vue`.
8. Add `mfa_enrolled` boolean to `HandleInertiaRequests::share`. Commit.

---

### 5. ICD-10 code validation (effort S, risk LOW)

**Goal / user value**
Unknown ICD-10 codes silently committed to `admission_diagnoses` corrupt statistics (diagnosis-based counts) and are invisible until someone queries the `icd10` table. Validate new codes at admit/modify time; warn (not reject) on bulk import.

**Files**

- MODIFY `laravel/app/Http/Requests/StoreAdmissionRequest.php` (`rules()`)
- MODIFY `laravel/app/Http/Requests/ModifyAdmissionRequest.php` (`rules()`)
- MODIFY `laravel/app/Http/Controllers/ImportController.php` (`parse()`)
- CREATE `laravel/app/Http/Controllers/OrphanDiagnosesController.php`
- MODIFY `laravel/routes/web.php` (admin group: orphan report route)
- CREATE `laravel/tests/Feature/Icd10ValidationTest.php`

**Design**

`StoreAdmissionRequest::demographicRules()` is shared between Store and Modify (the Modify request calls `StoreAdmissionRequest::demographicRules()`). The diagnosis rule in `demographicRules()` changes from:

```php
'diagnoses.*' => ['string', 'max:100'],
```

to:

```php
'diagnoses.*' => ['string', 'max:100', Rule::exists('icd10', 'code')],
```

This applies the Rule::exists check on every code in the array for new admissions (full enforcement). The `icd10` table has 72,751 rows; `Rule::exists` issues one `SELECT 1 FROM icd10 WHERE code = ?` per submitted code — at most 10–15 codes per admission, totally acceptable.

`ModifyAdmissionRequest::rules()` already uses `validate-only-on-change` for demographics. The same principle applies to diagnoses: the entire `diagnoses` array is compared to the stored codes. A modified admission that only changes a bed or date must not be blocked by pre-existing dirty codes. The logic:

```php
// In ModifyAdmissionRequest::rules()
$storedCodes = $admission?->diagnoses->pluck('icd10_code')->all() ?? [];
$submitted   = array_map('trim', $request->input('diagnoses', []));
$newCodes    = array_diff($submitted, $storedCodes);

$rules['diagnoses.*'] = count($newCodes) > 0
    ? ['string', 'max:100', Rule::exists('icd10', 'code')]
    : ['string', 'max:100'];
```

This means: if ANY submitted code is not in the stored set, all submitted codes are validated against `icd10`. If the submitted array is a subset of or identical to the stored array (only removing or reordering), the Rule::exists is skipped — preserving dirty-data editability for unrelated corrections.

`ImportController::parse()` adds a per-row warning (not `$row['ok'] = false`) for each unrecognized code. Since the import can have many rows and checking per-code would produce N×codes queries, build a lookup set once per parse run:

```php
// After collecting all codes from the batch:
$allCodes = collect($out)->flatMap(fn ($r) => $r['diagnoses'])->unique()->filter()->values()->all();
$validCodes = count($allCodes)
    ? DB::table('icd10')->whereIn('code', $allCodes)->pluck('code')->flip()->all()
    : [];
// Then in each row:
$badCodes = array_filter($row['diagnoses'], fn ($c) => !isset($validCodes[$c]));
if ($badCodes) {
    $row['warning'] = ($row['warning'] ? $row['warning'] . '; ' : '') . 'Unknown ICD-10: ' . implode(', ', $badCodes);
}
```

`OrphanDiagnosesController::index()` (admin, GET `/admin/orphan-diagnoses`):

```php
$orphans = DB::table('admission_diagnoses as ad')
    ->leftJoin('icd10', 'icd10.code', '=', 'ad.icd10_code')
    ->whereNull('icd10.code')
    ->join('admissions as a', 'a.id', '=', 'ad.admission_id')
    ->join('patients as p', 'p.id', '=', 'a.patient_id')
    ->whereNull('a.deleted_at')   // skip soft-deleted admissions
    ->selectRaw('ad.icd10_code, COUNT(DISTINCT ad.admission_id) admissions, MAX(a.admit_date) last_seen')
    ->groupBy('ad.icd10_code')
    ->orderByDesc('admissions')
    ->get();
```

Returns an Inertia page `Admin/OrphanDiagnoses.vue` — a simple table with code, admission count, last_seen date, and a note "These codes were imported before ICD-10 validation was enforced." No patient links needed (the admin can use the Registry to find specific admissions). Add to the admin nav as a link from the Control panel (a "Data Quality" section added in item 6 below).

**Decisions & defaults**
- New admissions: all codes fully validated (STRICT).
- Modify: validate-only-on-change (LENIENT for legacy dirty codes).
- Import: WARNING only — existing import behavior is preserved; the admin sees the warning in the preview and can choose to import anyway.
- Orphan report: read-only; no bulk-fix tooling (that is a Phase 5 or manual clinical decision).

**Test plan** (`laravel/tests/Feature/Icd10ValidationTest.php`)

- `test_unknown_code_rejected_on_new_admission` — admit with `diagnoses: ['ZZZZZ']`; assert `assertSessionHasErrors('diagnoses.0')`.
- `test_known_code_accepted_on_new_admission` — insert an `icd10` row with `code = 'A00'`; admit with `diagnoses: ['A00']`; assert redirect (success).
- `test_modify_with_unchanged_dirty_code_succeeds` — store an admission with a known-bad code directly via `DB::table`; PUT `/admissions/{id}/modify` with the same codes untouched and only a bed change; assert success (no ICD-10 error).
- `test_modify_adding_unknown_code_fails` — same setup; add a new unknown code to the request; assert `assertSessionHasErrors('diagnoses.1')` (or `.0` depending on position).
- `test_import_flags_unknown_codes_as_warning_not_rejection` — call `parse()` with a row containing an unknown code via reflection or a sub-request; assert `$row['ok'] === true` and `$row['warning']` contains the bad code.
- `test_orphan_diagnoses_report_accessible` — GET `/admin/orphan-diagnoses` as admin; assert 200 and the `orphans` prop contains the manually-inserted orphan row.

**Build sequence**
1. Write `Icd10ValidationTest` (failing).
2. Modify `demographicRules()` — add `Rule::exists('icd10', 'code')` to `diagnoses.*`.
3. Modify `ModifyAdmissionRequest::rules()` — add validate-on-change logic for diagnosis codes.
4. Run tests → green for new-admission and modify tests.
5. Modify `ImportController::parse()` — bulk-collect codes, single lookup, add warnings.
6. Run import tests → green.
7. Implement `OrphanDiagnosesController` + route + Vue page. Commit.

---

### 6. Data-quality dashboard (effort M, risk LOW)

**Goal / user value**
Dirty data accumulates silently (patients with no diagnosis, future admit dates, multiple open episodes). A single admin page listing every canary condition with a patient link gives the clinical coordinator a daily data-hygiene checklist.

**Files**

- CREATE `laravel/app/Http/Controllers/DataQualityController.php`
- MODIFY `laravel/routes/web.php` (admin group: `/data-quality`)
- CREATE `laravel/resources/js/Pages/Admin/DataQuality.vue`
- MODIFY `laravel/resources/js/Layouts/AppLayout.vue` (add nav link)
- CREATE `laravel/app/Console/Commands/DataQualityNotify.php`
- MODIFY `laravel/routes/console.php` (schedule the command)
- CREATE `laravel/tests/Feature/DataQualityTest.php`

**Design**

`DataQualityController::index()` runs five queries. Each returns rows linking to the patient board:

```php
// Q1: active non-longterm with LOS > long_los × N (default N=2, admin-tunable via a new
//     settings field `dq_los_multiplier` TINYINT DEFAULT 2)
$longLos    = (int) Setting::current()->long_los;
$multiplier = (int) (Setting::current()->dq_los_multiplier ?? 2);
$overLos = DB::table('admissions as a')
    ->join('patients as p', 'p.id', '=', 'a.patient_id')
    ->whereNull('a.discharge_date')
    ->where('a.is_longterm', 0)
    ->whereRaw('DATEDIFF(CURDATE(), a.admit_date) > ?', [$longLos * $multiplier])
    ->whereNull('a.deleted_at')
    ->selectRaw('a.id, p.mrn, p.name, DATEDIFF(CURDATE(), a.admit_date) los, a.admit_date')
    ->orderByDesc('los')
    ->limit(50)->get();

// Q2: active episodes with zero diagnoses
$noDx = DB::table('admissions as a')
    ->join('patients as p', 'p.id', '=', 'a.patient_id')
    ->leftJoin('admission_diagnoses as ad', 'ad.admission_id', '=', 'a.id')
    ->whereNull('a.discharge_date')->whereNull('a.deleted_at')
    ->whereNull('ad.id')
    ->selectRaw('a.id, p.mrn, p.name, a.admit_date')
    ->orderBy('a.admit_date')
    ->limit(50)->get();

// Q3: closed episodes where discharge_date < admit_date OR admit_date > today
$badDates = DB::table('admissions as a')
    ->join('patients as p', 'p.id', '=', 'a.patient_id')
    ->whereNull('a.deleted_at')
    ->where(fn ($w) => $w
        ->whereRaw('a.discharge_date IS NOT NULL AND a.discharge_date < a.admit_date')
        ->orWhereRaw('a.admit_date > CURDATE()'))
    ->selectRaw('a.id, p.mrn, p.name, a.admit_date, a.discharge_date')
    ->limit(50)->get();

// Q4: diagnosis codes not in icd10 (orphan codes on active episodes)
$orphanDx = DB::table('admission_diagnoses as ad')
    ->join('admissions as a', 'a.id', '=', 'ad.admission_id')
    ->join('patients as p', 'p.id', '=', 'a.patient_id')
    ->leftJoin('icd10', 'icd10.code', '=', 'ad.icd10_code')
    ->whereNull('icd10.code')
    ->whereNull('a.deleted_at')
    ->selectRaw('a.id, p.mrn, p.name, ad.icd10_code')
    ->limit(50)->get();

// Q5: patients with >1 simultaneously-open episode (canary for data integrity)
$doubleOpen = DB::table('admissions as a')
    ->join('patients as p', 'p.id', '=', 'a.patient_id')
    ->join('admissions as b', fn ($j) => $j
        ->on('b.patient_id', '=', 'a.patient_id')
        ->whereColumn('b.id', '>', 'a.id')
        ->whereNull('b.discharge_date')
        ->whereNull('b.deleted_at'))
    ->whereNull('a.discharge_date')->whereNull('a.deleted_at')
    ->selectRaw('p.mrn, p.name, COUNT(DISTINCT a.id) open_episodes')
    ->groupBy('p.id', 'p.mrn', 'p.name')
    ->limit(50)->get();
```

Result is passed as five Inertia props: `overLos`, `noDx`, `badDates`, `orphanDx`, `doubleOpen`. Each appears as a collapsible card in `DataQuality.vue` with a row count badge. Each row has a link to `/patients?highlight={admission_id}` or to the Registry entry.

`DataQualityNotify` command (`php artisan dq:notify`): runs the same five queries, sums totals, creates `Notification` rows for all active admins if any total > 0 (one notification per run, not per row), action `'dq.daily_report'` with payload `{over_los: N, no_dx: N, bad_dates: N, orphan_dx: N, double_open: N}`. Scheduled in `console.php` via `Schedule::command('dq:notify')->dailyAt('07:00')`.

Migration adds `dq_los_multiplier TINYINT UNSIGNED DEFAULT 2` to `settings`. `ControlController::updateSettings` gains a new validation rule `'dq_los_multiplier' => ['required', 'integer', 'min:1', 'max:10']`.

**Decisions & defaults**
- LOS multiplier default: 2 (flags episodes beyond `long_los × 2 = 22 days` by default).
- Row limit per section: 50 (a data-quality dashboard is not a registry; if > 50 results exist, the issue is severe enough to warrant a dedicated export).
- Notification command: no auto-fix, read-only report.
- Future-date admits: flagged (may be legitimate for pre-planned elective admissions — clinician sign-off required on whether to allow them, but the data-quality page will surface them for review).

**Test plan** (`laravel/tests/Feature/DataQualityTest.php`)

- `test_over_los_returns_qualifying_admissions` — create a non-longterm active admission with `admit_date = now()->subDays(long_los * 2 + 1)`; assert `overLos` prop contains it.
- `test_over_los_excludes_longterm` — same but `is_longterm = 1`; assert absent from `overLos`.
- `test_no_dx_returns_admission_without_diagnoses` — create active admission with no `admission_diagnoses`; assert in `noDx`.
- `test_bad_dates_flags_discharge_before_admit` — create closed admission with `discharge_date < admit_date`; assert in `badDates`.
- `test_bad_dates_flags_future_admit` — create active admission with `admit_date = now()->addDay()`; assert in `badDates`.
- `test_double_open_detects_two_open_episodes` — create two open admissions for the same patient; assert in `doubleOpen`.
- `test_dq_notify_command_creates_notification_for_admins` — seed one qualifying row; run the command; assert `Notification` row created for the admin user with `type = 'dq.daily_report'`.

**Build sequence**
1. Write `DataQualityTest` (failing).
2. Migration for `dq_los_multiplier` in `settings`.
3. Implement `DataQualityController::index()`.
4. Add route. Run tests → green.
5. Implement `DataQualityNotify` command + schedule registration.
6. Run command test → green.
7. Build `DataQuality.vue`. Add link to AppLayout admin nav. Commit.

---

### 7. Referential CHECK constraints + import validation hardening (effort S, risk LOW)

**Goal / user value**
MariaDB CHECK constraints prevent the database from ever storing structurally impossible data (discharge before admission, age out of range), regardless of the code path that writes the row. The import pipeline gains the same date-order and outcome-destination consistency rules that live admission writes already enforce.

**Files**

- CREATE `laravel/database/migrations/2026_06_14_000004_add_check_constraints.php`
- MODIFY `laravel/app/Http/Controllers/ImportController.php` (`parse()`)
- CREATE `laravel/tests/Feature/CheckConstraintTest.php`
- MODIFY `laravel/tests/Feature/LegacyImportTest.php` (add import-validation assertions)

**Design**

Migration: self-healing pattern (same approach as `2026_06_09_000002_unique_admission_diagnoses.php`). Before adding the CHECK constraints, produce a report of existing violations and write them to the audit log as informational rows, then self-heal by NULLing out the violating value (not deleting the row):

```php
// Step 1: report existing violations to audit_log
$violators = DB::select("
    SELECT id, admit_date, discharge_date, medical_discharge_date
    FROM admissions
    WHERE (discharge_date IS NOT NULL AND admit_date IS NOT NULL AND discharge_date < admit_date)
       OR (medical_discharge_date IS NOT NULL AND admit_date IS NOT NULL AND medical_discharge_date < admit_date)
       OR (discharge_date IS NOT NULL AND medical_discharge_date IS NOT NULL AND discharge_date < medical_discharge_date)
");
if ($violators) {
    DB::table('audit_log')->insert(['actor_id' => null, 'actor_name' => 'migration',
        'action' => 'migration.check_constraint_violation_report',
        'entity_type' => 'admission', 'entity_id' => null,
        'details' => json_encode(['count' => count($violators), 'ids' => array_column($violators, 'id')]),
        'ip' => '127.0.0.1', 'created_at' => now()]);
}
// Step 2: self-heal — NULL out the impossible date (keep the row, keep the audit trail)
DB::statement("UPDATE admissions SET discharge_date = NULL WHERE discharge_date IS NOT NULL AND admit_date IS NOT NULL AND discharge_date < admit_date");
DB::statement("UPDATE admissions SET medical_discharge_date = NULL WHERE medical_discharge_date IS NOT NULL AND admit_date IS NOT NULL AND medical_discharge_date < admit_date");

// Step 3: CHECK constraints (MariaDB 10.2+ / MySQL 8.0.16+)
DB::statement("ALTER TABLE admissions ADD CONSTRAINT chk_discharge_gte_admit
    CHECK (discharge_date IS NULL OR admit_date IS NULL OR discharge_date >= admit_date)");
DB::statement("ALTER TABLE admissions ADD CONSTRAINT chk_medical_discharge_gte_admit
    CHECK (medical_discharge_date IS NULL OR admit_date IS NULL OR medical_discharge_date >= admit_date)");
DB::statement("ALTER TABLE admissions ADD CONSTRAINT chk_discharge_gte_medical
    CHECK (discharge_date IS NULL OR medical_discharge_date IS NULL OR discharge_date >= medical_discharge_date)");

// patients table: age 0-150 guard (patients.age is unsignedSmallInt so already >= 0; cap at 150)
DB::statement("ALTER TABLE patients ADD CONSTRAINT chk_age_range CHECK (age IS NULL OR (age >= 0 AND age <= 150))");
```

The `down()` method drops the named constraints with `ALTER TABLE ... DROP CONSTRAINT`.

`ImportController::parse()` gains three additional rejection rules mirroring the CHECK constraints and the live action invariants:

1. Date ordering: `discharge_date < admit_date` → `$row['ok'] = false`, error `"Discharge date before admit date"`. Same for `medical_discharge_date < admit_date` and `discharge_date < medical_discharge_date`.
2. Outcome-destination consistency (mirrors `PatientActionController`): if `outcome === 'Dead'` and a `discharged_to` is provided and `discharged_to !== 'Mortuary'` → `$row['warning'] = "Dead outcome forces Mortuary destination; submitted DischargedTo overridden"` (warning, not rejection — the commit will override it). If `outcome === 'Dead'` and no `discharged_to` is provided, the commit path already forces `Mortuary`.
3. Delay-reason presence: if `discharge_date IS NULL` and `medical_discharge_date IS NOT NULL` (phase-1 state), `delay_reason` must be one of `Physical`/`System` (already in the schema). Add a warning if missing: `"Medically discharged without delay reason — imported with NULL delay_reason"`.

**Decisions & defaults**
- CHECK constraint violations in the existing data: NULL the violating date (conservative), NOT delete the row. The migration audit row provides a record.
- Import date-order violations: REJECT (hard error `ok = false`) — unlike legacy dirty data in the DB, newly imported rows have no excuse for impossible date ordering.
- Dead-to-non-Mortuary destination: WARNING + auto-correct at commit (same rule as live discharges).

**Test plan** (`laravel/tests/Feature/CheckConstraintTest.php`)

- `test_check_constraint_blocks_discharge_before_admit` — attempt to insert directly via `DB::table('admissions')->insert(...)` with `discharge_date < admit_date`; expect `QueryException`.
- `test_check_constraint_blocks_impossible_age` — `DB::table('patients')->insert(['age' => 151, ...])` → `QueryException`.
- `test_check_constraint_allows_null_discharge` — insert with `discharge_date = null` → no exception.
- `test_import_rejects_date_inversion` — parse a row with `DischargeDate` before `AdmitDate`; assert `ok = false`, error contains "before admit".
- `test_import_warns_dead_to_non_mortuary` — parse a row with `Outcome = Dead`, `DischargedTo = Home`; assert `ok = true`, `warning` contains "Mortuary".
- `test_import_warns_medical_discharge_without_delay_reason` — parse a row with a `ClinicalDischargeDate` but no `DischargeDate` and no `DelayReason`; assert `ok = true`, `warning` set.

**Build sequence**
1. Write `CheckConstraintTest` + add import-validation assertions to `LegacyImportTest` (failing).
2. Migration `add_check_constraints` (self-healing + ALTER TABLE).
3. Add date-order rejection rules to `ImportController::parse()`.
4. Add Dead-to-Mortuary warning + delay-reason warning.
5. Run full suite → green. Commit.

---

### 8. Import validator tests — expanded coverage (effort S, risk LOW)

**Goal / user value**
The import pipeline has several conditional branches (header detection, within-batch MRN dedup, TransferType validation, location-derived type, consultant matching, diagnosis dedup, commit-vs-preview count parity) that have no dedicated test coverage. A flaw in any of them silently corrupts historical data.

**Files**

- MODIFY `laravel/tests/Feature/LegacyImportTest.php` (extend with new test methods)
- No controller or model changes needed (tests drive against the existing `ImportController::parse()` indirectly via the preview endpoint or via direct method call via reflection).

**Design**

The existing `LegacyImportTest` already covers basic import flows. Add the following test methods. Where testing `parse()` directly, use reflection to call the private method, or alternatively route through `POST /import/preview` as an admin — the preview endpoint calls `parse()` and returns the `preview.sample` prop.

Helper: extract the `preview.sample` from the Inertia response body.

**Test plan** (all new methods in `LegacyImportTest`)

- `test_header_row_is_skipped_when_first_column_is_mrn` — CSV with a header row `MRN,Name,...`; assert that the header is not counted in `valid` + `invalid`.
- `test_header_row_without_mrn_label_is_not_skipped` — first row has a numeric MRN; assert it IS counted (not silently dropped as a header).
- `test_within_batch_duplicate_active_mrn_rejected` — two rows for the same MRN, both with no discharge date (active); assert the second is `ok = false` with `"already has an active"` error.
- `test_within_batch_duplicate_active_mrn_second_discharged_allowed` — two rows for the same MRN: first active, second discharged; assert both `ok = true`.
- `test_transfertype_without_discharge_date_rejected` — row with a `TransferType` column value but no `DischargeDate`; assert `ok = false`.
- `test_transfertype_with_discharge_date_overrides_location_derived` — row with `Location = Ward`, `DischargeDate` set, `TransferType = 'discharge from ICU'`; commit → assert `admissions.transfer_type = 'discharge from ICU'` (not `'discharge from ward'`).
- `test_invalid_transfer_type_literal_rejected` — `TransferType = 'random string'`; assert `ok = false`, error contains "TransferType must be one of".
- `test_consultant_name_not_matched_is_warning_not_rejection` — column 11 has an unrecognized consultant name; assert `ok = true`, `warning` contains "not matched".
- `test_diagnosis_deduplicated_on_import` — a row with `Diagnoses = A00|A00|B00`; commit → `admission_diagnoses` has 2 rows (A00 once, B00 once).
- `test_committed_rows_match_preview_valid_count` — preview returns `valid = N`; commit the same payload; assert `import.bulk` audit row `details.imported = N`.
- `test_preview_does_not_write_to_db` — POST `/import/preview`; assert `admissions` count is unchanged.

**Build sequence**
1. Add all new test methods to `LegacyImportTest` (failing).
2. Verify `parse()` already handles each case; fix any gaps (expected: the header-skip and diagnosis-dedup paths are already implemented — verify and add any missing guards).
3. Run → green. Commit.

---

### 9. Patient-merge / MRN-dedup tooling (effort L, HIGH risk)

**Goal / user value**
The legacy schema created genuine duplicate patient rows (same person, multiple MRNs due to typos and the `mediumtext` MRN column). The merge tool lets an admin re-point all admissions and consultations from a source patient to a canonical target patient in a single audited transaction, then soft-delete the source — preserving the full clinical history while eliminating the board showing the same patient twice.

**Files**

- CREATE `laravel/app/Http/Controllers/PatientMergeController.php`
- CREATE `laravel/app/Http/Requests/PatientMergeRequest.php`
- MODIFY `laravel/routes/web.php` (admin group: merge routes)
- CREATE `laravel/resources/js/Pages/Admin/PatientMerge.vue`
- CREATE `laravel/database/migrations/2026_06_14_000005_add_soft_deletes_to_patients.php`
- MODIFY `laravel/app/Models/Patient.php` (`use SoftDeletes`)
- CREATE `laravel/tests/Feature/PatientMergeTest.php`

**Design**

Migration adds `deleted_at` to `patients`. Admissions already have `cascadeOnDelete` on the `patient_id` FK — with soft delete active, the FK is never triggered by a soft-delete, so historical admissions remain intact.

`PatientMergeController`:

```
GET  /admin/patient-merge           -> index()   — search + possible-duplicates list
POST /admin/patient-merge/search    -> search()  — return source + target previews
POST /admin/patient-merge           -> merge()   — perform the merge
```

`index()` renders `Admin/PatientMerge.vue` with a `possibleDuplicates` prop: pairs of `patients` rows that share a normalized MRN (strip leading zeros, strip non-digits) OR have a `name` similarity score above a threshold AND a `NOMRN`-like placeholder MRN pattern (MRNs that are all-zeros, single-character, or match the pattern `/^0+$/`). The duplicate-finder query:

```php
// Normalized-MRN duplicates: two patients whose MRNs differ only by leading zeros
$normalized = DB::table('patients as p1')
    ->join('patients as p2', fn ($j) => $j
        ->on(DB::raw('CAST(p1.mrn AS UNSIGNED)'), '=', DB::raw('CAST(p2.mrn AS UNSIGNED)'))
        ->whereColumn('p1.id', '<', 'p2.id'))
    ->whereNull('p1.deleted_at')->whereNull('p2.deleted_at')
    ->selectRaw('p1.id id1, p1.mrn mrn1, p1.name name1, p2.id id2, p2.mrn mrn2, p2.name name2')
    ->limit(50)->get();
```

`search()` takes `source_id` and `target_id`, validates both exist (not soft-deleted), and returns a preview:
- Source admissions count (open + closed).
- Source consultations count.
- Target admissions count (open + closed).
- Whether either patient has an active open episode.

`PatientMergeRequest::authorize()` = admin only; `rules()` = `source_id: required|exists:patients,id`, `target_id: required|exists:patients,id`, `different:source_id`, `canonical_demographics: array` (optional field-by-field overrides: `name`, `gender`, `age`, `nationality` — any not submitted defaults to the TARGET's existing value; this lets the admin choose the best name spelling).

`merge()` method inside a `DB::transaction`:

```php
DB::transaction(function () use ($source, $target, $data) {
    // 1. Re-point all admissions + consultations from source to target
    DB::table('admissions')->where('patient_id', $source->id)->update(['patient_id' => $target->id]);
    DB::table('consultations')->where('patient_id', $source->id)->update(['patient_id' => $target->id]);

    // 2. Apply canonical demographic choices (only user-submitted overrides)
    $targetUpdates = array_filter($data['canonical_demographics'] ?? []);
    if ($targetUpdates) { $target->update($targetUpdates); }

    // 3. Audit before the source disappears
    AuditLog::create([
        'actor_id'    => Auth::id(), 'actor_name' => Auth::user()->name,
        'action'      => 'patient.merge',
        'entity_type' => 'patient', 'entity_id' => (string) $target->id,
        'details'     => [
            'source_id'  => $source->id, 'source_mrn' => $source->mrn,
            'target_mrn' => $target->mrn,
            'admissions_repointed' => DB::table('admissions')->where('patient_id', $target->id)->count(),
        ],
        'ip' => request()->ip(),
    ]);

    // 4. Soft-delete the source patient row
    $source->delete();  // SoftDeletes — sets deleted_at, does NOT cascade
});
```

Guard against merging a source that has an active (open, non-deleted) admission AND the target also has an active admission — that would produce a double-open canary hit. Reject with a validation message.

`PatientMerge.vue` is a two-panel interface:
- Left panel: a search box for source patient (MRN or name typeahead via the existing `/api/icd10`-pattern endpoint, but for patients).
- Right panel: target patient search.
- Middle: "Preview merge" button → shows a confirmation card (admissions to re-point, which demographics are kept).
- "Confirm merge" button with a `confirm()` dialog.

Add a GET `/api/patients/search?q=...` endpoint (admin-only, returns `[{id, mrn, name, open_admissions_count}]` for the typeahead) directly in `PatientMergeController::searchPatients()`.

**Decisions & defaults**
- Canonical demographics: if no override is submitted, TARGET's values are kept (not source's). The admin explicitly selects which name/gender/age/nationality is canonical.
- No auto-merge. Every merge is a manual admin action with step-up (add `->middleware('stepup')` to the merge POST route; this is a high-risk write).
- The merge tool does NOT deduplicate `admission_diagnoses` (if a source admission carried `A00` and the target also carried `A00`, after the repoint there will be duplicate diagnosis rows for that admission-code pair — this is prevented by the existing `adx_admission_code_unique` index introduced in `2026_06_09_000002_unique_admission_diagnoses.php`). The merge must re-point admissions one-by-one and catch/ignore `QueryException` on duplicate-diagnosis conflicts, or alternatively call `diagnoses()->delete()` + re-insert after de-duplication. Safe default: re-point admission, then run `DELETE ad1 FROM admission_diagnoses ad1 JOIN ... WHERE ad1.id > ad2.id` scoped to the re-pointed admissions.

**Test plan** (`laravel/tests/Feature/PatientMergeTest.php`)

- `test_merge_repoints_admissions_to_target` — create source + target patients each with admissions; POST merge; assert all admissions have `patient_id = target->id`.
- `test_merge_repoints_consultations_to_target` — same for `consultations`.
- `test_merge_soft_deletes_source_patient` — after merge, assert `patients.deleted_at IS NOT NULL` for the source.
- `test_merge_writes_patient_merge_audit_row` — assert `audit_log` has `action = 'patient.merge'` with the correct source/target IDs.
- `test_merge_blocked_when_both_have_active_admissions` — source and target each have an open admission; POST merge → assert 422/redirect with error.
- `test_merge_blocked_for_non_admin` — POST merge as consultant → 403.
- `test_possible_duplicates_detects_leading_zero_mrn` — create patients with MRNs `00123` and `123`; GET `/admin/patient-merge`; assert `possibleDuplicates` contains the pair.
- `test_diagnosis_dedup_after_merge` — source admission has `A00`, target admission ALSO has `A00`; merge → assert exactly one `admission_diagnoses` row for that admission+code.

**Build sequence**
1. Write `PatientMergeTest` (failing).
2. Migration `add_soft_deletes_to_patients`.
3. Add `use SoftDeletes` to `Patient` model.
4. Implement `PatientMergeController` (all three actions).
5. Implement `PatientMergeRequest`.
6. Add routes (including `stepup` middleware on POST merge).
7. Run tests → green.
8. Build `PatientMerge.vue`.
9. Add to admin nav. Commit.

---

### Out-of-scope note

PDPL data-subject export/erasure tooling and PHI-column encryption-at-rest are explicitly deferred to Phase 5. Phase 5 will need to decide: (a) whether the Saudi PDPL requires right-to-erasure in a clinical records context (likely excluded under the "public health" carve-out, but requires legal sign-off), and (b) whether at-rest encryption should be at the disk/tablespace level (transparent, no app changes) or column-level (requires schema redesign for every `name`/`mrn`/`nationality` column and defeats all direct SQL search). Neither decision can be made without legal and clinical governance input.

---

### Risks & sequencing notes

- Item 1 (soft-deletes) must be done before items 3, 6, 7, and 9 to avoid `DB::table` analytics queries counting soft-deleted rows. The `whereNull('deleted_at')` additions are mechanical but numerous; the failing analytics test in step 6 of the build sequence is the enforcer.
- Item 7 (CHECK constraints) carries the highest migration risk: if the existing `dmc_test` or production DB has a significant number of date-inversion rows, the self-healing `UPDATE`s in the migration could silently NULL out dates that someone relied on. The violation report written to `audit_log` must be reviewed before deploying to production. Run `SELECT COUNT(*) FROM admissions WHERE discharge_date < admit_date` on the production DB before running the migration.
- Item 4 (step-up) introduces a client-visible redirect for three previously direct-POST routes. Any existing Cypress/E2E tests that hit those routes directly will need to add a step-up session stamp. PHPUnit `actingAs` tests can stamp the session directly via `$this->withSession(['stepup.verified_at' => now()->getTimestamp()])`.
- Item 9 (patient merge) depends on item 1 (soft-deletes on patients) and on the step-up middleware from item 4. Sequence: 1 → 4 → 9.
- Items 5, 6, 8 are self-contained and can be parallelized with 2 and 3 after item 1 is complete.
- The `public/build` assets must be rebuilt and committed after any `AppLayout.vue` or new Vue page is added (items 2, 3, 4, 6, 9 all touch the frontend). A single build pass at the end of each item is sufficient; do not rebuild mid-item.
- The `Setting::current()` memoization uses `once()` (request-scoped). Adding new columns (`idle_timeout_minutes`, `dq_los_multiplier`, etc.) to `settings` requires running the migration before deploying code that reads those columns — standard migration-first deploy order.
