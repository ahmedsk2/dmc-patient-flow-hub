import { describe, it, expect, vi, afterEach, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { axe } from 'vitest-axe';
import * as axeMatchers from 'vitest-axe/matchers';

// UX-04 (engineering closeout): Profile/Edit's six form controls (full name, email, username,
// current/new/confirm password) had bare sibling <label>s with no for/id pairing. Same gate shape
// as Auth/Login.a11y.spec.js and Admissions/Create.a11y.spec.js.
expect.extend(axeMatchers);

const pErrors = vi.hoisted(() => ({}));
const wErrors = vi.hoisted(() => ({}));
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a href="#"><slot /></a>' },
    router: { delete: vi.fn() },
    useForm: (initial) => {
        const errors = 'full_name' in initial ? pErrors : wErrors;
        return { ...initial, errors, processing: false, recentlySuccessful: false, put: vi.fn(), reset: vi.fn() };
    },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: {
        name: 'AppLayout',
        props: ['title'],
        template: '<div><header><h1>{{ title }}</h1></header><main id="main-content" tabindex="-1"><slot /></main></div>',
    },
}));

import Edit from '@/Pages/Profile/Edit.vue';

// jsdom has no canvas; axe's colour-contrast probe logs a "Not implemented" error per audit
// (noise — contrast is scripts/contrast.mjs's job). Same shim as __tests__/a11y.axe.spec.js.
beforeAll(() => {
    if (!('__a11yCanvasShim' in HTMLCanvasElement.prototype)) {
        HTMLCanvasElement.prototype.getContext = () => null;
        HTMLCanvasElement.prototype.__a11yCanvasShim = true;
    }
});

const props = {
    profile: {
        name: 'Dr Nadia Farouk', username: 'nfarouk', email: 'nfarouk@dmc-im.com',
        role: 'Consultant', pass_exp_date: '2026-12-01', mfa_enabled: true,
    },
    trustedDevices: [
        { id: 1, label: 'Chrome on Windows', granted_at: '2026-08-01', expires_at: '2026-09-01', last_used_at: '2026-08-20' },
    ],
};

let wrappers = [];
const mountAttached = () => {
    const host = document.createElement('div');
    document.body.appendChild(host);
    const w = mount(Edit, { props, attachTo: host });
    wrappers.push({ w, host });
    return w;
};
afterEach(() => {
    for (const { w, host } of wrappers) { w.unmount(); host.remove(); }
    wrappers = [];
    for (const k of Object.keys(pErrors)) delete pErrors[k];
    for (const k of Object.keys(wErrors)) delete wErrors[k];
});

const EXPECTED = ['Full name', 'Email', 'Username', 'Current', 'New', 'Confirm'];

describe('Profile/Edit — accessible names (UX-04)', () => {
    it('has no axe violations', async () => {
        expect(await axe(mountAttached().element)).toHaveNoViolations();
    });

    it('pairs every labelled field with a control via for/id, each id unique', () => {
        const w = mountAttached();
        const labels = w.findAll('label[for]');
        const texts = labels.map((l) => l.text());
        for (const name of EXPECTED) expect(texts, `missing paired label "${name}"`).toContain(name);
        const ids = labels.map((l) => l.attributes('for'));
        expect(new Set(ids).size).toBe(ids.length);
        for (const l of labels) {
            const control = document.getElementById(l.attributes('for'));   // jsdom has no CSS.escape
            expect(control, `label "${l.text()}" points at nothing`).not.toBeNull();
            expect(control.tagName).toBe('INPUT');
        }
    });

    it('links a profile-form error to its control through aria-describedby', () => {
        pErrors.email = 'The email has already been taken.';
        const w = mountAttached();
        const label = w.findAll('label[for]').find((l) => l.text() === 'Email');
        const input = document.getElementById(label.attributes('for'));
        const described = input.getAttribute('aria-describedby');
        expect(described).toBeTruthy();
        expect(document.getElementById(described)?.textContent).toContain('already been taken');
    });

    it('links a password-form error to its control through aria-describedby', () => {
        wErrors.current_password = 'The current password is incorrect.';
        const w = mountAttached();
        const label = w.findAll('label[for]').find((l) => l.text() === 'Current');
        const input = document.getElementById(label.attributes('for'));
        const described = input.getAttribute('aria-describedby');
        expect(described).toBeTruthy();
        expect(document.getElementById(described)?.textContent).toContain('incorrect');
    });
});
