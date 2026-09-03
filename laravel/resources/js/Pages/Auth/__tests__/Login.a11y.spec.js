import { describe, it, expect, vi, afterEach, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { axe } from 'vitest-axe';
import * as axeMatchers from 'vitest-axe/matchers';

// UX-04 (prod-ready 2026-09-03): the sign-in page's two controls had bare sibling <label>s with no
// for/id pairing, so screen-reader users got no accessible name on the app's own front door. This
// gate mounts the real page and (1) hands it to axe-core, (2) checks every label's `for` resolves
// to a control, and (3) checks a server-side error is announced via aria-describedby.
expect.extend(axeMatchers);

const errors = vi.hoisted(() => ({}));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a href="#"><slot /></a>' },
    usePage: () => ({ props: { flash: null, auth: { user: null } } }),
    useForm: (initial) => ({ ...initial, errors, processing: false, post: vi.fn(), reset: vi.fn() }),
}));

import Login from '@/Pages/Auth/Login.vue';

// jsdom has no canvas; axe's colour-contrast probe logs a "Not implemented" error per audit
// (noise — contrast is scripts/contrast.mjs's job). Same shim as __tests__/a11y.axe.spec.js.
beforeAll(() => {
    if (!('__a11yCanvasShim' in HTMLCanvasElement.prototype)) {
        HTMLCanvasElement.prototype.getContext = () => null;
        HTMLCanvasElement.prototype.__a11yCanvasShim = true;
    }
});

let wrappers = [];
const mountAttached = () => {
    const host = document.createElement('div');
    document.body.appendChild(host);
    const w = mount(Login, { attachTo: host });
    wrappers.push({ w, host });
    return w;
};
afterEach(() => {
    for (const { w, host } of wrappers) { w.unmount(); host.remove(); }
    wrappers = [];
    for (const k of Object.keys(errors)) delete errors[k];
});

const pairs = (w) => w.findAll('label[for]').map((l) => ({
    text: l.text().trim(),
    control: document.getElementById(l.attributes('for')),   // jsdom has no CSS.escape; ids are attached to document
}));

describe('Auth/Login — accessible names (UX-04)', () => {
    it('has no axe violations', async () => {
        expect(await axe(mountAttached().element)).toHaveNoViolations();
    });

    it('pairs both labels with their inputs via for/id', () => {
        const found = pairs(mountAttached());
        expect(found.map((p) => p.text)).toEqual(['Username or email', 'Password']);
        for (const p of found) {
            expect(p.control, `no control for label "${p.text}"`).not.toBeNull();
            expect(['INPUT', 'SELECT']).toContain(p.control.tagName);
        }
        // the two ids must differ — a shared id would silently mislabel the password field
        expect(new Set(found.map((p) => p.control.id)).size).toBe(2);
    });

    it('announces a server-side error through aria-describedby', () => {
        errors.username = 'These credentials do not match our records.';
        const w = mountAttached();
        const input = w.find('input[autocomplete="username"]');
        const described = input.attributes('aria-describedby');
        expect(described).toBeTruthy();
        expect(document.getElementById(described)?.textContent).toContain('credentials');
    });
});
