# EHC UI/UX Renovation — Delta Design Spec

**Date:** 2026-07-08 · **Status:** Design approved (scope + enrichment) — pending user spec review
**App:** DMC "Internal Medicine" patient-flow board — Laravel 13 + Inertia 2 + Vue 3 + Tailwind v4 + ApexCharts (`laravel/`)
**Source plan:** the maintainer's *UI/UX Renovation Plan v1.0*, reconciled against the real codebase (gap analysis) and enriched from the plan's own reference galleries.
**Majors (per owner):** **design renovation** and **security** — everything below is weighted to those two.

> This is a **design spec**, not an implementation plan. It defines *what* we build and *why*. The task-by-task
> *how* comes next via the writing-plans skill. It is a **behavior-preserving delta** on an already-mature app:
> additive, incremental, reversible, one branch/PR per wave, small `ui(scope): …` commits.

---

## 1. Context & reframe

The renovation plan was written generically (React libraries, an EHR data model, `src/assets`). Verified against
the code, **~70–85 % of it already exists** from prior UI/UX waves: the Tailwind-v4 token system, EHC teal
identity, self-hosted fonts, dark mode, reduced-motion, accessible modals (`BaseModal`+`useModalA11y`), a `/`-triggered
`QuickJump` search, `PatientForm`/`DxChips`/`PatientFlags`, a grouped-IA nav shell with mobile drawer, and a KPI/chart
dashboard. So this is a **targeted delta + rebrand-completion + a security spine**, not a rebuild.

**Locked decisions (owner):**
- **Adapt to real data.** Keep the plan's UX patterns; map every "module" onto data that exists
  (admissions · ICD-10 diagnoses · consultations · discharge/transfer · handovers). **Drop** vitals/labs/meds/
  documents/allergies/phone/national-ID/DOB — no backend/data-model/API changes.
- **Targeted delta + rebrand polish.** Close genuine gaps; reuse everything built; **no new patient profile page**
  (the "profile" lives on the existing modal/summary surfaces).
- **Opportunistic RTL readiness.** On files touched anyway, use CSS logical properties + set `lang=ar`/`dir` on
  Arabic wordmark/name display. No full Arabic mode, no Arabic font, no `dir=rtl` toggle.
- **Logo:** attempt to fetch the official EHC mark + sampled colors from ehc.med.sa during Wave 0; keep the existing
  hand-drawn recreation as the automatic fallback; record provenance in `BRAND_README.md`.

**Tech reconciliation (plan → reality):** shadcn/TanStack/Tremor/Recharts are React — we reuse the vendored
Vue/ApexCharts stack and add only small **copy-in** Vue components. **Zero new runtime dependencies.** Paths are
`laravel/resources/js|css` and `laravel/public/images` (not `src/assets`). The security doc is `SECURITY-AUDIT.md`
(repo root).

---

## 2. Design signature — "The Census Board" (W0 anchor)

An authentic, non-templated art direction built *within* the fixed EHC identity (teal `#009ca6` primary, gold accent,
Hanken Grotesk display + Instrument Sans body, class-based dark mode). It is opinionated but never at the expense of
legibility or WCAG 2.2 AA for a time-pressed clinical user on a shared monitor.

- **Elevation & borders.** Reject drop-shadow bento. Two elevation tiers — `surface` (board) and `surface-raised`
  (KPI tiles, modals) — separated by a **single tonal step + a 1px teal-tinted hairline**. Soft shadow is reserved
  for **true overlays only** (command palette, dialogs).
- **The fingerprint.** Every patient row/card carries a **3px left status-rail** in its status color, so the board
  reads as a stack of tickets. This rail is the app's signature and recurs on board, queue, registry, and KPI strip.
- **Density on a 4px grid.** Zebra-free **hairline** row dividers + a 6 %-teal hover tint; row rhythm 8px pad / 12px
  gutters; section spacing in 16/24 steps. A **compact/comfortable density toggle**, persisted per user (shared-
  workstation ergonomics).
