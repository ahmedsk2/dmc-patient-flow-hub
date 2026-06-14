# DMC Patient-Flow — Usability / IA / Refactor Roadmap (Design Spec)

> **For agentic workers:** a 3-wave consolidation program (NO new clinical features). Each wave is
> built as its own slice (TDD, suite green, committed, pushed) with a checkpoint between waves.
> Source: a 3-dimension read-only usability/IA/code-org review (2026-06-14).

**Goal:** make the (feature-complete) DMC app *more useful, easier to use, and better organized* by
taming the menu/IA bloat that the fast 5-phase feature growth produced, smoothing day-to-day clinical
friction, and finishing the shared-component extraction — WITHOUT adding clinical features or changing
any clinical number.

**Status:** approach approved by owner ("whole program, phased", 2026-06-14). Waves run 1 -> 2 -> 3.

**Invariants (every wave):** server-side authorization is unchanged — **nav visibility is cosmetic, never the security boundary**; all refactors are **behavior-preserving** (existing PHPUnit + Vitest stay green); keep dark mode + a11y (focus-trap / aria-live / th-scope / reduced-motion); reuse the existing Tailwind tokens + the established shared pattern (Components/{ConfirmDialog,IcdTypeahead,ActivityPanel}, composables/{useConfirm,useModalA11y,useChartTheme}); additive only; rebuild + commit public/build each wave.

## Owner sign-off flags (defaults chosen; confirm or override)
| Flag | Default chosen | Wave |
|---|---|---|
| **Recent Activity audience** | Move nav entry to Clinical; make the *view* read-only for clinical roles; keep same-day *undo* admin-only **server-side** | 1 |
| **Patient quick-jump / find-discharged PHI scope** | Role-scoped: non-admins get a targeted MRN/name lookup of **their own unit only**, not registry browse | 2 |
| **Confirmation-trim rule** | Keep confirm on irreversible/identity actions (delete, change-identity, complete-discharge, sign-all); drop on reversible ones (single sign-off, shuffle, admit-from-ICU, undo-medical) with undo-in-toast | 2 |
| **Role-aware nav** | Hide entry points a role can't use (e.g. New Admissions without can_add); server authz still the real gate | 1 |

## Cross-wave sequencing
`lib/ui.js` (localToday/constants/helpers) and `BaseModal.vue` are foundational — Wave 3 owns them, but Wave 2's date-fix needs `localToday()` and Wave 2's queue<->board Modify unification is *completed* by Wave 3's `PatientForm`. Resolution: if a foundational helper is needed before its wave, create a minimal version early and let the later wave absorb it (noted per-item below). Build order within each wave puts foundations first.

---

## Wave 1 — Navigation & IA

This wave reorganizes the existing flat Administration menu into labeled sub-sections, promotes Recent Activity to the Clinical group, adds role/capability-aware nav filtering, introduces breadcrumbs, unifies the page-title pattern, and adds a compact admin landing band on the Dashboard — all purely presentational and behavior-preserving. No server-side authz changes, no new pages, no clinical feature additions. Every item is additive or a refactor that leaves existing tests green.

---

### 1. Regroup Administration into labeled sub-sections + extract NavLink.vue (effort M, risk Low)

**Goal / user value**
Twelve flat items are cognitively overwhelming. Four labeled sections let users jump directly to their task area. A shared NavLink.vue removes ~40 lines of duplicated render logic and makes future nav changes a single-file edit.

**Files**
- `resources/js/Layouts/AppLayout.vue` — modify (~lines 76-102, 254-273, and the admin block ~264-290)
- `resources/js/Components/NavLink.vue` — **create** (new shared component)
- `resources/js/Layouts/__tests__/AppLayout.spec.js` — **create** (Vitest)

**Design**

Replace the flat `adminNavItems` array (currently an array of `{label, href, icon}`) with a structured array of sections:

```js
// Inside AppLayout.vue <script setup>
const adminNavSections = [
  {
    section: 'ANALYTICS & REPORTS',
    items: [
      { label: 'Statistics',  href: route('statistics.index'), icon: 'chart-bar'      },
      { label: 'Reports',     href: route('reports.index'),    icon: 'document-text'  },
      { label: 'Registry',    href: route('registry.index'),   icon: 'archive-box'    },
    ],
  },
  {
    section: 'GOVERNANCE & SAFETY',
    items: [
      { label: 'Audit Log',        href: route('audit.index'),    icon: 'clipboard-list' },
      { label: 'Security',         href: route('security.index'), icon: 'shield-check'   },
      { label: 'Recently Deleted', href: route('deleted.index'),  icon: 'trash'          },
    ],
  },
  {
    section: 'DATA MANAGEMENT',
    items: [
      { label: 'Data Quality',  href: route('data-quality.index'), icon: 'beaker'          },
      { label: 'Patient Merge', href: route('merge.index'),        icon: 'arrows-pointing-in' },
      { label: 'Bulk Import',   href: route('import.index'),       icon: 'arrow-up-tray'   },
    ],
  },
  {
    section: 'SETTINGS',
    items: [
      { label: 'Control Panel', href: route('control.index'), icon: 'cog-6-tooth' },
    ],
  },
]
```

M&M Pack is nested under Reports — see Item 2 below; it does not appear as a top-level entry.

**NavLink.vue** — a single slot-less component that renders one sidebar link. It absorbs the currently duplicated `<a>` / `<Link>` markup from the Clinical loop (~line 254-260) and the admin loop:

```vue
<!-- resources/js/Components/NavLink.vue -->
<script setup>
import { Link, usePage } from '@inertiajs/vue3'
const props = defineProps({
  href:    { type: String,  required: true },
  icon:    { type: String,  required: true },   // Heroicon name (outline)
  label:   { type: String,  required: true },
  indent:  { type: Boolean, default: false },   // for M&M nesting (Item 2)
  badge:   { type: Number,  default: null  },   // optional count badge
})
const isActive = computed(() => usePage().url.startsWith(props.href))
</script>

<template>
  <Link
    :href="href"
    :class="[
      'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
      indent ? 'ml-5' : '',
      isActive
        ? 'bg-brand/10 text-brand font-semibold'
        : 'text-ink-muted hover:bg-card hover:text-ink',
    ]"
    :aria-current="isActive ? 'page' : undefined"
  >
    <!-- Icon slot resolved from Heroicons sprite or inline SVG lookup -->
    <HeroIcon :name="icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
    <span>{{ label }}</span>
    <span
      v-if="badge !== null"
      class="ml-auto rounded-full bg-danger/15 px-1.5 py-0.5 text-xs font-nums text-danger"
      aria-label="`${badge} items`"
    >{{ badge }}</span>
  </Link>
</template>
```

`HeroIcon` can be a tiny inline lookup (name → SVG string map) or the existing pattern already used in the codebase — check `resources/js/Components/` for the current icon approach and match it exactly rather than introducing a new dependency.

The admin section render loop replaces the current flat list:

```vue
<!-- AppLayout.vue admin sidebar block (replaces ~lines 264-290) -->
<template v-if="$page.props.auth.user.is_admin">
  <template v-for="section in adminNavSections" :key="section.section">
    <p class="mt-4 px-3 text-[10px] font-semibold uppercase tracking-widest text-ink-faint">
      {{ section.section }}
    </p>
    <NavLink
      v-for="item in section.items"
      :key="item.href"
      v-bind="item"
    />
  </template>
</template>
```

The Clinical block (~lines 254-260) also switches to `<NavLink>` (same props, no `indent`).

**Decisions & defaults**
- Heroicon names above are placeholder strings — match the exact icon-lookup convention already in the codebase (grep `resources/js` for `icon` or `HeroIcon` before implementing).
- Section heading style (`text-[10px] uppercase tracking-widest text-ink-faint`) reuses the existing pattern visible at ~line 265; confirm exact class names match the live token.
- `isActive` uses `startsWith` so `/reports/governance` correctly highlights both Reports and M&M. If exact-match is preferred for top-level items, use `=== usePage().url` and `startsWith` only for the indent variant.
- No owner sign-off needed for this item.

**Test plan**
- `AppLayout.spec.js` (Vitest): mount with a stubbed `$page.props.auth.user` (`is_admin: true`), assert all four section headings appear; assert `aria-current="page"` on the active link; assert non-admin user sees zero section headings.
- `NavLink.spec.js` (Vitest): prop matrix — `indent` adds `ml-5`; `badge` renders the count span; `isActive` state applies brand classes.
- PHPUnit: no behavior change — existing Feature tests for each admin route still pass (nav is client-side only).

**Build sequence**
1. Create `NavLink.vue` with props/template; write Vitest spec; confirm it passes.
2. Replace Clinical link loop in `AppLayout.vue` with `<NavLink>` — run suite.
3. Introduce `adminNavSections` data structure (without M&M nesting yet — Item 2 does that).
4. Replace admin flat loop with sectioned loop + section headings.
5. Verify suite green; `npm run build` and commit `public/build`.

---

### 2. Nest M&M Pack under Reports (effort S, risk Low)

**Goal / user value**
M&M Pack is `/reports/governance`, a child of the Reports area. Giving it a visual indent under Reports and removing its standalone nav row reduces menu length by one and signals the information hierarchy correctly.

**Files**
- `resources/js/Layouts/AppLayout.vue` — modify `adminNavSections` ANALYTICS section
- `resources/js/Pages/Reports/Index.vue` — add governance cross-link near line 28
- `resources/js/Components/NavLink.vue` — `indent` prop (already designed in Item 1)

**Design**

In `adminNavSections`, the Reports entry gains a `children` array; the standalone M&M row is removed:

```js
{
  section: 'ANALYTICS & REPORTS',
  items: [
    { label: 'Statistics', href: route('statistics.index'), icon: 'chart-bar' },
    {
      label:    'Reports',
      href:     route('reports.index'),
      icon:     'document-text',
      children: [
        { label: 'M&M Pack', href: route('reports.governance'), icon: 'shield-check', indent: true },
      ],
    },
    { label: 'Registry', href: route('registry.index'), icon: 'archive-box' },
  ],
},
```

The render loop expands children inline (no recursive component needed at this depth):

```vue
<template v-for="item in section.items" :key="item.href">
  <NavLink v-bind="item" />
  <template v-if="item.children">
    <NavLink
      v-for="child in item.children"
      :key="child.href"
      v-bind="child"
      :indent="true"
    />
  </template>
</template>
```

In `Reports/Index.vue` near line 28, add a governance call-to-action card mirroring the existing monthly-report link pattern:

```vue
<Link :href="route('reports.governance')" class="... flex items-center gap-2">
  <HeroIcon name="shield-check" class="h-4 w-4 text-warning" aria-hidden="true" />
  M&amp;M / Governance Pack
</Link>
```

**Decisions & defaults**
- The M&M nav row that previously existed as a standalone item in the flat list is removed. Any existing Vitest snapshot of the admin nav must be updated.
- `isActive` on the parent Reports link fires when the URL starts with `/reports`, so both `/reports` and `/reports/governance` highlight Reports — the indent variant highlights only `/reports/governance`. This is correct behavior.
- No owner sign-off needed.

**Test plan**
- Update `AppLayout.spec.js`: assert M&M renders indented under Reports; assert no standalone M&M top-level item.
- `Reports/Index.spec.js` (Vitest): assert governance link is present and points to the correct route.
- PHPUnit: `/reports/governance` route still resolves — run `php artisan route:list | grep governance` check in CI.

**Build sequence**
1. Add `children` to Reports entry in `adminNavSections`; update render loop.
2. Remove standalone M&M entry from the flat list (it no longer exists after Item 1 restructure).
3. Add cross-link to `Reports/Index.vue`.
4. Update Vitest snapshots; confirm suite green; rebuild assets.

---

### 3. Move Recent Activity into the Clinical group (effort M, risk Medium)

**Goal / user value**
Recent Activity is a clinical audit feed — nurses and registrars use it to see what happened during a shift. Burying it in Administration hides it from its primary audience. Moving it to Clinical (read-only for all clinical roles) while keeping the UNDO actions admin-gated server-side is a read/write split that is already partially correct (the undo POSTs likely already check `is_admin`) — this item makes it explicit and surfaces the page to the right audience.

**Files**
- `resources/js/Layouts/AppLayout.vue` — move Recent Activity from `adminNavSections` to `clinicalNavItems`
- `resources/js/Pages/Recent/Index.vue` — guard UNDO UI on `$page.props.auth.user.is_admin` (~lines 12-13, 41)
- `routes/web.php` — split the route group or add inline middleware to confirm the READ route is `auth`-only (not `admin`-only) and the UNDO POST routes remain `admin`-only (~line 151)
- `app/Http/Controllers/RecentController.php` (or equivalent) — confirm UNDO action methods check `is_admin`; add `abort(403)` if not already present
- `resources/js/Layouts/__tests__/AppLayout.spec.js` — update

**Design**

`clinicalNavItems` (the array feeding the Clinical sidebar section, currently defined near line 76) gains one entry:

```js
const clinicalNavItems = [
  { label: 'Dashboard',       href: route('dashboard'),           icon: 'squares-2x2',  can: () => true            },
  { label: 'New Admissions',  href: route('admissions.index'),    icon: 'user-plus',    can: () => user.can.add    },
  { label: 'Patients',        href: route('patients.index'),      icon: 'users',         can: () => true            },
  { label: 'Handovers',       href: route('handovers.index'),     icon: 'arrow-right-circle', can: () => true      },
  { label: 'Consultations',   href: route('consultations.index'), icon: 'chat-bubble-left-right', can: () => true  },
  { label: 'Recent Activity', href: route('recent.index'),        icon: 'clock',         can: () => true            },
  //  ^ was in Administration; now visible to all clinical roles including Observer (read-only)
]
```

`can` predicates are evaluated in the render loop (Item 4 formalizes this); Observer sees Recent Activity because it is read-only.

In `Recent/Index.vue`, wrap undo/action controls:

```vue
<!-- ~line 41 in Recent/Index.vue -->
<template v-if="$page.props.auth.user.is_admin">
  <button @click="undo(entry)">Undo</button>
</template>
```

And remove any "observer-hide" guard that may currently block the whole page from non-admins (~lines 12-13).

In `routes/web.php` (~line 151), confirm the split:

