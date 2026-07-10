# EHC UI/UX Renovation — Waves 1–5 Verification Report

**Branch:** `laravel-replatform` · **Spec:** `docs/superpowers/specs/2026-07-08-ehc-ui-ux-delta-design.md`
**Prior waves:** W0 (brand + style-guide) and W4 (dashboard + charts) shipped earlier
(`ee9964b`). This report covers **W1, W2, W3, W5** + the W4 tail.

## Commits

| Commit | Wave | Summary |
|---|---|---|
| `347a19e` | W4-tail | "Discharges Today" KPI card (the rendered good-polarity delta chip) |
| `293e054` | W1 | Command palette + IdentityChip + **MRN out of the URL** (SPC-TM-011) |
| `28753e0` | W2+W3 | Nav shell (collapse + IA) + server-sortable tables; edit-safety, Tabs, error-summary |
| `d4758bc` | W5 | a11y sweep + session-timeout UX + i18n-readiness (RTL/dates) |

## Gates (final state)

- **Vitest:** 462 passed (50 files)
- **PHPUnit:** 526 (`--exclude-group pdf`) + 56 (`--group pdf`)
- **Contrast** (`scripts/contrast.mjs`): PASS
- **Allowlist** (`scripts/check-source-allowlist.mjs`): PASS — 681 classes
- **Build:** reproducible; `public/build` committed
- **axe-core** (injected live, not committed) against Dashboard / board / Registry:
  **0 critical** on all three.

## What each wave delivered

### W1 — Findability + security P0
- `QuickJump.vue` → command palette: Ctrl/Cmd+K everywhere (keeps `/`), `role=combobox`
  + single `role=listbox` with `aria-activedescendant` (focus stays in the input),
  grouped Patients/Actions/Nav/Recents, loading/empty/error+Retry, `aria-live` count.
- `IdentityChip.vue` — the identity tuple that travels row → modal header (palette rows,
  ActionModal, ReassignModal via a backward-compatible `BaseModal #subtitle` slot).
- Recents: in-memory module store of ≤7 **opaque admission ids**, re-hydrated server-side
  through the same auth-scoped endpoint, **cleared on logout** (button + idle auto-logout).
- **SPC-TM-011:** patient name/MRN search terms moved out of the query string into POST
  bodies (QuickJump, Registry, board, Consultations, Dashboard recent rows, merge
  typeahead); non-PII filters stay URL-reflected; legacy GET-with-term 302-redirects
  dropping the term; `autocomplete=off` on every MRN/name input. New `TermOutOfUrlTest`.
- **Live-verified:** palette opens, combobox/listbox correct, MRN term absent from URL.

### W2 — Nav shell + tables
- Desktop **collapse-to-icons** (persisted `dmc-sidebar-collapsed`): `lg:w-64`↔`lg:w-16`,
  a real toggle with `aria-expanded`/`aria-controls`, reactive `lg:pl-*`, collapsed links
  keep an accessible name (`sr-only`) + `title`; mobile drawer untouched (lg-scoped).
- Nav IA regrouped **Overview / Clinical / Operations / Administration** — same can-flag +
  Observer filtering as before.
- `SortableTh.vue` + `useServerTable.js`: `aria-sort` on a real `<button>`, cycle
  none→desc→asc→none; **server-authoritative** — `RegistryController` `orderBy` on a
  per-mode **whitelist** (off-whitelist / injection-shaped input ignored; `dir` ∈ asc|desc).
  Registry gets sticky headers, status-rail rows, teal hover, a density toggle; the W1
  term stays in the POST body through sort + pagination.
- **Live-verified:** header `aria-sort` updates, the server genuinely reorders (asc
  surfaces oldest `admit_date`), term never enters the URL; collapse shrinks + persists +
  keeps a11y names; drawer focus-trap lands on the Dashboard link and wraps to Control.

### W3 — Edit safety, accessible Tabs, security P1 UX
- `useUnsavedGuard.js` + an optional `BaseModal dirty` prop (default false = unchanged);
  `usePatientEdit.isDirty` snapshot; the three Modify hosts (Patients/Admissions/Registry)
  route `@close` **and** the footer Cancel through one guarded closer — **no double-prompt**,
  save closes without a prompt.
- `ErrorSummary.vue` — NHS-style `role=alert` box, one focus-jump link per bad field
  (every mapped error id resolves to a real input); wired into PatientForm + the modals.
- Double-submit guard (`guardSubmit` + `:disabled` on every mutate button).
- Control page retrofitted onto the accessible `Tabs.vue` (ARIA tablist, roving tabindex,
  arrow/Home/End); `v-show` preserves panel state; Settings tab shows a dirty badge.
- Destructive copy states exact effect + name·MRN; the step-up re-auth is untouched.

### W5 — Accessibility, i18n-readiness, session-security UX
- **a11y:** board heading skip fixed (h3→h2); click-only rows → keyboard-operable
  (`role=button`+tabindex+Enter/Space); ≥40px targets; **Registry filter selects/inputs
  given accessible names** (found by live axe — 7 `select-name` + 2 `label` criticals);
  disabled-pagination contrast bump.
- **Session-security:** `useSessionTimeout.js` (min(idle, absolute), reads the existing
  shared lifetime props), a `role=timer aria-live` countdown toast, "Stay signed in"
  (re-stamps `last_activity` via the auth group) + "Lock now"/"Sign out (lock this
  workstation)".
- **i18n:** `formatDate` (DD MMM YYYY, null/ISO-safe); physical→logical properties on the
  core shells (fixed drawer left physical); `lang=ar dir=rtl` on the Arabic wordmark.
- **CSP-readiness:** no inline handlers; the theme bootstrap is the sole inline script.

## Adversarial verification

Two `Workflow` passes, 8 skeptics total, each reading the diff and trying to refute a
specific correctness claim:

- **W2/W3 pass:** 3 HOLDS + **2 real defects** caught → both fixed + live-verified:
  (a) the new `hidden lg:flex` collapse toggle polluted the mobile drawer's focus-trap
  (`focusableIn` had no visibility filter) — fixed with a `getClientRects()` filter;
  (b) a dangling `ErrorSummary` focus-jump link on the consultation `other_indication`
  field (missing id) — fixed.
- **W5 pass:** **3/3 HOLD** (session-timeout, RTL-layout, formatDate/a11y). Two
  non-blocking observations (formatDate accepts calendar-impossible days for display;
  a mild WCAG 2.5.3 Label-in-Name divergence on the date inputs, still a net improvement).

## Known / deferred

- **Systemic primary-button contrast (PENDED — needs a brand decision).**
  `bg-brand-600` + white — the app-wide primary-button fill (Save / Assign / Search /
  pagination-active) — measures **4.31:1 light / 2.09:1 dark**, below the 4.5:1 AA bar for
  its 14px text. axe flags it `color-contrast` **serious** (not critical) on the board (1)
  and Registry (2). It **pre-dates** this renovation and is not a simple step-bump: the
  brand teal steps *invert* light↔dark (`brand-700` is #00727b in light but #7accc9 in
  dark), so a fix needs a **theme-stable solid token** (e.g. a pinned dark teal / the
  `navy-700` the avatar now uses) applied to every CTA — a deliberate brand-appearance
  decision. Left for owner sign-off rather than changed unilaterally.
- **Label-in-Name (minor):** Registry date inputs read "From date"/"To date" while the
  visible label says "Admitted from"/"From"; associating the visible label (mode-scoped
  ids) would tighten WCAG 2.5.3. Non-blocking.