- **Sacred palette / accent discipline.** **Teal = interaction** (focus, active nav, links, primary buttons).
  **Gold = wayfinding only** ("you are here", pinned/current drill-in) — never a fill, never celebratory.
  **Status red/amber/green = load-bearing signal, never decoration** — forbidden on nav, on charts-by-default, and on
  empty states. Status is always **color + icon + text**.
- **KPI numerals & board identity.** Hanken Grotesk, tabular-nums, tight tracking, left-aligned to a baseline grid so
  columns of counts scan vertically like a departures board; a thin hairline **under** each numeral echoes the row rail.
- **Motion — reduced-motion-first.** Exactly **one** meaningful 120ms transition (row state-change / palette open).
  No skeleton shimmer, no count-up, no chart draw-in. `prefers-reduced-motion` → 0ms; state change cues via a brief
  static hairline flash, not movement.
- **Light/dark parity.** Design **dark-first** (night shift), derive light by inverting tonal steps while **holding
  status hues constant**, and re-check contrast in *both* themes (status ≥3:1 non-text, ≥4.5:1 as text).

*Gold standard:* the NHS Digital Service Manual governs content tone, error/callout patterns, focus, and status
semantics (patterns adapted, never copied — NHS content is Crown copyright / OGL).

---

## 3. The six waves (one branch/PR each)

### Wave 0 — Brand foundation, tokens finish & `/style-guide`
- Attempt fetch of official EHC logo + sampled colors → `laravel/public/images/` + `BRAND_README.md` (source URL,
  date, every sampled hex, contrast results); wire into `EhcLogo.vue`; keep recreation as fallback; add a white/mono
  variant for dark headers.
- Encode the §2 signature into `resources/css/app.css`: hairline border language, a **status-rail** utility, the two
  elevation tiers, a **3-tier flow-alert callout** token set (info-teal / warning-amber / critical-red, each with a
  visually-hidden SR prefix + icon + text label; cap 2/view), the focus token, and density-toggle scaffolding.
- Build an internal, **auth-gated `/style-guide`** Inertia page rendering: tokens, button hierarchy, status chips,
  flow-alert callouts, form field + error-summary, a KPI card, a table row with status-rail, a command-palette row,
  tabs — in **both themes**, with contrast notes.
- Keep the `navy-*` scale as EHC dark-teal (intentional); keep `APP_NAME` unless owner requests EHC wording (note:
  the session-cookie name derives from `APP_NAME`).
- **Acceptance:** single token source of truth; `/style-guide` renders every primitive in light + dark; contrast
  recorded in `BRAND_README.md`; all existing pages still load with no visual regression.

### Wave 1 — Findability + security P0
- Upgrade `QuickJump` → **command palette**: add **Ctrl/Cmd+K** (keep `/`); reachable at all breakpoints; a
  `role=listbox` + `aria-activedescendant` model (focus stays in the input); grouped results (Patients · Actions ·
  Nav · Recents); first-class **loading / empty / error** slots; `aria-live` result count.
- **Identity-ribbon result rows:** `name` (bold) · **MRN** (tabular-nums, never truncated) · age·sex · ward/location ·
  status chip, with a dim right-aligned last-admission date so re-admission episodes are distinct; deceased/discharged
  rows visibly marked so an old episode isn't actioned as active.
- **Recent patients:** an **in-memory** store of **opaque admission ids only**, re-fetched server-side, **cleared on
  logout** — never localStorage/sessionStorage (shared-workstation leak).
- **Security P0 — MRN out of URL (SPC-TM-011):** move the free-text search term out of the GET query string for both
  `QuickJump` and Registry search (→ POST body / opaque ids). Non-PII filters (mode/ward/status/date) stay
  URL-reflected for shareable views. `autocomplete="off"` on MRN/name inputs.
- Introduce the **IdentityChip** primitive (teal-bordered tuple) that travels unchanged from a result row into any
  action/edit modal header.