```php
// READ — all authenticated users
Route::get('/recent', [RecentController::class, 'index'])->name('recent.index');

// UNDO — admin only
Route::post('/recent/{id}/undo', [RecentController::class, 'undo'])
    ->name('recent.undo')
    ->middleware('admin');   // or Gate::authorize('admin') inside the controller
```

If a dedicated `admin` middleware does not exist (check `app/Http/Middleware/`), the controller method can use `abort_unless(auth()->user()->is_admin, 403)` — matching the existing authz pattern in the codebase.

**Decisions & defaults**
- DEFAULT (requires owner sign-off): Recent Activity index is visible to all authenticated roles including Observer. The owner should confirm this does not expose PHI beyond what Observers already see on the patient list. If PHI scope is a concern, the route can be gated to `$access_PICU_patients` roles (0,2,3,4) — Observer (5) excluded — with a one-line middleware change.
- The UNDO action remaining admin-only is non-negotiable and requires no sign-off.
- Recent Activity is removed from `adminNavSections` (GOVERNANCE & SAFETY or wherever it currently sits).

**Test plan**
- PHPUnit Feature: `GET /recent` as Observer → 200 (previously likely 403 or redirect); as unauthenticated → 302 to login.
- PHPUnit Feature: `POST /recent/{id}/undo` as Registrar → 403; as Admin → 200/302.
- Vitest `AppLayout.spec.js`: Recent Activity appears in Clinical section for all role mocks; does NOT appear in admin sections.
- Vitest `Recent/Index.spec.js`: Undo button visible with `is_admin: true`; hidden with `is_admin: false`.

**Build sequence**
1. Confirm current route/middleware setup for `/recent` — read `routes/web.php` line 151 and `RecentController`.
2. Split routes if not already split; add `abort_unless` to undo method.
3. Update `Recent/Index.vue` — wrap undo controls, remove full-page admin gate.
4. Move Recent Activity entry from `adminNavSections` to `clinicalNavItems`.
5. Write/update PHPUnit Feature tests; run suite.
6. Update Vitest specs; rebuild assets.

---

### 4. Role/capability-aware nav filtering (effort S, risk Low)

**Goal / user value**
"New Admissions" appears for users who cannot add patients (`can_add = false`). Clicking it wastes a round-trip and shows a page of disabled controls. Hiding dead entry points reduces confusion. This is cosmetic only — server authz remains the real gate.

**Files**
- `resources/js/Layouts/AppLayout.vue` — add `can` predicate to `clinicalNavItems`; filter in template
- `resources/js/Components/NavLink.vue` — no change (filtering happens upstream)
- `resources/js/Layouts/__tests__/AppLayout.spec.js` — update

**Design**

The `auth.user` shape already exposes `is_admin` and `can.{add,assign,manage,modify}` via `HandleInertiaRequests` (confirmed by `Registry/Index.vue:14-15`). No backend changes needed.

Each clinical nav item carries an optional `can` function (default `() => true`):

```js
const { auth } = usePage().props
const user     = auth.user

const clinicalNavItems = computed(() => [
  { label: 'Dashboard',      href: route('dashboard'),        icon: 'squares-2x2', can: true },
  { label: 'New Admissions', href: route('admissions.index'), icon: 'user-plus',   can: user.can.add },
  { label: 'Patients',       href: route('patients.index'),   icon: 'users',        can: true },
  { label: 'Handovers',      href: route('handovers.index'),  icon: 'arrow-right-circle', can: true },
  { label: 'Consultations',  href: route('consultations.index'), icon: 'chat-bubble-left-right', can: true },
  { label: 'Recent Activity',href: route('recent.index'),     icon: 'clock',        can: true },
].filter(item => item.can))
```

Using a `computed()` ensures reactivity if the user shape ever changes without a page reload. The `can` value is a boolean (not a function) for simplicity — avoids the need for `item.can()` calls in the template.

Admin nav sections do not need `can` filtering because the entire block is already gated by `is_admin`.

Observer filtering (hide write-triggering pages) is already handled at item level: Recent Activity `can: true` (read-only page); New Admissions `can: user.can.add` (Observer has `can_add = false` → hidden). No additional Observer-specific logic needed beyond what `can.*` flags provide.

**Decisions & defaults**
- Only New Admissions is filtered in this wave. Consultations (add-only) and Handovers are visible to all clinical roles because the page itself degrades gracefully for read-only users. Flag for owner: should Observer see the Consultations nav item? Current answer is yes (read-only view exists).
- `can` is a synchronous boolean derived from the already-shared prop — no additional AJAX call.

**Test plan**
- Vitest `AppLayout.spec.js`: mount with `can: { add: false }` → assert New Admissions link absent; mount with `can: { add: true }` → assert present.
- PHPUnit: no change — server-side `can_add` gate on `admissions.store` is unchanged.

**Build sequence**
1. Change `clinicalNavItems` from a plain array to a `computed()` with `can` booleans.
2. Add `.filter(item => item.can)` at the end of the computed.
3. Update Vitest spec with two role mocks.
4. Run suite; rebuild assets.

---

### 5. Breadcrumbs (effort M, risk Low)

**Goal / user value**
Deep pages (Reports/Governance, Registry detail, Admin sub-pages) have no spatial context. A breadcrumb trail lets users navigate up without back-button dependency, and provides screen-reader orientation.

**Files**
- `resources/js/Layouts/AppLayout.vue` — add `breadcrumbs` prop; render trail near line 294
- `resources/js/Components/Breadcrumbs.vue` — **create**
- Pages that need breadcrumbs (modify, not all at once):
  - `resources/js/Pages/Reports/Governance.vue`
  - `resources/js/Pages/Reports/Index.vue`
  - `resources/js/Pages/Registry/Index.vue` (and detail page if it exists)
  - `resources/js/Pages/Statistics/Index.vue`
  - `resources/js/Pages/AuditLog/Index.vue`, `Security/Index.vue`, `DataQuality/Index.vue`, `PatientMerge/Index.vue`, `BulkImport/Index.vue`, `RecentlyDeleted/Index.vue`, `ControlPanel/Index.vue`

**Design**

**Breadcrumbs.vue** — a pure presentational component:

```vue
<!-- resources/js/Components/Breadcrumbs.vue -->
<script setup>
import { Link } from '@inertiajs/vue3'
defineProps({
  crumbs: { type: Array, default: () => [] },
  // Each crumb: { label: string, href?: string }
  // Last crumb has no href (current page)
})
</script>

<template>
  <nav aria-label="Breadcrumb" v-if="crumbs.length > 1">
    <ol class="flex flex-wrap items-center gap-1 text-sm text-ink-muted">
      <li v-for="(crumb, i) in crumbs" :key="i" class="flex items-center gap-1">
        <Link
          v-if="crumb.href"
          :href="crumb.href"
          class="hover:text-ink transition-colors"
        >{{ crumb.label }}</Link>
        <span
          v-else
          class="text-ink font-medium"
          aria-current="page"
        >{{ crumb.label }}</span>
        <span v-if="i < crumbs.length - 1" aria-hidden="true" class="text-ink-faint">/</span>
      </li>
    </ol>
  </nav>
</template>
```

**AppLayout.vue** — add prop and slot near the page title area (~line 294):

```vue
<!-- AppLayout.vue additions -->
<script setup>
// existing props...
defineProps({
  // ...existing
  breadcrumbs: { type: Array, default: () => [] },
})
</script>

<!-- In template, immediately before or after the h1 (~line 294): -->
<Breadcrumbs :crumbs="breadcrumbs" />
```

**Page-level usage** — pages pass breadcrumbs as a prop to their layout. Example for `Reports/Governance.vue`:

```vue
<AppLayout
  title="M&M / Governance Pack"
  :breadcrumbs="[
    { label: 'Administration' },
    { label: 'Analytics & Reports' },
    { label: 'Reports', href: route('reports.index') },
    { label: 'M&M / Governance Pack' },
  ]"
>
```

The first crumb ("Administration") has no href — it represents the section label, not a page. This is the "derive the first crumb from the nav section" requirement. Alternatively, link it to the Dashboard since there is no dedicated Administration landing page. Default: no href on the section crumb (safe, avoids a dead link).

**Breadcrumb map for all affected pages** (implement incrementally):

| Page | Crumbs |
|---|---|
| Reports/Index | Administration › Analytics & Reports › Reports |
| Reports/Governance | Administration › Analytics & Reports › Reports (linked) › M&M Pack |
| Statistics/Index | Administration › Analytics & Reports › Statistics |
| Registry/Index | Administration › Analytics & Reports › Registry |
| AuditLog/Index | Administration › Governance & Safety › Audit Log |
| Security/Index | Administration › Governance & Safety › Security |
| RecentlyDeleted/Index | Administration › Governance & Safety › Recently Deleted |
| DataQuality/Index | Administration › Data Management › Data Quality |
| PatientMerge/Index | Administration › Data Management › Patient Merge |
| BulkImport/Index | Administration › Data Management › Bulk Import |
| ControlPanel/Index | Administration › Settings › Control Panel |
| Registry detail (if exists) | Administration › Analytics & Reports › Registry (linked) › Patient Detail |

**Decisions & defaults**
- Section crumbs ("Administration", "Analytics & Reports") have no `href` by default. No owner sign-off needed.
- Breadcrumbs are optional — pages that do not pass the prop see nothing (zero crumbs → component does not render due to `v-if="crumbs.length > 1"`).
- Reduced-motion: the component has no animation; no special handling needed.
- `aria-current="page"` on the final crumb satisfies WCAG 2.1 SC 2.4.8.

**Test plan**
- Vitest `Breadcrumbs.spec.js`: renders nothing with 0 or 1 crumbs; renders `nav[aria-label="Breadcrumb"]` with 2+ crumbs; last crumb has `aria-current="page"` and no `<Link>`; intermediate crumbs render as links.
- Vitest `AppLayout.spec.js`: assert `<Breadcrumbs>` is rendered when `breadcrumbs` prop is non-empty.
- PHPUnit: no behavior change — breadcrumbs are purely presentational.

**Build sequence**
1. Create `Breadcrumbs.vue`; write Vitest spec; confirm green.
2. Add `breadcrumbs` prop to `AppLayout.vue`; render `<Breadcrumbs :crumbs="breadcrumbs" />`.
3. Add breadcrumbs to `Reports/Governance.vue` and `Reports/Index.vue` first (highest-value, validates the pattern).
4. Propagate to remaining admin pages in order of the table above.
5. Rebuild assets after each page group; run suite.

---

### 6. Unify the page-title pattern (effort S, risk Low)

**Goal / user value**
Every deep page currently declares its title in two places: an `<Inertia Head>` block inside the page component and a `title` prop passed to `AppLayout`. This is error-prone (they can drift) and adds boilerplate. Having `AppLayout` own the `<Head title>` removes the duplication.

**Files**
- `resources/js/Layouts/AppLayout.vue` — add `<Head>` with `title` prop + constant suffix; example at `Reports/Governance.vue:24-25`
- All page components that currently have their own `<Head>` block — remove the `<Head>` incrementally (do not do all at once; confirm each page renders correctly)

**Design**

In `AppLayout.vue` `<script setup>`, import `Head` from Inertia and derive the document title:

```vue
<script setup>
import { Head } from '@inertiajs/vue3'

const props = defineProps({
  title:       { type: String, default: '' },
  breadcrumbs: { type: Array,  default: () => [] },
})

const APP_NAME = 'DMC Internal Medicine'
const documentTitle = computed(() =>
  props.title ? `${props.title} — ${APP_NAME}` : APP_NAME
)
</script>

<template>
  <Head :title="documentTitle" />
  <!-- rest of layout -->
</template>
```

Inertia's `<Head>` is already used per-page; when placed in the layout it still works correctly because Inertia merges `<Head>` declarations — but the page-level one will override the layout-level one if both are present. The safe migration path is: add `<Head>` to the layout first, then remove page-level `<Head>` blocks one by one, confirming the title in the browser tab each time.

Example removal from `Reports/Governance.vue` (~lines 24-25):

```vue
<!-- BEFORE -->
<Head title="M&M / Governance Pack" />
<AppLayout title="M&M / Governance Pack" :breadcrumbs="[...]">

<!-- AFTER -->
<AppLayout title="M&M / Governance Pack" :breadcrumbs="[...]">
  <!-- Head is now the layout's responsibility -->
```

**Decisions & defaults**
- Suffix format: `{Page Title} — DMC Internal Medicine`. The em-dash (`—`) distinguishes this from a colon-separated pattern. If the owner prefers `|`, change the constant.
- Pages with no `title` prop render `DMC Internal Medicine` as the bare document title — this applies to the Dashboard (which may already do something custom; check `Dashboard.vue` before removing its `<Head>`).
- `APP_NAME` is hardcoded in the layout constant (not pulled from a backend config) — acceptable since the branding is fixed. If a `config('app.name')` is already passed via `HandleInertiaRequests`, use that instead (check the shared data payload).

**Test plan**
- Vitest `AppLayout.spec.js`: mount with `title="Statistics"` → assert `document.title` or the `<Head>` rendered output contains `Statistics — DMC Internal Medicine`.
- Manual smoke: open each modified page in a browser tab and confirm the tab title is correct.
- PHPUnit: no behavior change.

**Build sequence**
1. Add `<Head :title="documentTitle" />` to `AppLayout.vue`.
2. Verify Dashboard tab title is unchanged (Dashboard may rely on its own `<Head>`).
3. Remove `<Head>` from `Reports/Governance.vue` and `Reports/Index.vue`; confirm tab titles.
4. Remove from remaining admin pages in batches of 3-4; rebuild and spot-check each batch.
5. Final suite run; rebuild assets.

---

### 7. Admin landing band on Dashboard (effort M, risk Low)

**Goal / user value**
Admins land on the Dashboard and must navigate away to see operational health signals (data quality issues, security anomalies, deleted records, pending handovers). A compact card row surfacing these counts with deep-links into the regrouped admin sections gives admins a single-glance operational view without a new page.

**Files**
- `resources/js/Pages/Dashboard.vue` — add admin band below (or above) the existing clinical cards; gated by `is_admin`
- `resources/js/Components/AdminBandCard.vue` — **create** (tiny card component)
- No new backend route/controller needed — counts already feed the notification digest in `AppLayout.vue` (~lines 161-168 `dq`/`security` payloads); pass them to Dashboard via the existing shared data or a Dashboard-specific prop

