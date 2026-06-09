EHC logo — drop-in location
===========================

Save the official Eastern Health Cluster mark here as:

    ehc-logo.svg      (preferred — crisp at any size)

…and the whole app (sidebar, login, top-bar) uses it automatically, with NO code change or
rebuild. If this file is absent, a faithful vector recreation of the EHC star is shown instead
(see resources/js/Components/EhcLogo.vue).

Guidance:
- Use the SQUARE star MARK only (transparent background) — the bilingual
  "Eastern Health Cluster / تجمع الشرقية الصحي" wordmark already appears as text in the UI.
- A PNG works too; if you use a different filename/extension, update the <img src> in EhcLogo.vue.
