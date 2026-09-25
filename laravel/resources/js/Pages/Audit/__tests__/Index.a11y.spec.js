import { describe, it, expect, vi, afterEach, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { axe } from 'vitest-axe';
import * as axeMatchers from 'vitest-axe/matchers';

// UX-04 (engineering closeout): Audit/Index's date-range filters ("From"/"To") had bare sibling
// <label>s with no for/id pairing, and three text filters (Action, Entity ID, IP address) had no
// accessible name at all (placeholder-only, unlike their aria-labelled siblings). This gate mounts
// the real page and (1) hands it to axe-core, (2) checks every label[for] resolves to a control,
// (3) checks the three previously-unlabelled inputs now carry an aria-label.
expect.extend(axeMatchers);

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a href="#"><slot /></a>' },
    router: { get: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: {
        name: 'AppLayout',
        props: ['title', 'breadcrumbs'],
        template: '<div><header><h1>{{ title }}</h1></header><main id="main-content" tabindex="-1"><slot /></main></div>',
    },
}));

import AuditIndex from '@/Pages/Audit/Index.vue';

// jsdom has no canvas; axe's colour-contrast probe logs a "Not implemented" error per audit
// (noise — contrast is scripts/contrast.mjs's job). Same shim as __tests__/a11y.axe.spec.js.
beforeAll(() => {
    if (!('__a11yCanvasShim' in HTMLCanvasElement.prototype)) {
        HTMLCanvasElement.prototype.getContext = () => null;
        HTMLCanvasElement.prototype.__a11yCanvasShim = true;
    }
});

const props = (over = {}) => ({
    logs: { data: [], total: 0, from: 0, to: 0, last_page: 1, links: [] },
    filters: {},
    actors: [{ id: 1, name: 'Dr A' }],
    entityTypes: ['admission', 'consultation'],
    categories: ['phi_read', 'admission', 'consultation', 'patient', 'user', 'settings'],
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

describe('Audit/Index — accessible names (UX-04)', () => {
    it('has no axe violations (empty log)', async () => {
        expect(await axe(mountAttached().element)).toHaveNoViolations();
    });

    it('has no axe violations (with rows and an expanded details panel)', async () => {
        const w = mountAttached(props({
            logs: {
                data: [
                    { id: 1, created_at: '2026-09-01T10:00:00Z', actor_name: 'Dr A', action: 'user.update', category: 'user', entity_type: 'admission', entity_id: 7, details: { field: 'role' }, ip: '10.0.0.1' },
                ],
                total: 1, from: 1, to: 1, last_page: 1, links: [],
            },
        }));
        await w.findAll('button').find((b) => b.text() === 'View').trigger('click');   // expand the details row
        await w.vm.$nextTick();
        expect(await axe(w.element)).toHaveNoViolations();
    });

    it('pairs the From/To labels with their inputs via for/id, ids unique', () => {
        const w = mountAttached();
        const labels = w.findAll('label[for]');
        expect(labels.map((l) => l.text())).toEqual(['From', 'To']);
        const ids = labels.map((l) => l.attributes('for'));
        expect(new Set(ids).size).toBe(ids.length);
        for (const l of labels) {
            const control = document.getElementById(l.attributes('for'));   // jsdom has no CSS.escape
            expect(control, `label "${l.text()}" points at nothing`).not.toBeNull();
            expect(control.tagName).toBe('INPUT');
        }
    });

    it('names the three placeholder-only filters with aria-label', () => {
        const w = mountAttached();
        expect(w.find('input[placeholder^="Action"]').attributes('aria-label')).toBe('Action');
        expect(w.find('input[placeholder="Entity ID"]').attributes('aria-label')).toBe('Entity ID');
        expect(w.find('input[placeholder="IP address"]').attributes('aria-label')).toBe('IP address');
    });

    // role walkthrough 2026-09-25 (ui-ux #5, axe "insufficient color contrast"): the disabled
    // "« Previous"/"Next »" pagination span was text-ink-300 on bg-card (~2:1) — below WCAG AA's
    // 4.5:1. Registry's identical paginator already used ink-500 (passes in both themes); Audit's
    // now matches it.
    it('renders a disabled pagination link in the AA-passing ink-500 tone, not ink-300', () => {
        const w = mountAttached(props({
            logs: { data: [], total: 0, from: 0, to: 0, last_page: 2, links: [
                { url: null, label: '&laquo; Previous', active: false },
                { url: '/audit?page=2', label: '2', active: false },
                { url: '/audit?page=2', label: 'Next &raquo;', active: false },
            ] },
        }));
        const disabled = w.findAll('span').find((s) => s.text().includes('Previous'));
        expect(disabled).toBeTruthy();
        expect(disabled.classes()).toContain('text-ink-500');
        expect(disabled.classes()).not.toContain('text-ink-300');
    });
});