- **Acceptance:** palette reachable from every page; keyboard-only find→open works; **no MRN/name appears in URL,
  history, or logs** (verified); recents cleared on logout; result rows carry the full identity tuple.

### Wave 2 — Nav shell + tables
- **Nav IA** regrouped **Overview / Clinical / Operations / Administration** using only sections that exist; driven by
  server-provided `can` flags (Observer read-only) — never hidden-link-only (endpoint still authorizes). Add
  **desktop collapse-to-icons** (persisted); mobile drawer already exists.
- **Tables:** copy-in `SortableTh.vue` (a real `<button>` header exposing `aria-sort`) + `useServerTable.js`
  (server-authoritative `manualSorting/Pagination/Filtering`; sort cycle none→desc→asc). Add **sticky headers**,
  visible **row focus**, **status-rail** rows, hairline dividers + teal hover, and the **density toggle**. Apply to
  Registry first, then where valuable.
- **Acceptance:** nav grouped, role-scoped, and desktop-collapsible; tables sticky + server-sortable + keyboard-
  operable; `aria-sort` announced.

### Wave 3 — Edit safety, accessible Tabs + security P1
- **Unsaved-changes guard:** an `isDirty` check in `usePatientEdit`/`BaseModal` → "Discard changes?" via the existing
  `ConfirmDialog` on Esc/backdrop/Cancel; dirty tabs badged (dot + `aria-label`).
- **`Tabs.vue` + `useTabs.js`** (ARIA `tablist/tab/tabpanel`, roving tabindex, ←/→/Home/End) — retrofit the Control
  page's non-accessible switcher; available for grouped edit sections.
- **NHS error-summary** pattern: on modal save-failure render a summary box at the modal top listing each bad field as
  a focus-jump link, plus a constructive field-level message ("Enter an MRN using digits only") — never red-border-
  alone. **Double-submit guard** on every POST (prevents duplicate admissions/discharges).
- **Security P1 — destructive/step-up UX:** preserve the step-up re-auth on delete/reverse/merge; confirmation copy
  states the **exact effect + blast radius** with the patient's name·MRN; typed-confirm for merges.
- **Acceptance:** unsaved edits prompt before loss; Tabs fully keyboard-operable; error-summary moves focus to the
  offending field; no double-submit; step-up prompts intact.

### Wave 4 — Dashboard polish + analytics signature + security P2
- **KPI triad cards:** label (uppercase, muted) → big Hanken tabular-nums numeral → **delta chip + inline sparkline**
  row. **Polarity-aware deltas** — each KPI declares `polarity` (good/bad/neutral) so "readmissions +3" reads red and
  "discharges +5" reads green; never naive green-up. Delta meaning carried by arrow-glyph + text, not color alone.
- **Per-widget states:** skeleton on load and an **error state with retry** per widget (never a blank card; show
  last-updated); charts keep explicit empty states.
- **Bed occupancy** as an accessible **segmented tracker** (composition + total; ok/warn/critical from `settings`)
  with per-segment `aria-label` + a `<figcaption>` and visually-hidden data table; trend charts **direct-labeled**
  (no legend eye-jump); calm styling (≤5 series, teal primary, no 3D/decorative gradients).
- **Actionable lists deep-link:** make "Recent admissions" rows navigable; add a real 24h-scoped admissions list and a
  flagged/critical list (long-term · TB · readmit) — all reusing existing flags, **no new endpoints**; rows deep-link
  into the relevant filtered board/registry.
- **Security P2 — print/export hygiene:** rounds print = clinical minimum (MRN as id; no nationality/free-text beyond
  need) mirroring the de-identified export floor; add a print footer (user · timestamp) for accountability and
  `Cache-Control: no-store` intent on PHI views.
- **Acceptance:** every widget has loading/empty/error; deltas polarity-correct; occupancy tracker has a text
  alternative; actionable rows deep-link; print view is clinical-minimum.

