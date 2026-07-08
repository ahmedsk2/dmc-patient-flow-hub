# EHC UI — Wave 0 verification record

**Commit at verification:** `b5c8922` (branch `laravel-replatform`)
**Date:** 2026-07-08
**Scope:** Wave 0 — brand foundation, "Census Board" tokens, `FlowAlert`, `EhcLogo` mono, `/style-guide`.

This file is the **W0-T8 deliverable**. The plan called for two PNG screenshots of `/style-guide`.
The preview screenshot tool could **not** capture this page in the local environment (six 30s
timeouts against `php artisan serve` + an Inertia SPA — the renderer never reached the tool's
"stable" state, even with all animations cancelled and the guided tour dismissed). The page itself
renders correctly: the accessibility snapshot, DOM inspection, and computed-style reads all succeed,
and the network log shows every asset returning `200` with no hanging request. Per the preview
guidance, **`preview_inspect` / computed styles are the authoritative method for verifying colours,
contrast, and tokens** (more accurate than a JPEG), so the visual check below is recorded as measured
token values and live WCAG ratios rather than pixels. Anyone with WAMP up can view the live page —
see **How to view live** at the bottom.

---

## 1. Automated gates — all green

| Gate | Command | Result |
|---|---|---|
| Build reproducibility | `npm run build` then `git status public/build` | **Byte-identical** to committed `public/build` (clean tree) — deploy host needs no Node; W0-T3c fix holds |
| WCAG + perceptual contrast | `npm run contrast` | **PASS (exit 0)** — 0 pairs below 3:1 (excl. KNOWN traps); 0 below CIEDE2000 floor |
| Shipped-selector drift | `npm run check-allowlist` | **PASS** — 770 classes emitted, 770 in snapshot, no drift |
| Vue component suite | `npx vitest run` | **255 passed / 31 files** |
| PHPUnit pass 1 | `artisan test --exclude-group pdf` | **507 passed / 3478 assertions** |
| PHPUnit pass 2 (dompdf isolated) | `artisan test --group pdf` | **56 passed / 216 assertions** |

**Total: 818 tests green** (507 + 56 PHPUnit, 255 Vitest), plus 3 static gates.

### Contrast gate — the olive-vs-amber decision, proved from both sides
The accent was rotated to **olive-gold** so accent-as-text no longer collides with the amber
`warning` token. The gate measures perceptual distance with CIEDE2000 (verdict) and flags the old
warm-gold as a regression trap:

```
TEXT  on-accent vs on-warning              #4f5314 vs #8a5a00  dE00 19.36  (floor 10)   PASS
TEXT  on-accent vs on-warning (dark)       #cdd48a vs #f0c073  dE00 16.29  (floor 10)   PASS
TEXT  (HISTORICAL TRAP) old warm-gold ...  #6b4a08 vs #8a5a00  dE00  8.18  (floor 10)   KNOWN TOO CLOSE
```
The trap sits at 8.18 (below the floor) and is pinned as KNOWN — i.e. the gate **bites** if the
accent ever regresses toward amber.

---

## 2. Live browser verification — `/style-guide`, both themes

Driven in a real browser on `php artisan serve` :8001, logged in as an admin. Theme forced via
`prefers-color-scheme` emulation + reload (the app resolves "system" on load).

### 2a. Structure renders (accessibility snapshot)
All sections present and labelled: **Brand** (colour + mono `EhcLogo`), **Brand scale** 50–900 with
theme-aware markers, **Status rail** (5 tones), **Flow alerts** (3 tones), KPI numeral, buttons,
`.field`. Skip-link, breadcrumb, and theme toggle all present.

### 2b. Token values + WCAG ratios (computed live from the shipped build)

| Token pair | Dark ratio | Light ratio | Bar |
|---|---|---|---|
| `on-accent` / `tint-accent` (olive) | **9.42:1** | **7.00:1** | 4.5:1 |
| `on-warning` / `tint-warning` (amber) | **8.25:1** | **5.14:1** | 4.5:1 |
| `on-danger` / `tint-danger` | **8.08:1** | **5.47:1** | 4.5:1 |
| `on-info` / `tint-info` | **8.22:1** | **5.32:1** | 4.5:1 |
| `on-success` / `tint-success` | **8.98:1** | **6.02:1** | 4.5:1 |

Every tint/on pair clears the 4.5:1 normal-text bar in **both** themes.

**Olive vs amber distinguishability** (they must not read as the same colour): RGB Euclidean
distance **46 (dark) / 63 (light)** between `on-accent` and `on-warning`; corroborated by the
CIEDE2000 gate above (16.29 / 19.36). Distinct hues — olive is green-yellow (`#cdd48a` / `#4f5314`),
amber is orange (`#f0c073` / `#8a5a00`).

**Theme-aware `brand-50` (the Active-tile fix, W0-T3m/#150):** resolves to `#192728` under dark and
`#f0fafa` under light — no longer the light-only value that rendered the dashboard "Active" tile at
1.01:1 (invisible) in dark mode.

**Status rail — 5 distinct rendered rail colours** (dark): neutral `rgb(51,71,74)`,
info `rgb(47,127,224)`, success `rgb(22,163,74)`, warning `rgb(230,146,9)`, danger `rgb(224,65,62)`.
The three `FlowAlert` tones map onto info / warning / danger rails correctly.

### 2c. Brand logo — fallback reconstruction confirmed working end-to-end
`EhcLogo.vue` points its `<img>` at the **official** asset (`/images/ehc-logo.svg`, and
`/images/ehc-logo-mono.svg` for mono). Those assets are **deliberately not committed** (the EHC mark
is copyrighted — see `laravel/public/images/BRAND_README.md`), so both requests return `404`, the
`@error` handler fires, and the component swaps to the **inline reconstructed SVG**. The browser
confirmed the fallback: the inline `<svg>` with the medallion ring renders under the correct
`"Eastern Health Cluster"` alt text. When the hospital drops the authorized asset into that slot,
the `<img>` lights up automatically with no code change.

---

## 3. Tracked / deferred items (unchanged by this wave)

Per the "defer the debt" decision, these remain open and are **not** blockers for Wave 0:

- **#145 — logo medallion ring is sub-pixel.** Confirmed: `stroke-width="1.4"` on a 100-unit viewBox
  renders **0.39 CSS px** at the 28px header size. Cosmetic (a hairline that rounds away at small
  sizes); revisit when the official raster/SVG asset lands.
- **#147 — residual accent-as-text + a Dashboard hover-opacity glyph at 2.49:1.** The olive rotation
  resolved the accent-vs-amber collision; the remaining hover-opacity glyph is tracked for a later a11y pass.
- **#139 — contrast.mjs body-text bar** (largely satisfied by the CIEDE2000 self-validation now in place).
- **#151 — delete unrouted `welcome.blade.php`** (136 dead selectors).
- **#152 — `color-mix` fallback degrades tint alphas inconsistently.**

---

## How to view live

WAMP + MySQL are running locally. The `dmc_laravel` dev DB was empty (0 users), so a **throwaway
local-only admin** was seeded for this verification:

```
URL:      http://127.0.0.1:8001/style-guide   (php artisan serve)
Username: sgpreview
Password: StyleGuide!Local1
```

This account exists **only in the local `dmc_laravel` dev DB** (no PHI, never committed, never
deployed). Delete it when finished:

```php
php artisan tinker --execute="App\Models\User::where('username','sgpreview')->forceDelete();"
```
