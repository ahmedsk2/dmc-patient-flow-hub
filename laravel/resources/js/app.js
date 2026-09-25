import { createApp, h, defineAsyncComponent } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

// PERF-01: ChartCanvas (and with it chart.js + the datalabels plugin) is code-split into its own
// chunk and fetched only by the pages that render a chart, instead of riding in the entry bundle
// for every login and board view. The global name is unchanged, so pages keep using <ChartCanvas>.
const ChartCanvas = defineAsyncComponent(() => import('@/Components/ChartCanvas.vue'));

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

// TST-10: last-resort net for a promise nobody caught (a raw fetch() that lost the network, a
// background refresh that failed). Logged, never swallowed silently, never sent anywhere — the
// app has no telemetry sink by design (PHI). Individual call sites handle their own failures.
window.addEventListener('unhandledrejection', (event) => {
    console.error('[unhandled promise rejection]', event.reason);
});

// S1 (role walkthrough 2026-09-25): a browser can restore the ENTIRE document — DOM, JS heap and
// all — from the back/forward cache (bfcache) instead of asking the server for anything, which is
// how "sign out, then press Back" used to show the last patient page verbatim with no network
// request at all. `Cache-Control: no-store` (SecurityHeaders) stops the HTTP cache from doing this
// but does not stop bfcache in modern Chromium/Firefox/Safari. `event.persisted` is true only on a
// bfcache restore (never on a normal load), so this reload is a no-op the rest of the time; the
// reload re-runs the full auth/session/MFA/email-verify/password-expiry gate chain server-side,
// exactly as if the page had been requested fresh. Inertia's own history encryption (config
// inertia.history.encrypt) covers the narrower case of the *page data* Inertia itself keeps in
// history.state — this covers the whole rendered document, which bfcache restores independently
// of that.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        window.location.reload();
    }
});

// The per-request CSP nonce, from the meta tag app.blade.php renders. Inertia stamps it on the
// <style> elements it inserts at runtime (the navigation progress bar, and the modal it shows for a
// non-Inertia error response such as the 429 page), which is why style-src needs no inline
// allowance. Undefined when absent, so nothing changes if the meta tag is ever missing.
const cspNonce = document.querySelector('meta[name="csp-nonce"]')?.content || undefined;

createInertiaApp({
    nonce: cspNonce,
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .component('ChartCanvas', ChartCanvas)
            .mount(el);
    },
    progress: {
        color: '#009ca6', // EHC primary teal
        showSpinner: false,
    },
});