**Design**

**AdminBandCard.vue** — a compact stat card for the band:

```vue
<!-- resources/js/Components/AdminBandCard.vue -->
<script setup>
import { Link } from '@inertiajs/vue3'
defineProps({
  label:  { type: String,  required: true },
  count:  { type: Number,  required: true },
  href:   { type: String,  required: true },
  icon:   { type: String,  required: true },
  urgent: { type: Boolean, default: false },  // true → danger tint when count > 0
})
</script>

<template>
  <Link
    :href="href"
    class="flex items-center gap-3 rounded-xl border border-ring-line bg-card px-4 py-3
           shadow-sm transition-shadow hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand"
    :class="{ 'border-danger/40 bg-danger/5': urgent && count > 0 }"
  >
    <HeroIcon :name="icon" class="h-5 w-5 shrink-0 text-ink-muted" aria-hidden="true" />
    <div class="min-w-0">
      <p class="nums text-2xl font-semibold" :class="urgent && count > 0 ? 'text-danger' : 'text-ink'">
        {{ count }}
      </p>
      <p class="truncate text-xs text-ink-muted">{{ label }}</p>
    </div>
  </Link>
</template>
```

**Dashboard.vue** — add the band inside the existing template, gated by `is_admin`:

```vue
<script setup>
// existing imports...
const { auth, adminBand } = usePage().props
// adminBand: { dqIssues, securityAnomalies, recentlyDeleted, pendingHandovers }
</script>

<template>
  <!-- existing dashboard content -->

  <section
    v-if="auth.user.is_admin"
    aria-label="Administrative overview"
    class="mt-6"
  >
    <h2 class="font-display mb-3 text-sm font-semibold uppercase tracking-wide text-ink-faint">
      Administrative
    </h2>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <AdminBandCard
        label="Data Quality Issues"
        :count="adminBand.dqIssues"
        :href="route('data-quality.index')"
        icon="beaker"
        :urgent="true"
      />
      <AdminBandCard
        label="Security Anomalies"
        :count="adminBand.securityAnomalies"
        :href="route('security.index')"
        icon="shield-exclamation"
        :urgent="true"
      />
      <AdminBandCard
        label="Recently Deleted"
        :count="adminBand.recentlyDeleted"
        :href="route('deleted.index')"
        icon="trash"
        :urgent="false"
      />
      <AdminBandCard
        label="Pending Handovers"
        :count="adminBand.pendingHandovers"
        :href="route('handovers.index')"
        icon="arrow-right-circle"
        :urgent="false"
      />
    </div>
  </section>
</template>
```

**Backend — where the counts come from**

Read `AppLayout.vue` lines 161-168 to determine how `dq` and `security` payloads are currently computed. Two patterns are possible:

a) They are already in `HandleInertiaRequests::share()` (the shared data bag). If so, read them directly from `usePage().props` in Dashboard — no controller change needed. Rename/alias them into `adminBand` in the Dashboard controller or keep them as-is and map in the component.

b) They are fetched by `AppLayout.vue` via a JavaScript call on mount. If so, the Dashboard controller (`DashboardController.php`) needs to add them to its `Inertia::render()` return value for admin users:

```php
// DashboardController.php — additive, no behavior change for non-admins
$adminBand = null;
if (auth()->user()->is_admin) {
    $adminBand = [
        'dqIssues'          => DataQualityService::openIssueCount(),   // reuse existing
        'securityAnomalies' => SecurityService::anomalyCount(),        // reuse existing
        'recentlyDeleted'   => Patient::onlyTrashed()->count(),        // or equivalent
        'pendingHandovers'  => Handover::pending()->count(),           // or equivalent
    ];
}
return Inertia::render('Dashboard', compact('adminBand', /* existing props */));
```

Confirm the exact service/query pattern by reading the current `DashboardController` and the lines in `AppLayout.vue` that load these counts before implementing.

**Decisions & defaults**
- The band is `v-if="auth.user.is_admin"` — invisible to all clinical roles including Registrar and Consultant.
- "Pending Handovers" count: use the same query that feeds the existing handovers badge if one exists; otherwise `Handover::where('status', 'pending')->count()`. Flag for owner: confirm the "pending" definition.
- `urgent` (danger tint) applies to Data Quality and Security counts only — these represent active problems. Recently Deleted and Pending Handovers are informational. Owner may want to adjust the `urgent` flags.
- Reduced-motion: the `transition-shadow` on hover is CSS-only and automatically suppressed by the existing `@media (prefers-reduced-motion)` in Tailwind's base; no extra work.

**Test plan**
- Vitest `AdminBandCard.spec.js`: `urgent=true` + `count=3` → danger border/tint classes present; `count=0` → no danger classes; `urgent=false` → no danger classes regardless of count.
- Vitest `Dashboard.spec.js`: mount with `is_admin: true` + `adminBand` prop → assert `section[aria-label="Administrative overview"]` present with 4 cards; mount with `is_admin: false` → assert section absent.
- PHPUnit Feature `DashboardTest.php`: `GET /dashboard` as Admin → response contains `adminBand` in Inertia props; as Registrar → `adminBand` is `null` or absent.

**Build sequence**
1. Locate `AppLayout.vue:161-168` and the Dashboard controller — determine whether counts are shared-data or controller-level.
2. Create `AdminBandCard.vue`; write Vitest spec.
3. Add `adminBand` to `DashboardController` (admin-only, null otherwise).
4. Add the band section to `Dashboard.vue` behind `v-if="auth.user.is_admin"`.
5. Write PHPUnit Feature test for the prop presence.
6. Write Vitest Dashboard component test.
7. Run full suite; rebuild assets.

---

## Risks & Sequencing Notes

**Dependencies between items**

- Item 1 (NavLink.vue + section structure) must land before Items 2, 3, and 4 — they all modify the same `adminNavSections` / `clinicalNavItems` data structures. Do not parallelize Item 1 with any other nav item.
- Item 2 (M&M nesting) depends on Item 1's `children` render loop being in place.
- Item 3 (Recent Activity move) depends on Item 1's `clinicalNavItems` shape (the `can` field introduced in Item 4 is also needed if Recent Activity should respect Observer filtering — implement Items 3 and 4 together or in immediate sequence).
- Item 5 (Breadcrumbs) depends on Item 1 only in that the section label strings used in crumbs should match the final section headings from Item 1. Implement after Item 1 is merged.
- Item 6 (title unification) is independent of all other items and can land any time after Item 1 (it touches `AppLayout.vue` which Item 1 also modifies — coordinate the merge or rebase).
- Item 7 (admin band) is independent of all nav items and can land in parallel with Items 5-6.

**Recommended merge order:** 1 → 2 → (3 + 4 together) → 5 → 6 → 7.

**Risk: `<Head>` duplication during Item 6 migration**
If a page-level `<Head>` and the layout-level `<Head>` are both present, Inertia v2 merges them and the page-level title wins. This is safe during incremental migration (titles remain correct) but produces a Vitest warning about duplicate head declarations. Suppress with `// @ts-ignore` or resolve quickly by batching the removals.

**Risk: Recent Activity PHI scope (Item 3)**
This is the only item with a non-trivial owner sign-off requirement. If the sign-off is delayed, implement Item 3 with Observer excluded (`can: user.position !== 5` or `!user.is_observer`) as a conservative default, and flip it to `can: true` once the owner confirms. The server-side route split should land regardless — it makes the authz explicit whether or not Observers can read.

**Risk: Admin band count queries (Item 7)**
If the counts in `AppLayout.vue:161-168` are expensive queries running on every page load (they presumably are, since they already exist), adding them to the Dashboard props is zero additional cost. If they are deferred/lazy in the layout, the Dashboard controller will need to call the same underlying service methods directly — confirm before implementing to avoid N+1 regressions.

**Suite green check after each item**
Run `php artisan test --testsuite=Feature` and `npm run test` after each item is merged. Items 1-4 touch `AppLayout.vue` repeatedly; a stale import or a renamed prop will break multiple Vitest specs silently if the suite is not run between merges.

**Asset rebuild**
Run `npm run build` and commit `public/build` after Items 1-4 (nav restructure complete), after Item 5 (Breadcrumbs), and after Items 6-7. Three build commits total for this wave.

---

## Wave 2 — Clinical ease-of-use

This wave targets the daily friction experienced by clinicians: a board search that silently returns nothing when a patient has been discharged or not yet assigned; no keyboard-first way to jump to any patient by MRN; date fields that shift by one day for KSA users due to a UTC/local mismatch in `new Date().toISOString()`; confirmation dialogs that interrupt reversible one-click actions; inconsistent assign-modal defaults and wording between the queue and the board; autofocus gaps that force an extra click on every modal and search page; board section collapse state that resets on every page load; a handful of write actions that emit no feedback toast; and a bulk-reassign preflight that requires manual scrolling to find which handover notes are still needed. No new clinical features are introduced; server-side authorization is never relaxed; all existing PHPUnit and Vitest tests must remain green.

---

### Item 1 — Discharged/unassigned fallback on board zero-state (effort M, risk low)

- **Goal / user value**: A ward doctor searches "Al-Otaibi" on the board, gets nothing, and currently has no next step. After this item, the same search shows "No active match. Found 2 discharged / 1 awaiting assignment — view." They can jump directly to the relevant row instead of pivoting to registry or phoning the admin.

- **Files**
  - Modify: `laravel/app/Http/Controllers/PatientsController.php` (add `boardFallback()` private method + `fallback` key in the `index()` Inertia share)
  - Modify: `laravel/resources/js/Pages/Patients/Index.vue` (render the inline zero-state affordance in the empty-board block)

- **Design**

  `PatientsController::index()` already resolves `$filters['search']`. Add a `fallback` prop that fires only when `search` is non-empty AND the filtered `$groups` array is empty after the main query. The fallback runs two cheap COUNT queries inside the same request, scoped to the same `$scope` closure (the D1 consultant scope is intentionally preserved — a consultant only sees fall-through counts for patients within their own unit):

  ```php
  // PatientsController.php — add inside index(), after $groups is built
  $fallback = null;
  if (!empty($filters['search']) && empty($groups)) {
      $s = $filters['search'];
      $match = fn ($q) => $q->whereHas('patient',
          fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%"));
      $fallback = [
          'discharged' => Admission::whereNotNull('discharge_date')
              ->tap($scope)->tap($match)->count(),
          'unassigned' => Admission::whereNull('discharge_date')
              ->whereNull('consultant_id')->tap($match)->count(),
          'search' => $s,
      ];
  }
  ```

  This exposes `fallback: Object|null` as a new Inertia prop.

  In `Patients/Index.vue`, the existing empty-board block (line ~365, the `<tr v-if="!groups.length">` row) is augmented:

  ```vue
  <!-- after the "No results" row, inside the summary table footer -->
  <tr v-if="!groups.length && fallback && (fallback.discharged || fallback.unassigned)">
    <td colspan="7" class="px-5 py-3 text-center text-sm text-ink-500">
      No active match.
      <span v-if="fallback.discharged">
        Found <strong class="nums text-ink-700">{{ fallback.discharged }}</strong> discharged
        <Link :href="`/registry?mode=admissions&search=${encodeURIComponent(fallback.search)}&discharged=1`"
              class="font-semibold text-brand-600 underline underline-offset-2 hover:text-brand-700">view →</Link>
      </span>
      <span v-if="fallback.unassigned">
        / <strong class="nums text-ink-700">{{ fallback.unassigned }}</strong> awaiting assignment
        <Link href="/admissions" class="font-semibold text-brand-600 underline underline-offset-2 hover:text-brand-700">queue →</Link>
      </span>
    </td>
  </tr>
  ```

  The `defineProps` in `Patients/Index.vue` (line 18) gains `fallback: { type: Object, default: null }`.

  **PHI scope flag (requires owner sign-off):** The fallback counts are scoped by `$scope` (the D1 closure), so a consultant only sees counts for their own unit. The registry link carries `search=` which the admin-gated RegistryController already guards. Non-admins clicking "view →" will land on a 403; the link is still safe to show because the count alone is non-identifying (it only says how many, not who). If the owner wants non-admins to never see the registry link at all, wrap it: `v-if="me.is_admin"` and show only the count to non-admins. Default: show the link to all roles (it just 403s for non-admins — they get a clear "not authorised" page rather than a dead end). Flag this choice for the owner.

- **Test plan**

  PHPUnit `Feature/BoardFallbackTest.php`:
  - Consultant with 0 active patients but 2 discharged + 1 unassigned matching "Ali" → `fallback.discharged === 2`, `fallback.unassigned === 1`
  - Non-empty active results → `fallback === null`
  - D1 scope: consultant A searching a name that exists only under consultant B's discharged patients → `fallback.discharged === 0`

  Vitest: mount `Patients/Index.vue` with `groups=[]`, `fallback={discharged:2, unassigned:1, search:'Ali'}` → assert the fallback text and link hrefs render; with `fallback=null` → assert the fallback row is absent.

- **Build sequence**
  1. Add `boardFallback()` count logic to `PatientsController::index()` with PHPUnit test covering the D1 scope (red → green).
  2. Add `fallback` to `defineProps`.
  3. Render fallback row in the summary table; Vitest test (red → green).
  4. Rebuild and commit assets.

---

### Item 2 — Global patient quick-jump in the header (effort M, risk medium)

- **Goal / user value**: Any clinician can press `/` from any page, type a partial MRN or name, and navigate to a patient's board card or queue entry within 2–3 keystrokes — without leaving the current page to search.

- **Files**
  - Create: `laravel/resources/js/Components/QuickJump.vue`
  - Modify: `laravel/resources/js/Layouts/AppLayout.vue` (import + insert at ~line 297)
  - Modify: `laravel/routes/web.php` (new route `GET /api/patients/quick-search`)
  - Modify: `laravel/app/Http/Controllers/PatientsController.php` (new `quickSearch()` method) — do NOT reuse `PatientMergeController::searchPatients()` directly because its scope is admin-only and returns a different payload; extract shared logic instead

