import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import VueApexCharts from 'vue3-apexcharts';

// Wave 2, Item 10: onboarding tour. driver.js is bundled (self-hosted — no CDN, PHI-safe). Its base
// CSS is themed to EHC tokens in resources/css/app.css (.dark + prefers-reduced-motion blocks).
import 'driver.js/dist/driver.css';

// Self-hosted fonts (NO external CDN — PHI privacy; must work offline).
// Body: Instrument Sans (400/500/600/700). Display: Hanken Grotesk (600/700/800) for the
// brand wordmark + big KPI numerals.
import '@fontsource/instrument-sans/400.css';
import '@fontsource/instrument-sans/500.css';
import '@fontsource/instrument-sans/600.css';
import '@fontsource/instrument-sans/700.css';
import '@fontsource/hanken-grotesk/600.css';
import '@fontsource/hanken-grotesk/700.css';
import '@fontsource/hanken-grotesk/800.css';

const appName = 'DMC Internal Medicine';

// 419 Page Expired (stale session/CSRF after sitting on a page) — a hard reload onto the login
// screen beats Inertia's modal of the framework error page
router.on('invalid', (event) => {
    if (event.detail.response?.status === 419) {
        event.preventDefault();
        window.location.href = '/login';
    }
});

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(VueApexCharts)
            .mount(el);
    },
    progress: {
        color: '#009ca6', // EHC primary teal
        showSpinner: false,
    },
});