### Wave 5 — Accessibility, i18n-readiness & session-security UX
- **A11y sweep:** fix heading order (h1→h2→h3) on Dashboard and others; make every click-only row keyboard-operable
  (`role=button`/tabindex/keydown); ≥40px touch targets (`coarse:` variant already exists); apply the focus token
  everywhere including dense-table cells.
- **Opportunistic RTL:** on every file touched in W0–W4, replace physical `ml-/mr-/text-left` with logical properties
  (`ms-/me-/text-align:start`); set `lang=ar`/`dir` on the Arabic wordmark and any Arabic name display.
- **Consistent dates:** a shared `formatDate` helper (`DD MMM YYYY`) applied to the inconsistent spots (e.g. Dashboard
  recent-admissions raw date).
- **Security P1 — session-security UX:** an idle/absolute-timeout **warning toast** at T-minus with a live countdown
  (`aria-live`, tabular-nums) + "Stay signed in" / "Lock now"; a one-click **Lock / Switch user** affordance in the
  nav shell (hits the existing logout route). Reads the existing backend session lifetime — no business-logic change.
- **Security P1 — CSP-readiness:** add **zero new inline scripts/handlers** (no `onclick=`, no inline `<script>`);
  quarantine the one existing dark-mode theme-bootstrap as the sole future-nonce'd exception.
- **Verification:** run **axe-core injected via Claude_Preview** (no dependency added) against Dashboard, the board,
  and Registry; fix all critical issues.
- **Acceptance:** axe reports no critical issues on the three core pages; keyboard + screen-reader smoke of the three
  core journeys (find → view → edit) passes; timeout warning fires and is announced; no new inline scripts introduced.

---

## 4. New components (all copy-in, zero runtime deps)

| Component / helper | Purpose | Wave |
|---|---|---|
| `CommandPalette` (extend `QuickJump.vue`) | Ctrl/K palette: grouped results, identity rows, recents, error state | W1 |
| `IdentityChip.vue` | The patient identity tuple that travels row → modal header | W1 |
| `FlowAlert.vue` | 3-tier callout (info/warning/critical) with SR prefix + icon + label | W0 |
| `StyleGuide.vue` (+ auth-gated route) | Living design-system reference page | W0 |
| `SortableTh.vue` + `useServerTable.js` | Accessible server-side sortable/sticky table over the existing `router.get` pattern | W2 |
| `Tabs.vue` + `useTabs.js` | ARIA tabs, roving tabindex | W3 |
| `useUnsavedGuard` (in `usePatientEdit`) | isDirty → discard-confirm | W3 |
| `useSessionTimeout` + timeout toast | Countdown warning + Stay/Lock | W5 |
| `formatDate` + `deltaPolarity` helpers (`lib/ui.js`) | Consistent dates; polarity-aware KPI deltas | W4/W5 |

---

## 5. Security & patient-safety pillar (a major — P0→P2, wave-mapped)

Backend controls are strong per `SECURITY-AUDIT.md` (step-up re-auth, hash-chained audit, idle+absolute timeout —
SPC-TM-012). The UI's job is to make those controls **legible and hard to defeat by human error** on shared
workstations, and to **fix the one open UI finding** (SPC-TM-011).

- **P0 Wrong-patient prevention** (W1/W2/W3): a fixed identity tuple everywhere (name · MRN · age/sex · ward); MRN in
  tabular-nums and never truncated/masked; the **IdentityChip** re-shows the *same* string from search row through the
  action modal; deceased/discharged rows marked; every destructive/edit modal header restates name·MRN.
- **P0 No PHI in URL / log / storage** (W1): realize **SPC-TM-011** (MRN → POST/opaque ids); recents in-memory-only,
  cleared on logout; `autocomplete=off` on PHI fields.
- **P1 Session-security UX** (W5): timeout warning + countdown (announced), Lock / Switch user.
- **P1 Destructive + step-up UX** (W3): preserve re-auth; state exact effect + blast radius; typed-confirm for merges.
- **P1 CSP-readiness** (W0/W5): no new inline scripts/handlers; quarantine the single theme-bootstrap (SPC-WEB-001/011).
- **P2 Print/export PHI hygiene** (W4): clinical-minimum print, accountability footer, `no-store` intent (SPC-WEB-015).