- **Design**

  **Route** (inside the authenticated, non-admin group):
  ```php
  Route::get('/api/patients/quick-search', [PatientsController::class, 'quickSearch'])
      ->name('patients.quickSearch');
  ```

  **`PatientsController::quickSearch()`**: Returns up to 8 results as JSON. The scope rule is:
  - Admin: searches across all patients (active + discharged), returning `{admission_id, patient_id, mrn, name, status, consultant_name}`.
  - Non-admin (registrar/resident/consultant): searches ACTIVE episodes only (`discharge_date IS NULL`) within the D1 scope (consultants get their own group; registrars/residents get all active). The `$scope` closure from `boardScope()` is reused.
  - Observer: same as non-admin non-consultant.

  The response shape per result:
  ```json
  {
    "id": 412,
    "mrn": "10023847",
    "name": "Ahmad Al-Otaibi",
    "status": "active|discharged|unassigned",
    "consultant": "Dr. Khalid",
    "href": "/patients?search=10023847"
  }
  ```
  `href` always points to `/patients?search={mrn}` (which the board handles); discharged results for admins point to `/registry?mode=admissions&search={mrn}`. The controller keeps the search to `WHERE (patients.mrn LIKE ? OR patients.name LIKE ?)` with a prepared binding — no interpolation. Minimum 2 characters before the server query fires (enforced server-side with a 422 + empty array fallback; the client also debounces).

  **`QuickJump.vue`** component:

  ```vue
  <script setup>
  import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue';
  import { router } from '@inertiajs/vue3';

  const input = ref(null);
  const open = ref(false);
  const q = ref('');
  const results = ref([]);
  const busy = ref(false);
  let timer = null;
  const xsrf = () => decodeURIComponent(
    (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');

  const focusInput = () => { open.value = true; nextTick(() => input.value?.focus()); };
  const close = () => { open.value = false; q.value = ''; results.value = []; };

  // "/" global shortcut — skip if focus is inside an input/textarea/select/[contenteditable]
  const onKey = (e) => {
    if (e.key !== '/' || ['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)
        || document.activeElement?.isContentEditable) return;
    e.preventDefault(); focusInput();
  };
  onMounted(() => window.addEventListener('keydown', onKey));
  onUnmounted(() => window.removeEventListener('keydown', onKey));

  watch(q, (v) => {
    clearTimeout(timer);
    if (v.length < 2) { results.value = []; return; }
    timer = setTimeout(async () => {
      busy.value = true;
      try {
        const r = await fetch(`/api/patients/quick-search?q=${encodeURIComponent(v)}`,
          { headers: { Accept: 'application/json' } });
        results.value = r.ok ? await r.json() : [];
      } finally { busy.value = false; }
    }, 250);
  });

  const go = (item) => { close(); router.visit(item.href); };
  </script>

  <template>
    <div class="relative">
      <button @click="focusInput" title="Quick patient search (press /)"
        aria-label="Quick patient search"
        class="hidden items-center gap-2 rounded-xl border border-line bg-card px-3 py-1.5 text-sm text-ink-400 shadow-sm transition hover:border-brand-400 hover:text-ink-600 sm:flex">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" />
        </svg>
        <span>Search patient…</span>
        <kbd class="rounded bg-ink-100 px-1.5 py-0.5 text-[10px] font-bold text-ink-500">/</kbd>
      </button>

      <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center pt-20 px-4"
           @click.self="close">
        <div class="w-full max-w-md rounded-2xl bg-card shadow-2xl ring-1 ring-line"
             role="dialog" aria-label="Quick patient search" aria-modal="true"
             @keydown.esc="close">
          <div class="flex items-center gap-3 border-b border-line px-4 py-3">
            <svg class="h-5 w-5 shrink-0 text-ink-400" fill="none" viewBox="0 0 24 24"
                 stroke-width="1.7" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" />
            </svg>
            <input ref="input" v-model="q" placeholder="MRN or patient name…"
                   class="flex-1 bg-transparent text-sm outline-none placeholder:text-ink-400"
                   aria-label="Patient search query"
                   @keydown.esc="close"
                   @keydown.down.prevent="/* arrow nav below */"
                   autocomplete="off" />
            <span v-if="busy" class="text-xs text-ink-400">…</span>
            <kbd class="rounded bg-ink-100 px-1.5 py-0.5 text-[10px] font-bold text-ink-500">Esc</kbd>
          </div>

          <ul v-if="results.length" class="max-h-72 divide-y divide-line overflow-auto py-1"
              role="listbox">
            <li v-for="r in results" :key="r.id" role="option">
              <button @click="go(r)"
                class="w-full flex items-center gap-3 px-4 py-2.5 text-left transition hover:bg-brand-50/50 focus:bg-brand-50/50 focus:outline-none">
                <span class="nums min-w-[5rem] text-xs font-semibold text-ink-500">{{ r.mrn }}</span>
                <span class="flex-1 text-sm font-semibold text-ink-800">{{ r.name }}</span>
                <span class="text-xs text-ink-400">{{ r.consultant }}</span>
                <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                  :class="r.status === 'active' ? 'bg-success-100 text-success-600'
                    : r.status === 'unassigned' ? 'bg-accent-100 text-accent-600'
                    : 'bg-ink-100 text-ink-500'">
                  {{ r.status }}
                </span>
              </button>
            </li>
          </ul>
          <p v-else-if="q.length >= 2 && !busy"
             class="px-4 py-6 text-center text-sm text-ink-400">No patients found.</p>
          <p v-else-if="q.length < 2"
             class="px-4 py-4 text-center text-xs text-ink-300">Type at least 2 characters</p>
        </div>
      </div>
    </div>
  </template>
  ```

  In `AppLayout.vue` at line ~297 (inside `<div class="ml-auto flex items-center gap-3">`), insert `<QuickJump />` immediately before the "Live" badge and the theme button:

  ```vue
  <div class="ml-auto flex items-center gap-3">
      <QuickJump />                         <!-- NEW: left of the bell -->
      <div class="hidden items-center ...">  <!-- existing "Live" badge -->
  ```

  **PHI scope flag (requires owner sign-off):** Non-admin scope is `active + D1` only. Admins get cross-history search. This means a non-admin clicking a discharged patient's result (which they can't see because the quickSearch only returns active rows for them) is not possible — the limitation is invisible. If the owner wants non-admins to also see recently-discharged patients of their own group (e.g. within 48h), that can be added as an optional extension. Default: active-only for non-admins.

- **Test plan**

  PHPUnit `Feature/QuickSearchTest.php`:
  - Admin query "Ahmad" → returns up to 8 rows from active + discharged
  - Consultant D1 scope: returns only their own active patients
  - Observer: returns only active rows (same as consultant scope)
  - `q` < 2 chars → returns `[]` with 422 response (client handles gracefully)
  - SQL injection: `q = "'; DROP TABLE patients; --"` → query runs safely via prepared binding

  Vitest for `QuickJump.vue`:
  - `/` keydown on `document` while focused on `<body>` → opens dialog
  - `/` keydown while focused on `<input>` → no-op
  - Esc closes the dialog
  - Selecting a result calls `router.visit(item.href)`

- **Build sequence**
  1. Add `quickSearch()` to `PatientsController` with PHPUnit scope tests (red → green).
  2. Register the route.
  3. Build `QuickJump.vue` with Vitest tests for keyboard and interaction (red → green).
  4. Import in `AppLayout.vue` and insert in header markup.
  5. Rebuild and commit assets.

---

### Item 3 — Local-date helper `localToday()` (effort S, risk low)

- **Goal / user value**: Admission forms and modal date fields pre-fill with the correct local calendar date (KSA is UTC+3; `toISOString()` returns the previous day between midnight and 3:00 AM local time, producing an admission dated "yesterday" for night-shift staff).

- **Files**
  - Create: `laravel/resources/js/lib/ui.js`
  - Modify: `laravel/resources/js/Pages/Admissions/Create.vue` (line 9)
  - Modify: `laravel/resources/js/Pages/Patients/Index.vue` (line 182)
  - Modify: `laravel/resources/js/Pages/Consultations/Index.vue` (line 33)
  - Modify: `laravel/resources/js/Pages/Admissions/Index.vue` (line 63)
  - Modify: `laravel/resources/js/Pages/Registry/Index.vue` (line 65)

  Wave 3 will import from this same file; nothing needs to move when Wave 3 ships.

- **Design**

  `laravel/resources/js/lib/ui.js`:
  ```js
  /**
   * Returns today's date as YYYY-MM-DD in the browser's LOCAL timezone.
   * Do NOT use new Date().toISOString().slice(0,10) — that gives UTC date,
   * which is the previous calendar day in UTC+ timezones before 00:00 UTC.
   */
  export function localToday() {
      const d = new Date();
      return [
          d.getFullYear(),
          String(d.getMonth() + 1).padStart(2, '0'),
          String(d.getDate()).padStart(2, '0'),
      ].join('-');
  }
  ```

  In each page file, replace the `const today = new Date().toISOString().slice(0, 10)` line with:
  ```js
  import { localToday } from '@/lib/ui.js';
  const today = localToday();
  ```

  The `today` constant is then used identically to before (`useForm({ ..., admit_date: today })`). This is a pure mechanical substitution — no logic changes.

- **Decisions & defaults**: `localToday()` is computed once at module setup time, which is correct: forms are opened inside an already-loaded page, so a date that changes after midnight while the page is open is an edge case that was already broken before and is out of scope. If Wave 3 needs a reactive version, the function is trivially wrappable in a `computed(() => localToday())`.

- **Test plan**

  Vitest `lib/ui.test.js`:
  - Mock `Date` to a UTC midnight (00:00 UTC = 03:00 KSA, still the same calendar date locally) → `localToday()` returns the KSA calendar date, not the UTC-previous date.
  - No-mock case: result matches `new Intl.DateTimeFormat('en-CA').format(new Date())` (the YYYY-MM-DD format).

  Behavior: all existing PHPUnit tests that POST with a `today`-based date remain green because the server never cared which date the client sent for "today" — it accepts any valid date string.

- **Build sequence**
  1. Create `lib/ui.js` with Vitest test (red → green).
  2. Mechanically replace all 5 occurrences, one file at a time; run Vitest suite after each.
  3. Rebuild and commit assets.

---

### Item 4 — Confirmation-fatigue trim (effort M, risk low)

- **Goal / user value**: `useConfirm` dialogs currently appear on single-sign-off, shuffle, admit-from-ICU, and undo-medical-discharge — all reversible or neutral actions. Removing these dialogs saves 1–2 clicks on the most frequent actions (sign-off, undo) while preserving confirmation only where it is irreversible: delete, change-patient-identity, complete-discharge (closes the episode), and Sign-all (bulk).

- **Files**
  - Modify: `laravel/resources/js/Pages/Consultations/Index.vue` (sign-off handler, `~line 80`)
  - Modify: `laravel/resources/js/Pages/Patients/Index.vue` (shuffle handler `~line 96`, `undoMedical` handler `~line 232`)
  - Modify: `laravel/resources/js/Pages/Admissions/Index.vue` (`fromIcu` handler `~line 60`)
  - Create: `laravel/resources/js/lib/confirmPolicy.md` (plain text rule document, 8 lines)

- **Design**

  **Canonical rule** (capture in `confirmPolicy.md` and in code comments):
  > Reserve `useConfirm` for: hard-delete of any record; change-patient-identity (MRN/name edit); complete-discharge (closes the clinical episode, affects statistics); Sign-all (bulk, multi-patient). All other actions are either reversible (undo-medical, reverse-signoff) or low-stakes (shuffle, single signoff, admit-from-ICU). These use immediate-action + the existing flash toast.

  **Changes per file:**

  `Consultations/Index.vue` — the `signoff` handler. Currently:
  ```js
  const signoff = async (c) => {
      if (await ask('Sign off consultation', `...`, 'neutral'))
          router.post(`/consultations/${c.id}/signoff`, ...);
  };
  ```
  Replace with:
  ```js
  const signoff = (c) => router.post(`/consultations/${c.id}/signoff`, {}, { preserveScroll: true });
  ```
  The reverse-signoff button is already instant (no confirm). `deleteConsult` keeps its `ask('danger')` confirm.

  `Patients/Index.vue` — shuffle handler (line ~96):
  ```js
  // BEFORE
  const shuffle = async () => { if (await ask(...)) router.post('/admissions/shuffle', ...); };
  // AFTER
  const shuffle = () => router.post('/admissions/shuffle', {}, { preserveScroll: true });
  ```
  The server already returns a flash message ("Shuffle assigned N patients…" or "No unassigned patients"). That toast is the user's feedback.

  `Patients/Index.vue` — `undoMedical` (line ~232):
  ```js
  // BEFORE
  const undoMedical = async (row) => { if (await ask('Undo medical discharge', ..., 'neutral')) router.post(...); };
  // AFTER
  const undoMedical = (row) => router.post(`/admissions/${row.id}/undo-medical-discharge`, {}, { preserveScroll: true });
  ```
  The server returns `'Medical discharge undone.'` flash. The action is reversible (re-run medical-discharge again).

  `Admissions/Index.vue` — `fromIcu` handler (line ~60):
  ```js
  // BEFORE
  const fromIcu = async (p) => { if (await ask('Admit from ICU', ..., 'neutral')) router.post(...); };
  // AFTER
  const fromIcu = (p) => router.post(`/admissions/${p.id}/icu-pull`, {}, { preserveScroll: true,
      onSuccess: () => (showIcu.value = false) });
  ```
  The ICU-pull creates a new unassigned queue entry (reversible via discharge) and the server flashes `'Patient admitted from ICU — now in the assignment queue.'`.

  **Handovers/Index.vue — Sign-all** (`signAll`, line ~29): keep `ask()` — this is bulk (multi-patient) and is the one neutral action that warrants a confirm because the user may not have reviewed all items.

  **Single sign** (`sign`, line ~28): already instant (no confirm) — no change needed.

- **Test plan**

  Vitest regression: mount `Patients/Index.vue` with a mock `router.post`, call `shuffle()` → `router.post` is called immediately, `ask` is never called. Same pattern for `undoMedical`, `fromIcu`, `signoff`.

  Existing PHPUnit tests for `signoff`, `undoMedical`, `icuPull`, `shuffle` — all pass unchanged (server logic is untouched).

