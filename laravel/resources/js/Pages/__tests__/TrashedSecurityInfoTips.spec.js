import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * Trashed/Index and Security/Index — 2026-09-24 role/UX review §5 info marks: neither page
 * explained what a "restored" user's active state ends up as, or what the Security tables actually
 * count. Smoke specs only — the pages themselves have no prior coverage.
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }));
vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() } }));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: vi.fn() }) }));

import Trashed from '@/Pages/Trashed/Index.vue';
import Security from '@/Pages/Security/Index.vue';

describe('Trashed/Index — restore InfoTip', () => {
    it('carries a "More information" InfoTip next to the Users heading', () => {
        const w = mount(Trashed, { props: { admissions: [], consultations: [], users: [] } });
        expect(w.find('button[aria-label="More information: Restoring a user"]').exists()).toBe(true);
    });
});

describe('Security/Index — section InfoTips', () => {
    it('carries a "More information" InfoTip on the Failed logins and First-seen IPs headings', () => {
        const w = mount(Security, { props: { failedClusters: [], firstSeenIps: [], mfaNonCompliant: [], mfaEnforcement: 0, notifyThreshold: 5 } });
        expect(w.find('button[aria-label="More information: Failed logins"]').exists()).toBe(true);
        expect(w.find('button[aria-label="More information: First-seen IPs"]').exists()).toBe(true);
    });
});
