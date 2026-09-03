import { describe, it, expect, vi, afterEach, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { axe } from 'vitest-axe';
import * as axeMatchers from 'vitest-axe/matchers';

// UX-04 (engineering closeout): Registry/Index's diagnosis-mode "Diagnosis keyword" field had a
// bare sibling <label> with no for/id pairing — the page's one remaining gap (every other filter
// already carried a for/id pair or an aria-label). This gate mounts the real page in each of its
// three modes, hands it to axe-core, and checks every label[for] resolves to a real, unique control.
expect.extend(axeMatchers);

const { get, post, visit } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), visit: vi.fn() }));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    router: { get, post, visit, delete: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (initial) => ({
        ...initial, errors: {}, processing: false,
        post: vi.fn(), put: vi.fn(), clearErrors: vi.fn(), reset: vi.fn(),
    }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: {
        name: 'AppLayout',
        props: ['title', 'breadcrumbs'],
        template: '<div><header><h1>{{ title }}</h1></header><main id="main-content" tabindex="-1"><slot /></main></div>',
    },
}));
// The ICD-10 typeahead owns its own labelling (covered elsewhere) and fetches over the network; it
// is stubbed to an inert element so only THIS page's own controls are under test — same idiom as
// Admissions/Create.a11y.spec.js.
vi.mock('@/Components/IcdTypeahead.vue', () => ({ default: { name: 'IcdTypeahead', template: '<div></div>' } }));

import Registry from '@/Pages/Registry/Index.vue';

// jsdom has no canvas; axe's colour-contrast probe logs a "Not implemented" error per audit
// (noise — contrast is scripts/contrast.mjs's job). Same shim as __tests__/a11y.axe.spec.js.
beforeAll(() => {
    if (!('__a11yCanvasShim' in HTMLCanvasElement.prototype)) {
        HTMLCanvasElement.prototype.getContext = () => null;
        HTMLCanvasElement.prototype.__a11yCanvasShim = true;
    }
});

const options = {
    consultants: [{ id: 5, name: 'Dr A' }],
    countries: ['Saudi Arabia', 'Egypt'],
    locations: ['Ward', 'ICU', 'ER'],
    outcomes: ['Alive', 'Dead'],
    admittedFrom: ['ER', 'OPD'],
    dischargedTo: ['Home'],
    delays: ['Awaiting family'],
    readmitWindow: 3,
    reasons: [{ id: 1, name: 'Chest pain' }],
    dxNames: [],
};
const results = { data: [], total: 0, from: 0, to: 0, last_page: 1, links: [] };
const pageProps = (mode) => ({ mode, results, filters: {}, options, sort: { sort: null, dir: null } });

let wrappers = [];
const mountAttached = (mode) => {
    authUser = { id: 1, is_admin: true, can: { modify: true } };
    const host = document.createElement('div');
    document.body.appendChild(host);
    const w = mount(Registry, { props: pageProps(mode), attachTo: host });
    wrappers.push({ w, host });
    return w;
};
afterEach(() => {
    for (const { w, host } of wrappers) { w.unmount(); host.remove(); }
    wrappers = [];
});

const checkPairs = (w) => {
    const labels = w.findAll('label[for]');
    const ids = labels.map((l) => l.attributes('for'));
    expect(new Set(ids).size).toBe(ids.length);
    for (const l of labels) {
        const control = document.getElementById(l.attributes('for'));   // jsdom has no CSS.escape
        expect(control, `label "${l.text()}" points at nothing`).not.toBeNull();
        expect(['INPUT', 'SELECT', 'TEXTAREA']).toContain(control.tagName);
    }
};

describe.each(['admissions', 'consultations', 'diagnosis'])('Registry/Index (%s mode) — accessible names (UX-04)', (mode) => {
    it('has no axe violations', async () => {
        expect(await axe(mountAttached(mode).element)).toHaveNoViolations();
    });

    it('pairs every label[for] with a control, ids unique', () => {
        checkPairs(mountAttached(mode));
    });
});

describe('Registry/Index — diagnosis keyword field', () => {
    it('pairs the "Diagnosis keyword" label with its input via for/id', () => {
        const w = mountAttached('diagnosis');
        const label = w.findAll('label[for]').find((l) => l.text() === 'Diagnosis keyword');
        expect(label).toBeTruthy();
        const input = document.getElementById(label.attributes('for'));
        expect(input?.tagName).toBe('INPUT');
    });
});