- **Build sequence**
  1. Write `confirmPolicy.md` one-liner rule.
  2. Remove `ask()` from `signoff`, add Vitest test (red → green).
  3. Remove `ask()` from `shuffle` and `undoMedical`, Vitest (red → green).
  4. Remove `ask()` from `fromIcu`, Vitest (red → green).
  5. Rebuild and commit assets.

---

### Item 5 — Unify queue/board assign-modal behavior and wording (effort S, risk low)

- **Goal / user value**: Today the queue's assign modal (`Admissions/Index.vue`) uses `mark_new: false` default while the board's assign modal (`Patients/Index.vue`) uses `mark_new: true`. The "Assign to me" button on the queue has no success toast gap (the server does return `'Assigned to you.'` flash, so this is actually already covered server-side). This item normalises the `mark_new` default, adds a success toast call for the "Assign to me" path on the queue page, standardises the modal title wording, and adds a small comment explaining the mark_new semantics.

- **Files**
  - Modify: `laravel/resources/js/Pages/Admissions/Index.vue` (line ~50, ~52, ~54)
  - Modify: `laravel/resources/js/Pages/Patients/Index.vue` (modal title text, line ~191)

- **Design**

  **mark_new default decision** (requires owner sign-off): the queue is where newly-admitted patients live. When staff manually assign from the queue, the intention is typically to mark them new (the shuffle does set `mark_new: true`). Align the queue assign modal to default `mark_new: true` to match the board. Document this with a comment. If the owner wants the current queue default (false) preserved, this sub-item is a no-op.

  `Admissions/Index.vue` line ~50:
  ```js
  // BEFORE
  const aForm = useForm({ consultant_id: '', mark_new: false });
  const openAssign = (p) => { assigning.value = p; aForm.consultant_id = ''; aForm.mark_new = false; ...
  // AFTER — mark_new default unified with the board (owner sign-off required)
  const aForm = useForm({ consultant_id: '', mark_new: true });
  const openAssign = (p) => { assigning.value = p; aForm.consultant_id = ''; aForm.mark_new = true; ...
  ```

  **"Assign to me" toast** on queue page: the server already sets the flash; the Inertia response delivers it via `page.props.flash`. The `assignToMe` handler (line ~54) fires `router.post(..., {}, { preserveScroll: true })` which already causes `AppLayout` to pick up the flash. No Vue change needed — the toast already works. Confirm via smoke-test only.

  **Modal title wording**: in `Patients/Index.vue`, the action modal renders its title from `modal.value.mode`. Add a title map so the assign mode always reads "Assign consultant" (both from the queue and from the board). Current ad-hoc rendering at the modal title slot — update to:
  ```js
  const modalTitle = computed(() => ({
      assign: 'Assign consultant',
      medical: 'Medical discharge',
      complete: 'Complete discharge',
      icu: 'ICU discharge',
      transfer: 'Transfer',
  }[modal.value?.mode] ?? ''));
  ```

  In `Admissions/Index.vue`, the assign modal `<h2>` tag reads "Assign consultant" (add this text if the current heading is absent or differs).

- **Decisions & defaults**: mark_new default change is gated on owner sign-off. The title unification and toast confirmation are unconditional.

- **Test plan**

  Vitest: mount `Admissions/Index.vue`, call `openAssign({id:1,...})` → `aForm.mark_new === true`.
  Mount `Patients/Index.vue`, call `openModal('assign', row)` → `modalTitle.value === 'Assign consultant'`.

- **Build sequence**
  1. Add `modalTitle` computed to `Patients/Index.vue`; Vitest test.
  2. Update queue `mark_new` default (after owner sign-off); Vitest test.
  3. Rebuild and commit assets.

---

### Item 6 — Autofocus on primary input in search pages and modals (effort S, risk low)

- **Goal / user value**: Opening a modal or navigating to a search page currently leaves focus on the backdrop or the trigger button. Users must click again to type. After this item, every modal opens with focus on the first field (not just the first focusable element, which may be a close button), and the three search inputs on Patients, Consultations, and Registry auto-focus on page mount.

- **Files**
  - Modify: `laravel/resources/js/composables/useModalA11y.js` (add `{ field: true }` option)
  - Modify: `laravel/resources/js/Pages/Patients/Index.vue` (search input, assign consultant select, new-consultation MRN)
  - Modify: `laravel/resources/js/Pages/Consultations/Index.vue` (search input, new-consultation MRN field)
  - Modify: `laravel/resources/js/Pages/Registry/Index.vue` (search input)

- **Design**

  `useModalA11y.js` currently focuses `items[0]` on open (line 18), which is the first focusable element — often a close/cancel button. Add a `fieldFirst` mode: when true, skip buttons/links and focus the first `input`, `select`, or `textarea` instead. The existing call sites use `onOpen()` with no argument; the new option is opt-in.

  ```js
  // useModalA11y.js — extend onOpen signature
  const onOpen = (el, { fieldFirst = false } = {}) => {
      opener = el ?? document.activeElement;
      nextTick(() => {
          const items = getFocusable();
          if (fieldFirst) {
              const field = items.find((i) => ['INPUT','SELECT','TEXTAREA'].includes(i.tagName));
              (field ?? items[0])?.focus();
          } else {
              items[0]?.focus();
          }
      });
  };
  ```

  **Call-site updates** — use `fieldFirst: true` on these opens:
  - `Patients/Index.vue` `openModal()` (line ~196): `a11yAction.onOpen(undefined, { fieldFirst: true })`
  - `Patients/Index.vue` `openReassign()` (line ~101): `a11yReassign.onOpen(undefined, { fieldFirst: true })`
  - `Admissions/Index.vue` `openAssign()` (line ~52): `a11yAssign.onOpen(undefined, { fieldFirst: true })`
  - `Consultations/Index.vue` `openAdd()` (line ~39): `a11yAdd.onOpen(undefined, { fieldFirst: true })`

  For **search inputs on page mount**, use the existing `vFocus` custom directive (`Patients/Index.vue:83`) which is already defined as `{ mounted: (el) => el.focus() }`. Add `v-focus` to:
  - `Patients/Index.vue` search input (~line 313)
  - `Consultations/Index.vue` search input
  - `Registry/Index.vue` search input (the "keyword" or main search field)

  The `vFocus` directive is already declared in `Patients/Index.vue`; move its declaration to `lib/ui.js` as an exported `vFocus` directive that all three pages import. Or simply redeclare it in each page (two lines — acceptable for now). The Wave-3 lib pass can consolidate.

- **Decisions & defaults**: Page-mount autofocus on search inputs is only applied on pages that are purely search/list views; it is not applied to the Dashboard (a landing page). The modifier `fieldFirst: true` is the new default for all clinical-action modals. The `onOpen()` signature extension is backward-compatible (existing callers with no second argument behave identically to before).

- **Test plan**

  Vitest for `useModalA11y`: mount a fake panel with a close button then an input; call `onOpen(undefined, { fieldFirst: true })` → the input receives focus, not the button. Call `onOpen()` (no options) → the button receives focus (unchanged behaviour).

  Vitest for `QuickJump.vue` (already tests focus on dialog open).

- **Build sequence**
  1. Extend `useModalA11y.js`, Vitest test (red → green).
  2. Update the four `onOpen()` call sites to `fieldFirst: true`.
  3. Add `v-focus` to the three search inputs.
  4. Rebuild and commit assets.

---

### Item 7 — Persist board expand/collapse and "show my group only" to localStorage (effort S, risk low)

- **Goal / user value**: Consultants collapse irrelevant groups to see only their own patients. Board reload (page navigation, Inertia revisit) resets every group to expanded/collapsed default, forcing the same collapses again. After this item, the per-consultant collapsed state survives navigation.

- **Files**
  - Modify: `laravel/resources/js/Pages/Patients/Index.vue` (lines ~58–61 expand/collapse, onMounted)

- **Design**

  The existing `open` ref is a `Set<number>` of group ids (lines 58–61). The density preference already uses `localStorage` (lines 45–47) — replicate that pattern.

  **Storage key**: `'dmc-board-open'` — stores the array of expanded group IDs as JSON.
  **Storage key**: `'dmc-board-my-group'` — `'1'|'0'` for a potential "show only my group" toggle (see below).

  Initialization: read from localStorage inside `onMounted` (already present for density), falling back to the current default logic (filtering-then-all-open / otherwise collapsed):

  ```js
  onMounted(() => {
      const d = localStorage.getItem('dmc-density');
      if (d === 'compact' || d === 'comfortable') density.value = d;

      // board expand state — persisted per browser
      const saved = localStorage.getItem('dmc-board-open');
      if (saved) {
          try {
              const ids = JSON.parse(saved);
              if (Array.isArray(ids)) {
                  // intersect with current group ids (a group may have been removed)
                  const valid = new Set(props.groups.map((g) => g.id));
                  open.value = new Set(ids.filter((id) => valid.has(id)));
              }
          } catch { /* corrupt storage — ignore, use default */ }
      }
      // if saved state is empty (first visit or all collapsed) AND there's an active filter,
      // expand everything as before
      if (open.value.size === 0 && filtering.value) {
          open.value = new Set(props.groups.map((g) => g.id));
      }
  });
  ```

  Persist on every toggle:
  ```js
  const toggle = (id) => {
      open.value.has(id) ? open.value.delete(id) : open.value.add(id);
      open.value = new Set(open.value);
      localStorage.setItem('dmc-board-open', JSON.stringify([...open.value]));
  };
  const allOpen = () => {
      open.value = new Set(props.groups.map((g) => g.id));
      localStorage.setItem('dmc-board-open', JSON.stringify([...open.value]));
  };
  const allClosed = () => {
      open.value = new Set();
      localStorage.setItem('dmc-board-open', JSON.stringify([]));
  };
  ```

  **"Show only my group" toggle** (for consultant role): a small toolbar button next to the density picker. When active, `groups` displayed are filtered client-side to `g.id === me.value.id`. This is purely presentational — server data is unchanged, D1 scoping is already server-side.

  ```js
  const myGroupOnly = ref(false);
  onMounted(() => {
      // ...existing density + expand state...
      myGroupOnly.value = localStorage.getItem('dmc-board-my-group') === '1';
  });
  const setMyGroupOnly = (v) => {
      myGroupOnly.value = v;
      localStorage.setItem('dmc-board-my-group', v ? '1' : '0');
  };
  // visibleGroups replaces direct use of `groups` in the v-for
  const visibleGroups = computed(() =>
      myGroupOnly.value && !me.value.is_admin
          ? props.groups.filter((g) => g.id === me.value.id)
          : props.groups);
  ```

  In the template, replace `v-for="g in groups"` → `v-for="g in visibleGroups"` in both the summary table and the card board. Show the toggle button only when `me.value.role === 3` (consultant, not admin).

- **Decisions & defaults**: The "my group only" button is consultant-role only (admins never need it; registrars/residents see all groups and that is correct). The expand state is per-browser, not per-user — acceptable for a shared workstation scenario because density already works this way.

- **Test plan**

  Vitest: mount `Patients/Index.vue` with `localStorage` mocked; call `toggle(5)` → localStorage `dmc-board-open` updated. Mount again → open.value contains 5.

  Vitest: `myGroupOnly` toggle → `visibleGroups` filters correctly.

- **Build sequence**
  1. Add `myGroupOnly` and `visibleGroups` computed; Vitest tests (red → green).
  2. Persist `open` on toggle/allOpen/allClosed; restore in `onMounted`; Vitest test.
  3. Add "My patients only" button in toolbar (consultant-only).
  4. Rebuild and commit assets.

---

### Item 8 — Standardize action wording and ensure complete toast coverage (effort S, risk low)

- **Goal / user value**: Certain actions use inconsistent verb labels ("Reassign" vs "Change consultant" vs "Assign"). After this item, one verb is used consistently across all surfaces for each concept. Every server-side write already emits a `with('flash', ...)` response — this item audits the coverage and fixes any client path that suppresses the flash.

- **Files**
  - Modify: `laravel/resources/js/Pages/Patients/Index.vue` (button labels `~line 383`, `~line 335`)
  - Modify: `laravel/resources/js/Pages/Admissions/Index.vue` (button labels)
  - (No controller changes — all flash messages already exist server-side, see the grep above)

- **Design**

  **Audit of flash coverage**: The grep of `PatientActionController` shows every public method ends with `back()->with('flash', ...)`. `ConsultationsController` and `HandoverController` are similarly complete. `updateBed` returns `'Bed updated.'`. `assignToMe` returns `'Assigned to you.'`. There are no gaps in server-side flash coverage.

  **Client-side toast suppression check**: The inline bed save (`saveBed`) calls `router.post('/admissions/{id}/bed', ...)` with `preserveScroll: true` but no `onSuccess`. Inertia's default behavior on a successful response IS to merge the new page props (including `flash`) — so the toast fires correctly. No client fix needed.

  **Wording standardization** (canonical verb table):

  | Concept | Old labels seen | Canonical label |
  |---|---|---|
  | Move a patient to a different consultant | "Change consultant", "Bulk reassign", "Reassign" | **"Reassign"** (single) / **"Bulk reassign"** (toolbar icon aria-label unchanged) |
  | First assignment of unassigned patient | "Assign to primary", "Assign", "Assign to me" | **"Assign"** / **"Assign to me"** |
  | Inline group header button | "Change consultant" | **"Reassign"** |

  In `Patients/Index.vue` line ~383, the group-header button reads "Change consultant" → change to **"Reassign"**.

  The toolbar `aria-label="Bulk reassign"` (line ~335) is already correct — no change.

  In modal title, the existing `modalTitle` computed from Item 5 uses "Assign consultant" — keep that.

  The "Reassign" wording for the group header is the only visible change. Document the verb table in the code comment above the button for maintainer reference.

- **Test plan**

  Vitest: render `Patients/Index.vue` with a non-empty group, `canReassign = true` → assert the group header button text is "Reassign" (not "Change consultant").

  No PHPUnit changes (server behavior unchanged).

- **Build sequence**
  1. Update the group-header button label; Vitest test (red → green).
  2. Add verb-table comment.
  3. Rebuild and commit assets.

---

### Item 9 — Streamline bulk-reassign handover preflight (effort S, risk low)

- **Goal / user value**: When a consultant is chosen in the "From consultant" picker, the preflight loads their patient list. If some rows have stale handovers, the user must scroll to find which ones need text, fill them manually, and click "Save all". After this item: the first stale handover textarea is auto-focused after the preflight loads, a counter "X of Y patients still need today's handover note" appears at the top of the stale section, and "Uncheck all stale" removes them from the move (rather than forcing note-writing before proceeding).

