import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

// Kept SEPARATE from vite.config.js so vitest/config (and the Vue test plugin) never enter the
// production Vite build. The `@` alias mirrors vite.config.js's resolve alias so test imports of
// `@/...` resolve the same way the app does.
export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.{test,spec}.{js,mjs,ts}'],
    },
    resolve: {
        alias: { '@': resolve(__dirname, 'resources/js') },
    },
});
