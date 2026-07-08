import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// Kept SEPARATE from vite.config.js so vitest/config (and the Vue test plugin) never enter the
// production Vite build. The `@` alias mirrors vite.config.js's resolve alias so test imports of
// `@/...` resolve the same way the app does.
export default defineConfig({
    // Mirrors vite.config.js's transformAssetUrls override: without it, @vitejs/plugin-vue falls
    // back to its build-mode default (includeAbsolute: true) here because vitest never fires the
    // plugin's configureServer hook, so an absolute `<img src="/images/...">` gets rewritten into
    // a module import and Vite's import-analysis plugin throws when the file doesn't exist yet.
    // Production intentionally allows dropping such assets in post-build with no rebuild — tests
    // must not require them to exist either.
    plugins: [vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } })],
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.{test,spec}.{js,mjs,ts}'],
    },
    resolve: {
        alias: { '@': resolve(__dirname, 'resources/js') },
    },
});