- **Files**
  - Modify: `laravel/resources/js/Pages/Patients/Index.vue` (lines ~151–179, ~633–639 preflight section in the template, and the `staleRows` / `saveAllStale` logic)

- **Design**

  All changes are **client-only** — the server preflight endpoint (`/handovers/preflight`) and the reassign endpoint are untouched.

  **Auto-focus first stale textarea**: after `loadPreflight()` resolves and stale rows exist, focus the first stale textarea:

  ```js
  const loadPreflight = async (id) => {
      preflight.value = { loading: true, rows: [] };
      const rows = await (await fetch(`/handovers/preflight?from_consultant_id=${id}`, ...)).json();
      preflightBodies.value = Object.fromEntries(
          rows.filter((r) => !r.handover_today).map((r) => [r.id, r.body || '']));
      selectedIds.value = new Set(rows.map((r) => r.id));
      preflight.value = { loading: false, rows };
      // auto-focus the first stale textarea
      nextTick(() => {
          const first = document.querySelector('[data-stale-textarea]');
          first?.focus();
      });
  };
  ```

  Add `data-stale-textarea` attribute to the textarea element in each stale-row slot in the template.

  **"X of Y handovers still need today's note" counter**: insert above the stale-rows list in the preflight modal template. The `staleRows` computed already exists (line ~166):

  ```vue
  <!-- add above the stale rows list in the preflight panel -->
  <p v-if="staleRows.length" class="mb-2 text-sm font-semibold text-warning-600">
      {{ staleRows.length }} of {{ preflight.rows.length }} patient(s) still need today's handover note.
  </p>
  ```

  **"Uncheck all stale" button**: adds a shortcut to exclude all stale rows from the move set (the user accepts that those patients WON'T be reassigned in this batch — valid when partial reassignment is intended):

  ```js
  const uncheckAllStale = () => {
      const staleIds = new Set(staleRows.value.map((r) => r.id));
      selectedIds.value = new Set([...selectedIds.value].filter((id) => !staleIds.has(id)));
      selectedIds.value = new Set(selectedIds.value);
  };
  ```

  In the template, beside the "Save all handovers" button:
  ```vue
  <button @click="uncheckAllStale"
      class="rounded-xl border border-ink-200 px-3 py-2 text-sm font-semibold text-ink-600 hover:bg-ink-50">
      Uncheck all stale ({{ staleRows.length }})
  </button>
  ```

  This button is only shown when `staleRows.length > 0`. After unchecking, `staleRows` becomes `[]` (no stale rows among the selected), so `preflightReady` unlocks — the user can proceed with the non-stale subset.

  The existing `preflightReady` computed (line ~167) already correctly handles this case: `selectedIds.size > 0 && staleRows.value.length === 0`.

- **Test plan**

  Vitest: mount `Patients/Index.vue` stub with a pre-populated `preflight.rows` where 2 rows are stale → `staleRows.length === 2`. Call `uncheckAllStale()` → `selectedIds` no longer contains the stale ids → `preflightReady === true` (assuming remaining selected size > 0).

  Vitest: `staleRows.length` counter text renders correctly.

  No PHPUnit changes.

- **Build sequence**
  1. Add `uncheckAllStale()` function and Vitest test (red → green).
  2. Add stale counter paragraph in template.
  3. Add "Uncheck all stale" button in template.
  4. Add `data-stale-textarea` attribute and `nextTick` auto-focus in `loadPreflight`.
  5. Rebuild and commit assets.

---

## Risks & sequencing notes

**Wave 3 coordination**: Item 3 creates `laravel/resources/js/lib/ui.js`. Wave 3's lib pass should import from this file rather than creating a parallel one. Confirm with Wave 3 author before that wave ships.

**Item 5 `mark_new` default change** requires explicit owner sign-off before commit. The behavior change (queue assign now marks patients as "New") affects the New badge count on the board and the dashboard's "new today" metric. Gate this sub-item behind owner confirmation; all other Item 5 work (title wording) is unconditional.

**Item 1 registry link visibility** for non-admins requires owner decision: show the link (non-admins get a clean 403) or hide it. Default chosen above is "show but 403" — simpler, honest, and matches the existing nav-link visibility approach. Flag for confirmation.

**Item 2 quick-jump PHI scope** for non-admins (active-only vs recent-discharged) requires owner decision. Default chosen is active-only for non-admins. This is the safe default.

**Item 2 route placement**: `GET /api/patients/quick-search` lives inside the authenticated non-admin middleware group (not admin-only). This is intentional — the endpoint is PHI-aware by scoping, not by blocking all non-admins. The admin-only `GET /api/patients/search` (`PatientMergeController::searchPatients`) is unchanged and stays admin-only.

**Sequencing within the wave**: Items 3 and 8 are pure mechanical changes with no cross-item dependencies — they can be done first. Item 6 depends on `useModalA11y.js` being extended (30-minute task) before all other modal open-calls are updated. Items 4, 5, 7, 9 are independent of each other. Items 1 and 2 each touch the controller layer and should be reviewed before merging to avoid conflicts on `PatientsController.php`. Recommended order: 3 → 8 → 6 → 4 → 7 → 9 → 5 → 1 → 2.

**Asset rebuild**: `public/build` assets are committed per the project pattern. Rebuild (`npm run build` inside `laravel/`) and commit after each item or in one final commit at the end of the wave. Do not let the JS source and the committed build diverge mid-wave.

**Test suite stability**: Every item above is additive or a mechanical substitution. No existing PHPUnit Feature test touches the `boardFallback` fallback key (it is a new prop), the `quick-search` route (new), or the confirm-dialog call sites (client-only). The Vitest suite has no tests for the removed `ask()` calls — the "no behavior change" assertion is that the router.post fires in all cases, which the new tests explicitly verify.

---

## Wave 3 — Code organization / refactor

This wave is purely behavior-preserving. It extracts duplicated logic scattered across 10+ Vue files into a small set of shared utilities and components, finishing the shared-component pattern begun with `ConfirmDialog`, `IcdTypeahead`, `ActivityPanel`, and `EhcLogo`. No routes, no endpoints, no auth changes, no new clinical features. The payoff is: a single source of truth for domain constants and date helpers (unblocking Wave 2's date fix), eliminated modal scaffolding drift, a canonical patient-edit form replacing three diverged copies, and a decomposed `Patients/Index.vue` that drops from ~742 lines to ~300. All existing PHPUnit and Vitest tests must remain green after each item; new tests are additive.

---

### Item 1 — `lib/ui.js`: central UI utilities module (effort M, risk Low)

- **Goal / user value** — Eliminates five inlined `localToday()` implementations, three `xsrf()` snippets, four `locTone()` switch blocks, three `admitFromOptions` arrays, and thirty-nine field-class strings. Any future fix (e.g. Wave 2's UTC midnight date shift) lands in one place and propagates everywhere automatically.

- **Files**
  - CREATE: `resources/js/lib/ui.js`
  - MODIFY: `resources/js/Pages/Patients/Index.vue` (lines 135, 212–216, 256, 290, 416–432, and field-class strings throughout)
  - MODIFY: `resources/js/Pages/Admissions/Index.vue` (lines 41, 81, 111, 163–170, field-class strings)
  - MODIFY: `resources/js/Pages/Registry/Index.vue` (lines 83, field-class strings)
  - MODIFY: `resources/js/Pages/Dashboard/Index.vue` (line 229)
  - MODIFY: `resources/js/Pages/ActiveList.vue` (line 22)
  - MODIFY: `resources/js/Layouts/AppLayout.vue` (line 138)
  - MODIFY: `resources/js/Pages/PatientMerge.vue` (line 17)
  - MODIFY: `resources/js/Components/IcdTypeahead.vue` (default field-class prop value)
  - MODIFY: `resources/css/app.css` (add `.field` utility)

- **Design**

  `resources/js/lib/ui.js` exports:

  ```js
  // Returns YYYY-MM-DD in the browser's local timezone — single source of truth.
  export function localToday() { ... }

  // XSRF token from the meta tag — replaces three inline copies.
  export function xsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
  }

  // Domain-constant arrays — server props take priority where provided (see Decisions).
  export const ADMIT_FROM_OPTIONS = [
    'Emergency Room', 'Ward', 'ICU', 'Outpatient', 'Other'
  ]
  export const DISCHARGE_DESTINATIONS = [
    'Home', 'Transfer', 'AMA', 'Deceased'
  ]
  export const OUTCOME_STATUSES = [
    'Alive', 'Dead', 'LAMA', 'Transferred'
  ]

  // Maps current_location value → Tailwind token class string.
  export function locTone(loc) {
    switch (loc) {
      case 'ICU':  return 'bg-danger/10 text-danger ring-danger/30'
      case 'Ward': return 'bg-info/10 text-info ring-info/30'
      case 'ER':   return 'bg-warning/10 text-warning ring-warning/30'
      default:     return 'bg-card text-ink-muted ring-line'
    }
  }

  // The consultantOptions helper lives here (see Item 7).
  export function consultantOptions(consultants, { keepId = null, specialtyId = null, onServiceOnly = false } = {}) { ... }

  // FIELD is a single canonical class string for form inputs.
  export const FIELD = 'block w-full rounded-lg border border-line bg-card px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-brand/50 disabled:opacity-50'
  ```

  In `app.css`, add an `@apply` utility so server-rendered or non-Vue forms can use it too:

  ```css
  @layer components {
    .field {
      @apply block w-full rounded-lg border border-line bg-card px-3 py-2 text-sm text-ink
             placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-brand/50
             disabled:opacity-50;
    }
  }
  ```

  In each consuming file, replace inline field-class strings with either `import { FIELD } from '@/lib/ui'` (`:class="FIELD"`) or the `.field` CSS class. Replace `locTone` switch blocks with `import { locTone } from '@/lib/ui'`. Replace `xsrf()` with the import. Replace inline `localToday()` with the import.

  `IcdTypeahead.vue`: change the `inputClass` prop default from the hard-coded string to `FIELD` (import at top of `<script setup>`).

- **Decisions & defaults**
  - `admitFromOptions`: `Create.vue` currently receives these as a server prop from the Laravel controller. That server prop is the canonical source for `Create.vue`. The `ADMIT_FROM_OPTIONS` constant in `ui.js` is used only where no server prop arrives (inline modals). If the owner wants a single DB-driven source, that is a Wave 4 controller change — not in scope here.
  - The `FIELD` constant vs `.field` CSS class: components use the JS constant (avoids Tailwind purge concerns with dynamic class assembly); non-component HTML uses the CSS class. Both are kept in sync — they are literally the same string.
  - `localToday()` implementation: `new Date().toLocaleDateString('en-CA')` returns `YYYY-MM-DD` in local time on all modern browsers. This is the Wave 2–targeted fix; document it with a comment.

- **Test plan**
  - Vitest unit: `tests/js/lib/ui.test.js` — assert `localToday()` returns a string matching `/^\d{4}-\d{2}-\d{2}$/`; assert `locTone('ICU')` contains `text-danger`; assert `consultantOptions` filtering (see Item 7); assert `xsrf()` returns empty string when meta tag absent.
  - No PHPUnit changes needed (pure frontend).
  - Smoke: run existing Vitest suite before and after; assert zero regressions.

- **Build sequence**
  1. Create `resources/js/lib/ui.js` with all exports (no consumers yet). Write Vitest unit tests. Confirm green.
  2. Add `.field` to `app.css`. Confirm Tailwind build succeeds.
  3. Replace `localToday()` in all five files, one file at a time; run suite after each.
  4. Replace `xsrf()` in `AppLayout.vue:138`, `Patients/Index.vue:135`, `PatientMerge.vue:17`.
  5. Replace `locTone()` in `Dashboard/Index.vue:229`, `ActiveList.vue:22`, `Patients/Index.vue:290`, `Admissions/Index.vue:111`.
  6. Replace field-class strings across all 10 files (mechanical find-replace; verify no dynamic string concatenation is broken).
  7. Replace `IcdTypeahead` default prop.
  8. Full Vitest + PHPUnit run; commit.

---

### Item 2 — `BaseModal.vue`: single modal scaffold (effort M, risk Low–Medium)

- **Goal / user value** — Ten hand-rolled modal scaffolds have drifted in their focus-trap wiring, backdrop z-index, `aria-modal` placement, and Esc-key cleanup. One `BaseModal.vue` that owns a single `useModalA11y()` instance eliminates the drift and is the enabler for Items 3 and 4.

- **Files**
  - CREATE: `resources/js/Components/BaseModal.vue`
  - MODIFY: `resources/js/Pages/Patients/Index.vue` (lines 471, 607, 658, 697 + onEsc dispatcher 273–281)
  - MODIFY: `resources/js/Pages/Admissions/Index.vue` (lines 183, 202, 223 + onEsc 95–102)
  - MODIFY: `resources/js/Pages/Consultations/Index.vue` (modal scaffold)
  - MODIFY: `resources/js/Pages/Registry/Index.vue` (modal scaffold)
  - MODIFY: `resources/js/Pages/Control/Index.vue` (modal scaffold)
  - REFERENCE: `resources/js/composables/useModalA11y.js` (read-only — reuse as-is)

- **Design**

  ```vue
  <!-- resources/js/Components/BaseModal.vue -->
  <script setup>
  import { watch, onUnmounted } from 'vue'
  import { useModalA11y } from '@/composables/useModalA11y'

  const props = defineProps({
    open:     { type: Boolean, required: true },
    title:    { type: String,  required: true },
    subtitle: { type: String,  default: '' },
    size:     { type: String,  default: 'md',
                validator: v => ['sm','md','lg','xl','2xl'].includes(v) }
  })
  const emit = defineEmits(['close'])

  const { dialogRef, onOpen } = useModalA11y()

  // Map size → max-width token
  const sizeClass = { sm:'max-w-sm', md:'max-w-md', lg:'max-w-lg',
                      xl:'max-w-xl', '2xl':'max-w-2xl' }

  watch(() => props.open, v => { if (v) onOpen() })

  function close() { emit('close') }

  // Esc key — owned once here, cleaned up on unmount
  function onKey(e) { if (e.key === 'Escape' && props.open) close() }
  window.addEventListener('keydown', onKey)
  onUnmounted(() => window.removeEventListener('keydown', onKey))
  </script>

  <template>
    <Teleport to="body">
      <Transition name="modal">
        <div v-if="open"
             class="fixed inset-0 z-50 flex items-center justify-center p-4"
             @click.self="close">
          <!-- Backdrop -->
          <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"
               aria-hidden="true" @click="close" />
          <!-- Panel -->
          <div ref="dialogRef"
               role="dialog"
               aria-modal="true"
               :aria-labelledby="`modal-title-${$.uid}`"
               :class="['relative z-10 w-full rounded-xl bg-card shadow-xl ring-1 ring-line',
                        sizeClass[size]]">
            <!-- Header -->
            <div class="flex items-start justify-between border-b border-line px-6 py-4">
              <div>
                <h2 :id="`modal-title-${$.uid}`"
                    class="font-display text-base font-semibold text-ink">
                  {{ title }}
                </h2>
                <p v-if="subtitle" class="mt-0.5 text-sm text-ink-muted">{{ subtitle }}</p>
              </div>
              <button @click="close"
                      class="ml-4 rounded-lg p-1 text-ink-muted hover:bg-app hover:text-ink
                             focus:outline-none focus:ring-2 focus:ring-brand/50"
                      aria-label="Close dialog">
                <svg .../><!-- × icon -->
              </button>
            </div>
            <!-- Content -->
            <div class="px-6 py-4"><slot /></div>
            <!-- Footer slot (optional) -->
            <div v-if="$slots.footer"
                 class="border-t border-line px-6 py-4 flex justify-end gap-3">
              <slot name="footer" />
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </template>
  ```

  Adopting each existing modal: wrap the existing modal body content with `<BaseModal :open="showX" title="..." @close="showX=false">...</BaseModal>` and delete the hand-rolled scaffold div, the per-modal `onMounted`/`onUnmounted` keydown listeners, and the `useModalA11y()` call from the page file.

  The `Transition` uses `@media (prefers-reduced-motion: reduce)` — set duration to 0 via the existing reduced-motion pattern already in `app.css`.

- **Decisions & defaults**
  - `Teleport to="body"` is the default; this avoids z-index stacking context bugs in the AdminLTE-derived sidebar layout.
  - `$.uid` for unique `aria-labelledby` IDs: acceptable in Vue 3 (`getCurrentInstance().uid`). If the team prefers a `useId()` composable (Vue 3.5+), that is a one-line swap.
  - Backdrop click closes the modal. For destructive-action modals (e.g. delete confirm), the caller wraps with `ConfirmDialog` first — `BaseModal` itself is not opinionated about this.
  - Size `md` (`max-w-md`) is the default. Callers that currently render wide modals (e.g. the Modify patient form) pass `size="xl"`.

- **Test plan**
  - Vitest component test (`tests/js/Components/BaseModal.test.js`): mount with `open=false`, assert no dialog in DOM; set `open=true`, assert `role=dialog`, `aria-modal=true`, title rendered; simulate Esc keydown, assert `close` emitted; simulate backdrop click, assert `close` emitted; simulate X click, assert `close` emitted; assert keydown listener removed on `unmount()`.
  - Regression: existing Vitest tests for `Patients/Index`, `Admissions/Index` must still pass after the scaffold swap.

- **Build sequence**
  1. Write `BaseModal.vue` (no consumers). Write Vitest tests. Green.
  2. Replace ONE modal in `Admissions/Index.vue` (the simplest: the "Assign to me" or "Create consultation" modal). Run suite.
  3. Replace remaining `Admissions` modals. Run suite.
  4. Replace `Patients/Index.vue` modals (271–281 onEsc dispatcher disappears). Run suite.
  5. Replace `Consultations`, `Registry`, `Control` modals. Run suite.
  6. Commit.

---

### Item 3 — `PatientForm.vue` + `usePatientEdit.js` (effort L, risk Medium)

- **Goal / user value** — The Modify-patient form exists in three diverged copies: `Patients/Index.vue:696–740` (select for nationality), `Admissions/Index.vue:222–259` (same), and `Registry/Index.vue` (free-text nationality — a known drift). One canonical form component and one composable unify the logic and fix the Registry drift silently.

- **Files**
  - CREATE: `resources/js/Components/PatientForm.vue`
  - CREATE: `resources/js/composables/usePatientEdit.js`
  - MODIFY: `resources/js/Pages/Patients/Index.vue` (remove lines 234–268, 696–740; insert `<PatientForm>`)
  - MODIFY: `resources/js/Pages/Admissions/Index.vue` (remove lines 62–91, 222–259; insert `<PatientForm>`)
  - MODIFY: `resources/js/Pages/Registry/Index.vue` (remove mForm/openEdit/submitEdit; insert composable + `<PatientForm>`)
  - MODIFY: `resources/js/Pages/Patients/Create.vue` (reuse `PatientForm`'s demographics block for the admission form)
  - REFERENCE: `resources/js/Components/IcdTypeahead.vue` (reused inside `PatientForm`)

- **Design**

  **`usePatientEdit.js`**:

  ```js
  // resources/js/composables/usePatientEdit.js
  import { reactive, ref } from 'vue'
  import { xsrf, localToday } from '@/lib/ui'

  export function usePatientEdit({ endpoint, onSuccess }) {
    const form = reactive({
      id: null, mrn: '', pname: '', age: '', gender: '',
      nationality: '', admit_from: '', bed: '',
      admdate: '', diagnoses: [],   // array of {id,name}
      consultant_id: null,
    })
    const saving  = ref(false)
    const errors  = ref({})

    async function open(patient) {
      Object.assign(form, {
        id: patient.id, mrn: patient.mrn, pname: patient.pname,
        age: patient.age, gender: patient.gender,
        nationality: patient.nationality, admit_from: patient.admit_from,
        bed: patient.bed, admdate: patient.admdate,
        diagnoses: patient.diagnoses ?? [],
        consultant_id: patient.consultant_id,
      })
      errors.value = {}
    }

    function addDx(dx)    { if (!form.diagnoses.find(d => d.id === dx.id)) form.diagnoses.push(dx) }
    function removeDx(id) { form.diagnoses = form.diagnoses.filter(d => d.id !== id) }

    async function submit() {
      saving.value = true
      errors.value = {}
      try {
        const res = await fetch(endpoint(form.id), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json',
                     'X-CSRF-TOKEN': xsrf(),
                     'X-HTTP-Method-Override': 'PATCH' },
          body: JSON.stringify({ ...form,
            diagnosis_ids: form.diagnoses.map(d => d.id) }),
        })
        const data = await res.json()
        if (!res.ok) { errors.value = data.errors ?? {}; return }
        onSuccess(data.patient)
      } finally { saving.value = false }
    }

    return { form, saving, errors, open, addDx, removeDx, submit }
  }
  ```

  The `endpoint` argument is a function `(id) => string` so each caller supplies its own route (`/patients/${id}`, `/admissions/${id}`, `/registry/patients/${id}`).

  **`PatientForm.vue`**:

  Props: `form` (reactive object from `usePatientEdit`), `errors`, `countries` (array), `consultants` (array, optional — absent hides the consultant field), `admitFromOptions` (array, defaults to `ADMIT_FROM_OPTIONS` from `lib/ui`), `readonly` (Boolean, default false — for the Registry view-only panel).

  Emits: none (mutates `form` directly via `v-model` on the reactive object's properties).

  Internal structure: a two-column CSS grid (`grid-cols-2 gap-4`) — MRN, Name, Age, Gender, Nationality (select with a legacy-option fallback for values not in `countries`), Admit-From, Bed, Admit Date, Consultant (if prop provided), ICD-10 diagnosis (full-width row, reuses `IcdTypeahead` + chip list with remove buttons). Field validation errors render below each input via `errors[fieldName]`.

  **Legacy-nationality fallback** (the canonical resolution of the Registry drift): if `form.nationality` is a string not found in the `countries` array, render an `<option>` for it labeled `"(current: {value})"` and mark it `selected`. This preserves existing data without data migration.

  **Consuming pattern** (same in all three pages):

  ```vue
  <script setup>
  import { usePatientEdit } from '@/composables/usePatientEdit'
  import PatientForm from '@/Components/PatientForm.vue'

  const { form, saving, errors, open, addDx, removeDx, submit } =
    usePatientEdit({ endpoint: id => `/patients/${id}`, onSuccess: refreshRow })

  const showModify = ref(false)
  function openModify(patient) { open(patient); showModify.value = true }
  </script>

  <template>
    <BaseModal :open="showModify" title="Modify Patient" size="xl" @close="showModify=false">
      <PatientForm :form :errors :countries :consultants />
      <template #footer>
        <button @click="showModify=false">Cancel</button>
        <button :disabled="saving" @click="submit">Save</button>
      </template>
    </BaseModal>
  </template>
  ```

  `Create.vue` reuses `PatientForm` with `consultant_id` hidden (not relevant at admit time) by omitting the `:consultants` prop.

- **Decisions & defaults**
  - **Endpoint method**: the existing Laravel controllers accept `PATCH`/`PUT`; the composable sends `POST` with `X-HTTP-Method-Override: PATCH` because `Inertia.patch` is already wired to these routes. Confirm with the controller that `updatePatient` in `Patients\PatientController@update` (or equivalent) accepts JSON — if it only accepts Inertia form data, use `router.patch()` from `@inertiajs/vue3` instead of `fetch`. Flag for owner review if the controller is form-data-only.
  - **Consultant field in Registry Modify**: the Registry modify modal currently allows consultant reassignment. Keep this behavior — pass `consultants` prop.
  - **`Create.vue` demographics reuse**: `Create.vue` receives `admitFromOptions` as a server prop. Pass it down to `PatientForm` explicitly; `PatientForm`'s default is the `lib/ui` constant as fallback. No behavior change.

- **Test plan**
  - Vitest: `usePatientEdit.test.js` — mock `fetch`; assert `open()` populates form; assert `addDx`/`removeDx` mutate `form.diagnoses`; assert `submit()` calls correct endpoint with correct body; assert `errors` populated on 422 response.
  - Vitest: `PatientForm.test.js` — mount with stub form; assert nationality legacy-option renders when value not in countries list; assert IcdTypeahead present; assert `readonly` disables inputs.
  - PHPUnit: existing `PatientsModifyTest` (or equivalent Feature test) must pass unchanged — the endpoint contract is unchanged.

- **Build sequence**
  1. Write `usePatientEdit.js` with Vitest tests (no UI yet). Green.
  2. Write `PatientForm.vue` with Vitest tests. Green.
  3. Replace `Registry/Index.vue` modify flow (lowest risk — fewest dependents). PHPUnit Feature test for registry modify must still pass.
  4. Replace `Admissions/Index.vue` modify flow. Suite green.
  5. Replace `Patients/Index.vue` modify flow. Suite green.
  6. Thread demographics block into `Create.vue`. Suite green.
  7. Commit.

---

### Item 4 — Split `Patients/Index.vue` into focused modal components (effort L, risk Medium)

- **Goal / user value** — `Patients/Index.vue` is ~742 lines. After Item 3 removes the Modify modal, ~500 lines remain. Extracting `ActionModal.vue` (discharge/ICU/transfer/assign flows + shared `AdmissionSummary.vue`), `ReassignModal.vue` (preflight + bulk save), and `HandoverModal.vue` reduces `Index.vue` to ~300 lines of board rendering and per-row wiring — tractable to read and test.

- **Files**
  - CREATE: `resources/js/Components/Patients/ActionModal.vue`
  - CREATE: `resources/js/Components/Patients/AdmissionSummary.vue`
  - CREATE: `resources/js/Components/Patients/ReassignModal.vue`
  - CREATE: `resources/js/Components/Patients/HandoverModal.vue`
  - MODIFY: `resources/js/Pages/Patients/Index.vue` (inline flows replaced with component refs; target ~300 lines)
  - REFERENCE: `resources/js/composables/useConfirm.js` (reused for destructive-action confirms inside ActionModal)

- **Design**

  **`AdmissionSummary.vue`** — extracted from the read-only review block duplicated at `Patients/Index.vue:491–504` and `547–560`:

  ```vue
  <!-- props: patient (object) -->
  <!-- renders: MRN, Name, Bed, Location, Admit date, Diagnoses as DxChips (Item 5), Consultant -->
  ```

  **`ActionModal.vue`** — handles all the flow-state transitions for one patient. Props:

  ```js
  defineProps({
    open:       Boolean,
    action:     String,   // 'assign'|'medical'|'complete'|'icu'|'transfer'
    patient:    Object,
    consultants: Array,
    can:        Object,   // auth user's capability flags
  })
  defineEmits(['close', 'done'])
  ```

  Internally uses a computed `currentStep` to show the right sub-form. The `AdmissionSummary` appears at the top of every sub-form (replacing the two duplicated read-only blocks). All fetch calls use `xsrf()` from `lib/ui`. On success, emits `done` with the updated patient object; `Index.vue` patches its local `patients` array.

  **`ReassignModal.vue`** — the consultant-preflight/checkbox/save-all-stale flow (currently inline in `Patients/Index.vue:119–179`). Props: `open`, `staleList` (array of patients with stale handovers), `consultants`. Emits: `close`, `done`. Uses `useHandover.js` (Item 6) for the actual saves.

  **`HandoverModal.vue`** — the single-patient handover write modal. Props: `open`, `patient`. Emits: `close`, `done`. Uses `useHandover.js`.

  `Index.vue` after extraction:

  ```vue
  <script setup>
  // props: patients, consultants, can, ...
  // local state: which modal is open + which patient
  const modal = reactive({ type: null, patient: null })
  function openAction(type, patient) { modal.type = type; modal.patient = patient }
  </script>

  <template>
    <!-- Board table rows -->
    <ActionModal   :open="modal.type !== null && !['reassign','handover'].includes(modal.type)"
                   :action="modal.type" :patient="modal.patient"
                   :consultants :can @close="modal.type=null" @done="patchRow" />
    <ReassignModal :open="modal.type==='reassign'" ... @close="modal.type=null" @done="reload" />
    <HandoverModal :open="modal.type==='handover'" :patient="modal.patient"
                   @close="modal.type=null" @done="patchRow" />
  </template>
  ```

- **Decisions & defaults**
  - All five action types share one `ActionModal` rather than five separate components, because they share the `AdmissionSummary` header and the same `done`/`close` event contract. If action-specific forms grow complex in future waves, they can be further split without changing the `Index.vue` interface.
  - `patchRow(updatedPatient)` does a local reactive splice — no full page reload — matching the existing behavior.
  - `HandoverModal` and `ReassignModal` are separate because they are triggered from different UX entry points (row action vs header bulk button) and have unrelated state.

- **Test plan**
  - Vitest: `ActionModal.test.js` — mount with each `action` value; assert `AdmissionSummary` renders; assert correct sub-form fields shown; mock fetch; assert `done` emitted on success.
  - Vitest: `AdmissionSummary.test.js` — assert all fields render; assert DxChips rendered (from Item 5).
  - Vitest: `ReassignModal.test.js` — assert stale list renders checkboxes; assert save triggers `useHandover.saveHandover` for each checked item.
  - PHPUnit: all existing `Patients` Feature tests unchanged — no endpoint changes.

- **Build sequence**
  1. Create `AdmissionSummary.vue`, replace the two duplicated blocks in `Index.vue`. Suite green. Commit.
  2. Create `ActionModal.vue` with assign sub-form only. Wire into `Index.vue`. Suite green.
  3. Add remaining sub-forms (medical/complete/icu/transfer) to `ActionModal`. Suite green.
  4. Create `HandoverModal.vue`. Wire into `Index.vue`. Suite green.
  5. Create `ReassignModal.vue`. Wire into `Index.vue`. Suite green.
  6. Verify `Index.vue` line count is ≤ 320. Commit.

---

### Item 5 — `PatientFlags.vue` + `DxChips.vue` (effort S, risk Low)

- **Goal / user value** — The badge cluster (New / Readmit / Long-term / TB / Discharged / Disch-still-in) and the "N dx" diagnosis expander are triplicated across `Patients/Index.vue:416–432`, `ActiveList.vue:107–113`, and `Admissions/Index.vue:163–170`, with minor rendering differences. One component fixes all three simultaneously and feeds into `AdmissionSummary.vue` (Item 4).

- **Files**
  - CREATE: `resources/js/Components/PatientFlags.vue`
  - CREATE: `resources/js/Components/DxChips.vue`
  - MODIFY: `resources/js/Pages/Patients/Index.vue` (lines 416–432)
  - MODIFY: `resources/js/Pages/ActiveList.vue` (lines 107–113)
  - MODIFY: `resources/js/Pages/Admissions/Index.vue` (lines 163–170)
  - MODIFY: `resources/js/Components/Patients/AdmissionSummary.vue` (uses `DxChips`)

- **Design**

  **`PatientFlags.vue`**:

  ```js
  defineProps({
    patient:       { type: Object, required: true },
    readmitWindow: { type: Number, default: 30 },  // days; matches existing 30-day lookback
  })
  ```

  Renders: `New` badge (if `patient.newassign`), `Readmit` (if `patient.is_readmit` — computed server-side, already present as a boolean on the patient shape in the Inertia page props), `Long-term` (if `patient.longterm === 'longterm'`), `TB` (if `patient.is_tb`), `Disch` / `Disch still in` flags. Each badge uses the existing token classes (`bg-info/10 text-info`, `bg-warning/10 text-warning`, etc.). The `readmitWindow` prop is accepted but not used for client-side computation — it is documentation only; the actual readmit flag is computed in PHP. Flag for owner: confirm `patient.is_readmit` and `patient.is_tb` are already in the Inertia patient shape; if not, add them to the Laravel resource transform (controller change, one line each).

  **`DxChips.vue`**:

  ```js
  defineProps({
    diagnoses:   { type: Array, required: true },  // [{id, name}]
    max:         { type: Number, default: 2 },      // chips shown before expand
    removable:   { type: Boolean, default: false }, // show × button (for edit forms)
  })
  defineEmits(['remove'])
  ```

  Renders up to `max` chips inline; "+ N more" toggle reveals the rest. When `removable=true`, each chip has a `×` button emitting `remove(id)`. Used in `PatientFlags.vue` (display, `removable=false`) and `PatientForm.vue` (edit, `removable=true`).

- **Decisions & defaults**
  - `readmitWindow` prop exists for future use (if the PHP-side window ever becomes configurable); it does not affect current behavior.
  - `max=2` chips shown by default matches the current visual density in the patient table rows.

- **Test plan**
  - Vitest: `PatientFlags.test.js` — mount with various patient shapes; assert correct badges appear/absent.
  - Vitest: `DxChips.test.js` — assert truncation at `max`; assert expand toggle; assert `remove` event on × click when `removable=true`.
  - Smoke: visual diff across all three pages in browser (no automated screenshot test required for this wave).

- **Build sequence**
  1. Create `DxChips.vue`. Vitest tests. Green.
  2. Create `PatientFlags.vue` using `DxChips`. Vitest tests. Green.
  3. Replace `Admissions/Index.vue:163–170`. Suite green.
  4. Replace `ActiveList.vue:107–113`. Suite green.
  5. Replace `Patients/Index.vue:416–432`. Suite green.
  6. Wire `DxChips` into `AdmissionSummary.vue` (Item 4 dependency). Commit.

---

### Item 6 — `useHandover.js`: extract handover fetch paths (effort S, risk Low)

- **Goal / user value** — Three overlapping handover-write paths in `Patients/Index.vue` hand-build the same `fetch + xsrf + payload` pattern (modal write at ~119–129, inline gate-then-retry at ~136–149, bulk preflight `saveAllStale` at ~170–179). One composable removes the duplication and is the correct unit-test surface.

- **Files**
  - CREATE: `resources/js/composables/useHandover.js`
  - MODIFY: `resources/js/Pages/Patients/Index.vue` (lines ~119–179, inline fetch calls replaced)
  - MODIFY: `resources/js/Components/Patients/HandoverModal.vue` (uses composable)
  - MODIFY: `resources/js/Components/Patients/ReassignModal.vue` (uses composable)

- **Design**

  ```js
  // resources/js/composables/useHandover.js
  import { ref } from 'vue'
  import { xsrf } from '@/lib/ui'

  export function useHandover() {
    const saving = ref(false)

    async function saveHandover(patientId, body) {
      saving.value = true
      try {
        const res = await fetch(`/patients/${patientId}/handover`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': xsrf() },
          body: JSON.stringify(body),
        })
        if (!res.ok) throw new Error(await res.text())
        return await res.json()
      } finally { saving.value = false }
    }

    async function fetchHandover(patientId) {
      const res = await fetch(`/patients/${patientId}/handover`)
      if (!res.ok) throw new Error(await res.text())
      return res.json()
    }

    async function preflight(patientIds) {
      // Returns { stale: [{id, name, ...}] } for the bulk-reassign gate
      const res = await fetch('/patients/handover/preflight', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': xsrf() },
        body: JSON.stringify({ patient_ids: patientIds }),
      })
      if (!res.ok) throw new Error(await res.text())
      return res.json()
    }

    return { saving, saveHandover, fetchHandover, preflight }
  }
  ```

  The URL patterns above must match the existing Laravel routes. Verify against `routes/web.php` before wiring; if the route is `PATCH /patients/{id}/handover`, change the method accordingly. The composable is the only place this URL string lives.

- **Decisions & defaults**
  - The "inline gate-then-retry" path (Index.vue ~136–149) tries to save a handover; if the patient has no current handover, it opens the modal instead. This two-branch logic stays in `Index.vue` but now calls `fetchHandover()` and `saveHandover()` rather than raw `fetch`.
  - `saving` is a single shared ref for the composable instance. Callers that need per-patient saving state (e.g. bulk save with per-row spinners) should call `useHandover()` per patient or manage a local map. For the current bulk-save flow (a single "Save All" button), one shared `saving` ref is sufficient.

- **Test plan**
  - Vitest: `useHandover.test.js` — mock `fetch` globally; assert `saveHandover` sends correct method/headers/body; assert `fetchHandover` calls correct URL; assert `preflight` sends patient_id array; assert `saving` toggles correctly; assert error thrown on non-ok response.
  - PHPUnit: existing handover Feature tests unchanged.

- **Build sequence**
  1. Write `useHandover.js`. Vitest tests. Green.
  2. Replace the three inline fetch paths in `Index.vue`. Suite green.
  3. Wire into `HandoverModal.vue` (Item 4). Suite green.
  4. Wire into `ReassignModal.vue` (Item 4). Suite green.
  5. Commit.

---

### Item 7 — `consultantOptions()` helper in `lib/ui.js` (effort S, risk Low)

- **Goal / user value** — The "on-service consultants, plus keep current if off-service, optionally narrow by specialty" rule is re-expressed four times in four different files. One function eliminates all four copies and gives the rule a name.

- **Files**
  - MODIFY: `resources/js/lib/ui.js` (add `consultantOptions` — already stubbed in Item 1)
  - MODIFY: `resources/js/Pages/Patients/Index.vue` (lines 212–216, 256)
  - MODIFY: `resources/js/Pages/Admissions/Index.vue` (lines 41, 81)
  - MODIFY: `resources/js/Pages/Registry/Index.vue` (line 83)
  - MODIFY: `resources/js/Pages/Consultations/Index.vue` (lines 65–73)

- **Design**

  ```js
  // In resources/js/lib/ui.js
  /**
   * Returns a filtered+sorted consultant list for a dropdown.
   * @param {Array}  consultants   - full list from page props (shape: {id, full_name, on_service, specialty_id})
   * @param {Object} opts
   * @param {number|null} opts.keepId        - always include this id even if off-service
   * @param {number|null} opts.specialtyId   - if set, further filter to this specialty (used in Consultations)
   * @param {boolean}     opts.onServiceOnly - if true, exclude off-service consultants (never keep keepId)
   */
  export function consultantOptions(consultants, { keepId = null, specialtyId = null, onServiceOnly = false } = {}) {
    return consultants
      .filter(c => {
        if (onServiceOnly) return c.on_service
        if (c.on_service) return specialtyId == null || c.specialty_id === specialtyId
        return !onServiceOnly && c.id === keepId
      })
      .sort((a, b) => a.full_name.localeCompare(b.full_name))
  }
  ```

  Usage in each file: replace the inline `.filter()` chain with `consultantOptions(props.consultants, { keepId: form.consultant_id })` (or with `specialtyId` for the Consultations specialty-narrow case). The resulting array is identical to the current output — this is a pure refactor.

- **Decisions & defaults**
  - `onServiceOnly=false` is the default, preserving the current behavior where the current consultant is always shown even if they have gone off-service (prevents forms from rendering an empty or mismatched selection).
  - The Consultations specialty-narrow case (`Consultations/Index.vue:65–73`) passes `specialtyId: form.consultation_to_specialty_id`. Confirm the field name against the actual Consultations form shape.
  - Sorting by `full_name` is added (currently inconsistent across files). Flag for owner: confirm alphabetical sort is acceptable for all four dropdowns.

- **Test plan**
  - Vitest: already covered in Item 1's `ui.test.js` — assert `consultantOptions` with on-service mix; assert `keepId` includes off-service consultant; assert `specialtyId` narrows correctly; assert `onServiceOnly` excludes keepId.
  - No PHPUnit changes.

- **Build sequence**
  1. `consultantOptions` already stubbed in `lib/ui.js` from Item 1. Fill implementation. Vitest tests. Green.
  2. Replace `Consultations/Index.vue:65–73`. Suite green.
  3. Replace `Registry/Index.vue:83`. Suite green.
  4. Replace `Admissions/Index.vue:41,81`. Suite green.
  5. Replace `Patients/Index.vue:212–216,256`. Suite green.
  6. Commit.

---

## Risks & sequencing notes

**Mandatory order within the wave:**

1. Item 1 (`lib/ui.js`) must land first — `localToday`, `xsrf`, `FIELD`, and `consultantOptions` are imported by every subsequent item. Wave 2's date fix also blocks on `localToday` being a single export.
2. Item 2 (`BaseModal.vue`) must land before Items 3 and 4 — both use it as the modal shell.
3. Item 5 (`PatientFlags.vue` / `DxChips.vue`) must land before Item 4 (`AdmissionSummary.vue` uses `DxChips`).
4. Item 6 (`useHandover.js`) must land before Items 4's `HandoverModal` and `ReassignModal`.
5. Item 7 can be done in parallel with Items 5–6 once Item 1 is complete.
6. Item 3 (`PatientForm.vue`) can proceed in parallel with Items 5–7; it depends on Item 2 for `BaseModal`.
7. Item 4 (split `Index.vue`) is last — it depends on Items 2, 3, 5, and 6.

**Cross-wave dependencies:**

- Wave 2's "date fix" item imports `localToday` from `lib/ui.js` — Wave 3 Item 1 must be merged first, or Wave 2 must land after Wave 3. Coordinate branch merge order.
- `AdmissionSummary.vue` (Item 4) is a natural host for any future Wave 4 "patient detail panel" feature — design it as a display-only read component with no action wiring inside it.

**Risk notes:**

- Item 3 (`PatientForm.vue`) carries the highest risk because it touches three pages' submit paths. Mitigate with the PHPUnit Feature tests for each page's modify endpoint running before and after each step.
- `BaseModal.vue` uses `$.uid` for unique IDs. In Vue 3.5+ the `useId()` composable is preferred. If the project targets Vue < 3.5, `$.uid` via `getCurrentInstance().uid` is the fallback; document this in the component.
- `Patients/Index.vue` currently has inline `<script>` event-wiring that may reference local variables from the component scope. During the split (Item 4), audit every `emit` and `ref` that `ActionModal` and `ReassignModal` need — ensure they receive everything via props rather than relying on parent-scope closure. Missed prop threads are the most common split-refactor bug.
- After Item 4, `Index.vue` importing four new components increases the JS bundle for the patients page. Confirm Vite's chunk splitting does not regress `public/build` asset sizes beyond the team's tolerance (no lazy loading needed — these are all above-the-fold interactive components).
- The `app.css` `.field` utility (Item 1) must be added before the Tailwind build step. Since `public/build` assets are committed, rebuild and commit after Item 1 lands, before any other item's CSS depends on it.
