# EHC brand assets — provenance, palette, and the enforced contrast record

This file records **what was actually retrieved, from where, when, and what was decided** — plus the
colour rules the UI is held to. It is a provenance record, not a design brief. Nothing in it is
aspirational: every ratio below is produced by `laravel/scripts/contrast.mjs`, which is a required
CI step.

> **This file is not scanned by Tailwind.** `resources/css/app.css` pins the scan set with
> `@import 'tailwindcss' source(none)` plus four explicit `@source` globs (lines 50–55): the client
> under `resources/js`, its Blade views, and Laravel's vendored pagination views. `public/**` is
> named in none of them. Utility class names spelled in prose below are therefore inert here — which
> is *not* true of a real source file (see Rule 5).

---

## 1. Logo

### 1.1 Source and authorization

| | |
|---|---|
| Official site | `https://www.ehc.med.sa/` |
| Retrieved | 2026-07-08 |
| HTTP status | `200` (307,837 bytes) |
| Method | One unauthenticated `GET` of the public homepage, then direct `GET`s of the asset URLs it links |
| Authorization | Directed by the project maintainer (the department's own maintainer) |

No credentials were entered, no CAPTCHA solved, no access control bypassed, and the site was not
crawled. The Eastern Health Cluster mark is **copyrighted**.

### 1.2 What the homepage yielded

Verbatim output of the candidate scan:

```
href="https://www.ehc.med.sa/wp-content/uploads/2025/02/logo-150x150.png"
href="https://www.ehc.med.sa/wp-content/uploads/2025/02/logo-300x300.png"
href="https://www.ehc.med.sa/wp-content/uploads/2025/02/logo-300x300.png"
src="https://www.ehc.med.sa/wp-content/uploads/2025/03/Logo-EHC-1.svg"
src="https://www.ehc.med.sa/wp-content/uploads/2025/04/Logo-EHC-1.svg"
```

The page also links `AAHRPP-Logo.png`, `American_Heart_Association_Logo.png`,
`HACCP-Certification-Logo.png` and `GHR-Logo1.png` — third-party accreditation marks, not EHC's, and
not candidates.

Each EHC candidate was downloaded to a scratch directory and inspected (the SVG was rasterized
locally with headless Chrome to confirm what it depicts):

| Asset | Format | Dimensions | What it actually is | Verdict |
|---|---|---|---|---|
| `2025/03/Logo-EHC-1.svg`, `2025/04/Logo-EHC-1.svg` | SVG | 885 × 197 | **Horizontal lockup** — square star mark on the left, "Eastern Health Cluster" + "HEALTH HOLDING CO." wordmark to the right, as 188 outlined `<path>`s behind Illustrator clip-paths | Rejected |
| `2025/02/logo.png` | PNG, RGBA, transparent | 512 × 512 | **The square star mark** | Not committed — see 1.3 |
| `2025/02/cropped-logo.png` | PNG, RGBA, transparent | 512 × 512 | Same mark (site-icon crop) | Not committed |
| `2025/02/logo-300x300.png` | PNG, RGBA, transparent | 300 × 300 | Same mark, smaller (favicon) | Not committed |

The two SVG URLs are **byte-identical** (one asset, two upload paths).

```
sha256  c2dbfd2b2ff125ad44a36927dda434680b5b5ed526ac75e065328b7e51aefac1  Logo-EHC-1.svg (both paths)
sha256  a7e3c87f94e91df1b7f01f8b61e0546d5fc193467711afb672c050e28bb1c9f1  logo.png
sha256  888e9241a699fedaf9bfd3b7005630d4807401bb154a893e606d17a00a5dd01d  cropped-logo.png
sha256  36f61b942c29eaf20d10fce1e190c7ac01e01b7e8f37d4730afd6aae05c01cf2  logo-300x300.png
```

### 1.3 Outcome: **no official asset was committed**

Neither `ehc-logo.svg` nor `ehc-logo-mono.svg` exists in this directory. Three reasons, in order:

1. **The only vector is the lockup, not the mark.** `README.txt` requires the square star mark
   alone — the bilingual wordmark already appears as live text in the UI, and an 885 × 197 asset
   cannot render in a square 28px chip. Extracting the mark from the lockup would mean cropping a
   copyrighted, clip-path'd, 188-path Illustrator export and guessing its bounding box. That is
   reconstruction, and it was explicitly out of bounds. It was not done.
2. **The only square mark is a raster, and the mono variant cannot be derived from one.**
   `ehc-logo-mono.svg` exists to paint in `currentColor`, which requires vector fills. There is no
   raster equivalent.
3. **The remaining choice is not an engineering one.** Committing a copyrighted mark as a raster,
   and accepting a mixed-provenance set (official raster for colour, in-repo recreation for mono),
   is a brand decision for the maintainer.

**No mark was reconstructed, approximated, traced, redrawn, or fabricated.**

### 1.4 Fallback behaviour (why the app is never blocked)

`resources/js/Components/EhcLogo.vue` renders `<img :src>` and, on `@error`, flips to an inline SVG
recreation of the EHC five-point flame star with its central medallion. Both asset URLs currently
404, so **every call site renders the recreation.**

All six production call sites use the **colour** variant — `AppLayout.vue` (sidebar chip),
`Login.vue` (×2), `Register.vue`, `ForgotPassword.vue`, `ResetPassword.vue`. The `mono` prop has
**no production call site today**; it is exercised only by `EhcLogo.spec.js`.

The recreation is deliberately **blue** (`#2f97c4` → `#1f86bf` → `#0e6fa6`), faithful to the real
mark — not the teal of the surrounding chrome. See §2.

### 1.5 Drop-in rule

Save the official **square mark only** (transparent background, no wordmark) as:

```
laravel/public/images/ehc-logo.svg
```

The whole app picks it up with **no code change and no rebuild**. That zero-code path is why `src`
was left pointing at `.svg`.

- **If a raster is approved instead**, the verified-clean candidate is
  `https://www.ehc.med.sa/wp-content/uploads/2025/02/logo.png` (512 × 512, RGBA, transparent,
  sha256 `a7e3c87f…`). Using it requires changing `src` in
  `resources/js/Components/EhcLogo.vue` **and** the literal string asserted in
  `resources/js/Components/__tests__/EhcLogo.spec.js`.
- **For `ehc-logo-mono.svg`**: derive it from a *vector* mark by replacing every `fill` and
  `stop-color` with `currentColor` and deleting the `<linearGradient>` defs. It cannot be produced
  from a raster. Because `mono` paints in `currentColor`, the **caller owns the contrast** (Rule 7).

---

## 2. Sampled palette

There are **two** palettes here. Do not conflate them.

### 2a. App chrome — authoritative for the UI

Sampled from `resources/css/app.css`. **Provenance: a prior renovation wave — this is not a fresh
sample from any EHC asset.**

| Token | Hex | Role |
|---|---|---|
| `brand-200` | `#a9ded8` | light aqua |
| `brand-500` | `#009ca6` | primary teal |
| `brand-700` | `#00727b` | body-text teal (light theme) |
| `brand-800` | `#00565e` | deep teal |
| `surface-app` | `#f1f6f6` | page background |
| `surface-card` | `#ffffff` | elevated card |

In dark theme `brand-700` / `brand-800` are re-declared as `#7accc9` / `#a9ded8` (light ink on dark
chrome); the row that certifies dark chrome is `brand-400 on dark card` at 6.70:1.

### 2b. The official lockup — retrieved, inspected, **not committed**

Every hex literal in `Logo-EHC-1.svg`, by frequency. Recorded for reference only; no shipped asset
uses these.

```
  75  #2490cc      11  #1f91cf       6  #243d82       5  #1d83c3
  37  #51938b       8  #077690       6  #1a93d1       5  #0c549e
  30  #2fa8df       7  #9cca3b       6  #00a57c       5  #0a5aa5
                    6  #3dc0c3       5  #ffffff       5  #000000
```

Its five `<linearGradient>`s (one per petal) each run `#2fa8df` → `#1d83c3` → `#0a5aa5`; `#ffffff`
and `#000000` appear as Illustrator mask/clip stops.

**The mark is blue** (`#2490cc` dominant); the app's `brand-500` is **teal** (`#009ca6`). This
divergence is intentional and pre-existing. The green flecks (`#9cca3b`, `#00a57c`, `#3dc0c3`) are
the Eastern Province map rendered inside the medallion.

---

## 3. Contrast record

**This record is enforced, not aspirational.** `scripts/contrast.mjs` is wired into
`.github/workflows/laravel-ci.yml` as a required step (`npm run contrast`). It first self-validates
its CIEDE2000 implementation against the 34 Sharma–Wu–Dalal (2005) reference pairs, then
`process.exit(1)`s on **any** non-`KNOWN` WCAG failure, **any** perceptual-distance pair below its
floor, or a broken CIEDE2000 implementation. A colour-token edit that regresses contrast cannot
merge.

Every hex the script tests was checked against the shipped tokens in `resources/css/app.css` (light
and dark) and matches exactly — the record measures the real app, not a stale copy.

**To regenerate:** `cd laravel && node scripts/contrast.mjs` (or `npm run contrast`). Paste the
output below verbatim.

Verbatim output, 2026-07-08:

```text
--- CIEDE2000 self-test (Sharma, Wu & Dalal 2005 reference data, 34 pairs) ---
  # 1  got 2.0425  expected 2.0425  err 4.03e-5  OK
  # 2  got 2.8615  expected 2.8615  err 1.02e-5  OK
  # 3  got 3.4412  expected 3.4412  err 9.40e-6  OK
  # 4  got 1.0000  expected 1.0000  err 1.14e-6  OK
  # 5  got 1.0000  expected 1.0000  err 4.70e-6  OK
  # 6  got 1.0000  expected 1.0000  err 1.30e-5  OK
  # 7  got 2.3669  expected 2.3669  err 4.12e-5  OK
  # 8  got 2.3669  expected 2.3669  err 4.12e-5  OK
  # 9  got 7.1792  expected 7.1792  err 2.80e-5  OK
  #10  got 7.1792  expected 7.1792  err 3.74e-5  OK
  #11  got 7.2195  expected 7.2195  err 2.78e-5  OK
  #12  got 7.2195  expected 7.2195  err 2.58e-5  OK
  #13  got 4.8045  expected 4.8045  err 2.17e-5  OK
  #14  got 4.8045  expected 4.8045  err 2.45e-5  OK
  #15  got 4.7461  expected 4.7461  err 2.89e-5  OK
  #16  got 4.3065  expected 4.3065  err 1.79e-5  OK
  #17  got 27.1492  expected 27.1492  err 3.13e-5  OK
  #18  got 22.8977  expected 22.8977  err 7.53e-6  OK
  #19  got 31.9030  expected 31.9030  err 4.65e-6  OK
  #20  got 19.4535  expected 19.4535  err 2.14e-5  OK
  #21  got 1.0000  expected 1.0000  err 2.63e-5  OK
  #22  got 1.0000  expected 1.0000  err 2.71e-5  OK
  #23  got 1.0000  expected 1.0000  err 4.95e-5  OK
  #24  got 1.0000  expected 1.0000  err 3.48e-5  OK
  #25  got 1.2644  expected 1.2644  err 2.00e-5  OK
  #26  got 1.2630  expected 1.2630  err 4.07e-5  OK
  #27  got 1.8731  expected 1.8731  err 2.95e-5  OK
  #28  got 1.8645  expected 1.8645  err 4.77e-6  OK
  #29  got 2.0373  expected 2.0373  err 4.17e-5  OK
  #30  got 1.4146  expected 1.4146  err 2.21e-5  OK
  #31  got 1.4441  expected 1.4441  err 2.91e-5  OK
  #32  got 1.5381  expected 1.5381  err 1.70e-5  OK
  #33  got 0.6377  expected 0.6377  err 2.77e-5  OK
  #34  got 0.9082  expected 0.9082  err 3.28e-5  OK
34/34 reference pairs match (tolerance 0.001).
brand-500 on white                   #009ca6 on #ffffff    3.33:1  UI / large text only
brand-700 on white                   #00727b on #ffffff    5.69:1  AA text
brand-800 on white                   #00565e on #ffffff    8.42:1  AA text
warning-500 on white                 #e69209 on #ffffff    2.48:1  FAIL  KNOWN
warning-500 on warning-100           #e69209 on #fdedd2    2.15:1  FAIL  KNOWN
danger-600 on danger-100             #c1302d on #fbdcdc    4.39:1  UI / large text only
success-600 on success-100           #15803d on #d8f5e3    4.32:1  UI / large text only
info-500 on info-100                 #2f7fe0 on #d7e9fb    3.24:1  UI / large text only
on-info on tint-info                 #1b5cad on #d7e9fb    5.32:1  AA text
on-warning on tint-warning           #8a5a00 on #fdedd2    5.14:1  AA text
on-danger on tint-danger             #a82824 on #fbdcdc    5.47:1  AA text
on-success on tint-success           #11672f on #d8f5e3    6.02:1  AA text
on-info on tint-info (dark)          #9cc4f5 on #12293f    8.22:1  AA text
on-warning on tint-warning (dark)    #f0c073 on #3a2a09    8.25:1  AA text
on-danger on tint-danger (dark)      #f4a6a4 on #3a1a19    8.08:1  AA text
on-success on tint-success (dark)    #86d6a3 on #10291a    8.98:1  AA text
accent-600 on accent-300/40 over card (BASELINE, light) #b9842a on #faf0d9    2.90:1  FAIL  KNOWN
accent-600 on accent-300/40 over card (BASELINE, dark) #b9842a on #6d6a53    1.67:1  FAIL  KNOWN
accent-600 on white (BASELINE plain, light) #b9842a on #ffffff    3.28:1  UI / large text only
on-accent on tint-accent             #4f5314 on #eef0d6    7.00:1  AA text
on-accent on card                    #4f5314 on #ffffff    8.14:1  AA text
on-accent on app surface             #4f5314 on #f1f6f6    7.46:1  AA text
on-accent on tint-accent (dark)      #cdd48a on #262a10    9.42:1  AA text
on-accent on card (dark)             #cdd48a on #13201f   10.67:1  AA text
on-accent on app surface (dark)      #cdd48a on #0c1416   11.87:1  AA text
white on success-700 (hover fill)    #ffffff on #166534    7.13:1  AA text
white on danger-700 (hover fill)     #ffffff on #9f2724    7.53:1  AA text
navy-950 on warning-500 (rest fill)  #00252a on #e69209    6.53:1  AA text
navy-950 on warning-600 (hover fill) #00252a on #d58506    5.54:1  AA text
white on danger-600 (rest fill)      #ffffff on #c1302d    5.63:1  AA text
on-success on success-200 (hover)    #11672f on #c2efd3    5.52:1  AA text
on-success on success-200 (hover, dark) #86d6a3 on #18402a    6.74:1  AA text
white on success-600 (rest)          #ffffff on #15803d    5.02:1  AA text
white on success-600 at 90% opacity over card (HISTORICAL FAIL) #ffffff on #2c8d50    4.17:1  UI / large text only
danger-200 on nav chip over navy-900 #f4a6a4 on #273237    6.78:1  AA text
on-danger on nav chip over navy-900 (the trap) #a82824 on #273237    1.87:1  FAIL  KNOWN
ink-700 on app                       #344145 on #f1f6f6    9.68:1  AA text
brand-400 on dark card               #38b4ba on #13201f    6.70:1  AA text

0 pair(s) below 3:1 (excluding KNOWN historical/trap rows).

--- perceptual distance: CIEDE2000 (verdict) + CIE76 (reference/comparison) ---
TEXT  on-accent vs on-warning                              #4f5314 vs #8a5a00  dE00  19.36  dE76  30.24  (floor 10)
TEXT  on-accent vs on-warning (dark)                       #cdd48a vs #f0c073  dE00  16.29  dE76  23.87  (floor 10)
TEXT  (HISTORICAL TRAP) old warm-gold on-accent vs on-warning #6b4a08 vs #8a5a00  dE00   8.18  dE76  14.15  (floor 10)  KNOWN TOO CLOSE
FILL  tint-accent vs tint-warning                          #eef0d6 vs #fdedd2  dE00   7.93  dE76   6.75
FILL  tint-accent vs tint-warning (dark)                   #262a10 vs #3a2a09  dE00  11.74  dE76  12.32
HOVER warning-600 vs warning-500                           #d58506 vs #e69209  dE00   4.36  dE76   6.55  (floor 3)
HOVER success-700 vs success-600                           #166534 vs #15803d  dE00   9.41  dE76  14.86  (floor 3)
HOVER danger-700 vs danger-600                             #9f2724 vs #c1302d  dE00   7.30  dE76  12.31  (floor 3)

0 pair(s) below their CIEDE2000 perceptual floor (excluding KNOWN historical/trap rows).

contrast gate: PASS (exit 0)
```

---

## 4. Rules this establishes

Each rule below is **measured**, not asserted. Several were live defects fixed during this wave. The
line references are to the verbatim output in §3.

### Rule 1 — `brand-500` is not a text colour

`brand-500` (`#009ca6`) is **3.33:1** on white — legal for **fills, borders, and large text only**.
Body text and links must use **`brand-700`** (`#00727b`, **5.69:1**) or **`brand-800`**
(`#00565e`, **8.42:1**). Both ratios are against white (`bg-card`, light theme).

### Rule 2 — raw status colours fail AA as text

The raw `*-500` / `*-600` status colours **fail AA as text**, including on their own tints:

| Pairing | Ratio | Verdict |
|---|---|---|
| `warning-500` on `warning-100` | 2.15:1 | FAIL |
| `warning-500` on white | 2.48:1 | FAIL |
| `info-500` on `info-100` | 3.24:1 | below AA text |
| `success-600` on `success-100` | 4.32:1 | below AA text |
| `danger-600` on `danger-100` | 4.39:1 | below AA text |

None reaches 4.5:1. Always use the `on-info` / `on-warning` / `on-danger` / `on-success` /
`on-accent` tokens for status **text**, paired with the matching `tint-*` surface. **Both themes are
verified:** light 5.32 / 5.14 / 5.47 / 6.02 / 7.00; dark 8.22 / 8.25 / 8.08 / 8.98 / 9.42.

The raw steps remain correct as **fills** — the pair that matters there is the fill against its own
label (`navy-950` on `warning-500` = 6.53:1; white on `danger-600` = 5.63:1).

### Rule 3 — status is never conveyed by colour alone

Pair every status with an **icon and a text label**. `resources/js/Components/FlowAlert.vue` is the
reference implementation.

### Rule 4 — never put `opacity-*` on text over a tinted fill

CSS `opacity` composites the **whole element** over its backdrop, label included. At 90% the light
pairs degrade to:

| Pairing | 100% | 90% | 14px AA (4.5:1) |
|---|---|---|---|
| `on-info` on `tint-info` | 5.32:1 | **4.41:1** | fails |
| `on-warning` on `tint-warning` | 5.14:1 | **4.21:1** | fails |
| `on-danger` on `tint-danger` | 5.47:1 | 4.67:1 | passes |
| `on-success` on `tint-success` | 6.02:1 | 4.89:1 | passes |

Two of the four survive — but the rule is **absolute**, because it is the *pairing* that decides, not
the token, and nothing in the build stops a safe token from being used on an unsafe backdrop.

These four rows are **derived** from the light `on-*` / `tint-*` rows in §3; `contrast.mjs` does not
currently assert them directly. The one opacity row it *does* carry is the shipped historical
regression: `white on success-600 at 90% opacity over card` = **4.17:1**, down from a 5.02:1 rest
state. WCAG 1.4.3 exempts `disabled`; it never exempts `hover`. Real hover **fills** replaced the
opacity fades.

### Rule 5 — never spell a resolvable Tailwind utility in a comment or string anywhere Tailwind scans

Tailwind v4's extractor reads **raw bytes**: prose, docblocks, string literals, test fixtures. A real
rule, `.top-5`, once shipped into production CSS from the phrase *"top-5 diagnoses"* in a
`StatisticsController` docblock. Others shipped from an error string and from `not.toContain(...)`
regression guards.

Sources are now allow-listed: `@import 'tailwindcss' source(none)` plus explicit `@source` entries.
`resources/js/**/__tests__` is subtracted with an explicit `@source not`; `app/`, `routes/`, `docs/`
and `scripts/` are excluded **by omission** — under `source(none)` nothing is scanned unless named.
`scripts/check-source-allowlist.mjs` snapshots the class set the build actually emits and fails CI on
drift in **either** direction, which is how a loosened allow-list gets caught.

This file lives in `public/images/`, which is named in no `@source`, so the utility names above are
inert **here**. Verify before relying on that anywhere else.

### Rule 6 — contrast ratios presume a fully painted pixel

A ratio describes two solid colours. An antialiased sub-pixel stroke never paints either of them.
`EhcLogo`'s medallion ring is `stroke-width="1.4"` in a `0 0 100 100` viewBox, so it renders at:

| Rendered size | Call site | Stroke |
|---|---|---|
| 28px | `AppLayout.vue` sidebar chip | **0.392 CSS px** |
| 32px | the four auth pages | 0.448 CSS px |

Sub-pixel at every real size. Its nominal contrast ratio is **not** what renders. Tracked as a
separate task; deliberately not fixed here.

### Rule 7 — `mono` paints in `currentColor`, so the caller owns the contrast

`EhcLogo`'s `mono` variant cannot know what it sits on. The caller must set an inherited `color` that
contrasts with the caller's own backdrop.

`AppLayout`'s logo chip is `bg-card` (`#ffffff` light, `#13201f` dark), which is exactly why it
correctly uses the **colour** variant: `currentColor` there would inherit the surrounding aside's
`text-navy-100` (`#cfe9e7`), giving **1.28:1 on white** — no visible glyph at all. (On the dark card
the same colour is 13.13:1; the failure is light-theme-only, which is what makes it easy to miss.)

`mono` is for callers that set an appropriate `color` themselves — dark chrome, or solid black in
print.
