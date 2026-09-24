import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

// UX-review #8 (2026-09-24): Dashboard/Statistics and Recent Activity count "discharges" two
// different ways for the same day (population, not just wording — see RecentController vs
// StatisticsController::index). This gate checks the page now says, in plain words, what its own
// discharge list counts.
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 1, is_admin: true } } } }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title'], template: '<div><main><slot /></main></div>' },
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: vi.fn() }) }));

import RecentIndex from '@/Pages/Recent/Index.vue';

const props = { discharges: [], signoffs: [], since: '2026-09-23' };

let wrappers = [];
const mountAttached = () => {
    const host = document.createElement('div');
    document.body.appendChild(host);
    const w = mount(RecentIndex, { props, attachTo: host });
    wrappers.push({ w, host });
    return w;
};
afterEach(() => {
    for (const { w, host } of wrappers) { w.unmount(); host.remove(); }
    wrappers = [];
});

describe('Recent/Index — "Discharges" scope info mark (UX-review #8)', () => {
    it('adds an info mark next to the Discharges/Sign-offs tabs explaining what counts', () => {
        const w = mountAttached();
        const tip = w.find('button[data-infotip]');
        expect(tip.exists()).toBe(true);
        expect(tip.attributes('aria-label')).toBe('More information: What Discharges counts');
    });

    // review fix-up: the tip must track the active tab, not always describe Discharges
    // while the Sign-offs tab is selected.
    it('switches the info mark to describe Sign-offs once that tab is active', async () => {
        const w = mountAttached();
        const signoffsTab = w.findAll('button').find((b) => b.text().startsWith('Sign-offs'));
        await signoffsTab.trigger('click');
        const tip = w.find('button[data-infotip]');
        expect(tip.attributes('aria-label')).toBe('More information: What Sign-offs counts');
        expect(tip.attributes('aria-label')).not.toContain('Discharges');
    });
});
