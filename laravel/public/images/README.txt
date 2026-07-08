EHC logo — drop-in location
===========================

EhcLogo.vue reads TWO files from this directory. Neither is present right now, so every call site
renders the built-in vector recreation of the EHC star (see resources/js/Components/EhcLogo.vue).

    ehc-logo.svg        colour variant  — used by every production call site today
    ehc-logo-mono.svg   mono variant    — currentColor; no production call site today

Drop either file in and the app uses it automatically, with NO code change and NO rebuild.

Guidance:
- Use the SQUARE star MARK only (transparent background) — the bilingual
  "Eastern Health Cluster / تجمع الشرقية الصحي" wordmark already appears as text in the UI.
  The mark-and-wordmark LOCKUP published on ehc.med.sa is 885x197 and is NOT suitable: it would
  duplicate the wordmark and cannot render in the square 28px sidebar chip.
- ehc-logo-mono.svg must be derived from a VECTOR mark: replace every fill/stop-color with
  "currentColor" and delete the <linearGradient> defs. It cannot be produced from a raster.
  Because it paints in currentColor, the CALLER owns the contrast — it must set an inherited
  `color` that contrasts with the caller's own backdrop. This is why AppLayout's bg-card logo
  chip correctly uses the colour variant instead.
- A PNG works for the colour variant, but it is NOT a zero-code drop-in: you must update the
  <img src> in resources/js/Components/EhcLogo.vue AND the literal string asserted in
  resources/js/Components/__tests__/EhcLogo.spec.js. Prefer SVG so neither needs touching.

Provenance, the sampled palette, and the enforced WCAG/CIEDE2000 contrast record for the whole
brand system live in BRAND_README.md, in this directory. Read it before changing any colour token.
