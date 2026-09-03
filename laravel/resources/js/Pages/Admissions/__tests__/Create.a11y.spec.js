import { describe, it, expect, vi, afterEach, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { axe } from 'vitest-axe';
import * as axeMatchers from 'vitest-axe/matchers';

// UX-04 (prod-ready 2026-09-03): the admission form — the core clinical workflow — had ten
// unlabelled controls (bare sibling <label>s). This gate mounts the real page under the same
// AppLayout stub the ledger a11y gate uses, runs axe-core, and asserts every label resolves to a
// control by for/id so the regression cannot come back silently.
expect.extend(axeMatchers);

const errors = vi.hoisted(() => ({}));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a href="#"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 1, role: 0, is_admin: true, can: { add: true } } }, flash: null } }),
    useForm: (initial) => ({ ...initial, errors, processing: false, post: vi.fn(), reset: vi.fn(), clearErrors: vi.fn() }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: {
        name: 'AppLayout',
        props: ['title'],
        template: '<div><header><h1>{{ title }}</h1></header><main id="main-content" tabindex="-1"><slot /></main></div>',
    },
}));
// The ICD-10 typeahead owns its own labelling (covered elsewhere) and fetches over the network;
// it is stubbed to an inert element so only THIS page's controls are under test.
vi.mock('@/Components/IcdTypeahead.vue', () => ({ default: { name: 'IcdTypeahead', template: '<div></div>' } }));

import Create from '@/Pages/Admissions/Create.vue';

// jsdom has no canvas; silence axe's contrast probe exactly as __tests__/a11y.axe.spec.js does.
beforeAll(() => {
    if (!('__a11yCanvasShim' in HTMLCanvasElement.prototype)) {
        HTMLCanvasElement.prototype.getContext = () => null;
        HTMLCanvasElement.prototype.__a11yCanvasShim = true;
    }
});

const props = {
    consultants: [{ id: 5, name: 'Dr A', full_name: 'Dr A' }],
    countries: ['Saudi Arabia', 'Egypt'],
    locations: ['Ward', 'ICU', 'ER'],
    admitFrom: ['ER', 'OPD'],
};

let wrappers = [];
const mountAttached = () => {
    const host = document.createElement('div');
    document.body.appendChild(host);
    const w = mount(Create, { props, attachTo: host });
    wrappers.push({ w, host });
    return w;
};
afterEach(() => {
    for (const { w, host } of wrappers) { w.unmount(); host.remove(); }
    wrappers = [];
    for (const k of Object.keys(errors)) delete errors[k];
});

const EXPECTED = ['MRN', 'Full name', 'Age', 'Gender', 'Nationality', 'Admit date', 'Admitted from', 'Location', 'Bed', 'Consultant'];

describe('Admissions/Create — accessible names (UX-04)', () => {
    it('has no axe violations', async () => {
        expect(await axe(mountAttached().element)).toHaveNoViolations();
    });

    it('pairs all ten labels with a control via for/id, each id unique', () => {
        const w = mountAttached();
        const labels = w.findAll('label[for]');
        const texts = labels.map((l) => l.text().replace('*', '').trim());
        for (const name of EXPECTED) expect(texts, `missing paired label "${name}"`).toContain(name);
        const ids = labels.map((l) => l.attributes('for'));
        expect(new Set(ids).size).toBe(ids.length);
        for (const l of labels) {
            const control = document.getElementById(l.attributes('for'));   // jsdom has no CSS.escape
            expect(control, `label "${l.text()}" points at nothing`).not.toBeNull();
            expect(['INPUT', 'SELECT']).toContain(control.tagName);
        }
    });

    it('links a field error to its control through aria-describedby', () => {
        errors.mrn = 'The MRN has already been admitted.';
        const w = mountAttached();
        const mrnLabel = w.findAll('label[for]').find((l) => l.text().startsWith('MRN'));
        const input = document.getElementById(mrnLabel.attributes('for'));
        const described = input.getAttribute('aria-describedby');
        expect(described).toBeTruthy();
        expect(document.getElementById(described)?.textContent).toContain('already been admitted');
    });
});
