import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// Kept SEPARATE from vite.config.js so vitest/config (and the Vue test plugin) never enter the
// production Vite build. The `@` alias mirrors vite.config.js's resolve alias so test imports of
// `@/...` resolve the same way the app does.
export default defineConfig({
    // Aligns the test config with vite.config.js so tests and production resolve assets identically.
    // Without it, @vitejs/plugin-vue falls back to its build-mode default (includeAbsolute: true),
    // because vitest never fires the plugin's configureServer hook.
    //
    // This is NOT load-bearing for EhcLogo's current dynamic `:src`: transformAssetUrls only rewrites
    // STATIC attributes (@vue/compiler-sfc bails on anything that isn't NodeTypes.ATTRIBUTE), so a
    // binding is never eligible. It guards the next person — a static `src="/images/…"` would
    // otherwise hard-fail at import-analysis in tests while passing in prod, where such assets are
    // intentionally droppable post-build with no rebuild.
    //
    // Trade-off: with includeAbsolute:false, a typo'd absolute `<img src>` no longer fails
    // import-analysis — and EhcLogo's own `@error` handler would silently swallow the 404 into its
    // fallback. The literal `src` assertion in EhcLogo.spec.js is now the only guard against that.
    plugins: [vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } })],
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.{test,spec}.{js,mjs,ts}'],
        // Coverage floor (TST-02, prod-ready 2026-09-03). Scoped to the app's own source so vendor /
        // build output never inflates or deflates the number. Thresholds sit just under the measured
        // baseline — they stop coverage from silently eroding; raise them as coverage grows, never
        // lower them to pass a build. Enforced only with `--coverage` (the CI frontend job); plain
        // `vitest run` is unchanged.
        //   2026-09-03 (723 tests): lines 77.5 · branches 78.8 · functions 50.7 → 75 / 76 / 48
        //   2026-09-03 later (751 tests, the six new form specs pulled six previously un-imported
        //   pages into the instrumented set: +92 functions, +34 statements): lines 83.3 ·
        //   branches 78.1 · functions 45.9 → lines/statements RAISED to 80, functions reset to 44
        //   because the denominator changed, not because anything lost coverage.
        //   2026-09-22 — RE-BASELINED on vitest 5 / @vitest/coverage-v8 5 (from 3). Same 757 tests,
        //   same 88 source files, same code; only the measuring engine changed. v5's AST-aware
        //   remapping counts EXECUTABLE lines and every logical branch (&&, ||, ?:, ??), where v3
        //   counted whole source lines — so the units themselves moved: lines 10,155 → 3,744,
        //   statements 10,155 → 5,093, branches 2,508 → 5,359, functions 719 → 1,710. Measured
        //   under v5: lines 73.3 · statements 67.0 · branches 62.7 · functions 48.7 → floors
        //   71 / 65 / 60 / 46, the same "a couple of points under the baseline" rule as above.
        //   This is a new ruler, not a lower bar: v3 also OVERSTATED coverage. IcdTypeahead.vue and
        //   ActivityPanel.vue read 100% under v3 although no spec ever loads them (every spec
        //   vi.mock()s both); v5 reports them at 8% and 20%. They are the two genuinely untested
        //   components, and the obvious place to raise these floors from.
        coverage: {
            provider: 'v8',
            include: ['resources/js/**/*.{js,vue}'],
            exclude: ['resources/js/**/__tests__/**', 'resources/js/**/*.{spec,test}.js'],
            reporter: ['text-summary'],
            thresholds: { lines: 71, statements: 65, branches: 60, functions: 46 },
        },
    },
    resolve: {
        alias: { '@': resolve(__dirname, 'resources/js') },
    },
});