**Regression floor — never weaken (from `SECURITY-AUDIT.md` "Positive controls"):** every route stays behind
`auth + session.timeout + mfa.enroll + pwd`; no `$request->all()` mass-assignment; no SQLi (parameterized only);
de-identified exports with CSV-formula-injection neutralized; append-only hash-chained audit; step-up on
delete/reverse/merge/admin-grant; self-registration stays inactive + non-admin.

---

## 6. Guardrails (every wave)

- **No new runtime dependencies** (copy-in Vue components only); pin nothing new.
- **No external calls / CDNs** (fonts already self-hosted); app stays fully offline/intranet.
- **One behavior change only** — the SPC-TM-011 search GET→POST (a security *improvement*), covered by a new PHPUnit
  test. Every other form submits the **same payload to the same endpoint**; every renamed route keeps a redirect.
- **CSP-ready:** no new inline scripts/handlers.
- Refactor **styles/markup, not data flow**; smallest possible surface per change.

---

## 7. Verification & tooling (plugin-mapped)

- **TDD** (`test-driven-development`) for all new logic: palette recents/error, `useServerTable`, `Tabs` roving focus,
  unsaved guard, `useSessionTimeout`, polarity deltas, and the POST search endpoint.
- **Vitest** (front-end) + **PHPUnit two-pass** (`--exclude-group pdf` then `--group pdf`) — behavior-preserving.
- **Claude_Preview** for before/after screenshots (into `docs/renovation/`) and runtime **axe-core** a11y checks.
- **`verification-before-completion`** before each PR; **`requesting-code-review` / `feature-dev:code-reviewer`** per wave.

---

## 8. Definition of Done

- EHC-branded, signature-driven UI; single token source; `/style-guide` renders every primitive in both themes.
- Any patient reachable in ≤3 interactions via the command palette; wrong-patient safeguards (identity tuple + traveling
  IdentityChip) in place.
- **No PHI in URLs/history/logs** (SPC-TM-011 closed); recents in-memory-only; session-timeout warning live.
- All touched pages meet WCAG 2.2 AA basics; axe reports no critical issues on Dashboard, board, Registry.
- Every dashboard widget has loading/empty/error; KPI deltas are polarity-correct.
- Every `SECURITY-AUDIT.md` positive control re-verified intact in the final report; no new runtime deps.
- Before/after screenshots for each major page in `docs/renovation/`.

---

## 9. References (patterns adapted — not copied; licenses noted)

- **NHS Digital Service Manual** (design system + accessibility) — Crown copyright / OGL: content tone, callouts,
  error-summary, focus, status semantics, button hierarchy, dense-form exception for internal staff services.
- **shadcn/ui, satnaing/shadcn-admin, TanStack Table** — MIT: command-palette (cmdk), ARIA Tabs, headless
  server-side table, RBAC-aware nav — **patterns/semantics only**, re-authored in Vue.
- **Tremor** — MIT: KPI-card anatomy, delta chips, sparklines, segmented tracker — adapted to ApexCharts/`useChartTheme`.
- **Koru UX / UI Bakery / AdminLTE** healthcare roundups — patient look-up disambiguation, timeline, census layouts,
  HMS sidebar IA (directional inspiration).
- Dribbble / Behance medical-dashboard galleries — not machine-fetchable; visual direction synthesized into §2 rather
  than scraped.

---

## 10. Out of scope

No patient profile page/route · no EHR modules (vitals/labs/meds/documents/allergies/phone/national-ID/DOB) · no React
libraries · no full Arabic RTL mode or Arabic font · no CSP/security-header/backend work (that is `SECURITY-AUDIT.md`
M1 — separate) · no re-touching the already-good tokens/fonts/dark-mode/charts beyond signature refinement.
