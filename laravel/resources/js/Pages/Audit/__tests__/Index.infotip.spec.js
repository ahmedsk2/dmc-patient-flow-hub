import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

// UX-review §5 (2026-09-24): the "Hash chain intact through {date}" badge is the page's core trust
// signal but had no explanation of the mechanism. This gate checks the info mark is present when
// the badge shows, and absent from the "No integrity data yet" fallback.
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a href="#"><slot /></a>' },
    router: { get: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><main><slot /></main></div>' },
}));

import AuditIndex from '@/Pages/Audit/Index.vue';

const props = (over = {}) => ({
    logs: { data: [], total: 0, from: 0, to: 0, last_page: 1, links: [] },
    filters: {}, actors: [], entityTypes: [], categories: [],
    integrityThrough: '2026-09-02T21:00:00Z',
    ...over,
});

let wrappers = [];
const mountAttached = (p = props()) => {
    const host = document.createElement('div');
    document.body.appendChild(host);
    const w = mount(AuditIndex, { props: p, attachTo: host });
    wrappers.push({ w, host });
    return w;
};
afterEach(() => {
    for (const { w, host } of wrappers) { w.unmount(); host.remove(); }
    wrappers = [];
});

describe('Audit/Index — "Hash chain intact" info mark', () => {
    it('shows an info mark next to the badge when integrity data is present', () => {
        const w = mountAttached();
        expect(w.text()).toContain('Hash chain intact through');
        expect(w.find('button[data-infotip]').exists()).toBe(true);
    });

    it('has no info mark (and no badge) when there is no integrity data yet', () => {
        const w = mountAttached(props({ integrityThrough: null }));
        expect(w.text()).toContain('No integrity data yet');
        expect(w.find('button[data-infotip]').exists()).toBe(false);
    });
});
