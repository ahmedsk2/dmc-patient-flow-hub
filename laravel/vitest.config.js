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
        // baseline of 2026-09-03 (lines 77.5 · branches 78.8 · functions 50.7 over 723 tests) — they
        // stop coverage from silently eroding; raise them as coverage grows, never lower them to
        // pass a build. Enforced only with `--coverage` (the CI frontend job); plain `vitest run`
        // is unchanged.
        coverage: {
            provider: 'v8',
            include: ['resources/js/**/*.{js,vue}'],
            exclude: ['resources/js/**/__tests__/**', 'resources/js/**/*.{spec,test}.js'],
            reporter: ['text-summary'],
            thresholds: { lines: 75, statements: 75, branches: 76, functions: 48 },
        },
    },
    resolve: {
        alias: { '@': resolve(__dirname, 'resources/js') },
    },
});
