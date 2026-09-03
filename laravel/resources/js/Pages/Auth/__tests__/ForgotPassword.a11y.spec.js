import { describe, it, expect, vi, afterEach, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { axe } from 'vitest-axe';
import * as axeMatchers from 'vitest-axe/matchers';

// UX-04 (engineering closeout): the forgot-password page's one control had a bare sibling <label>
// with no for/id pairing. Same gate shape as Auth/Login.a11y.spec.js: mount the real page, run
// axe-core, and check the label resolves to the control and a server-side error is announced.
expect.extend(axeMatchers);

const errors = vi.hoisted(() => ({}));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a href="#"><slot /></a>' },
    useForm: (initial) => ({ ...initial, errors, processing: false, post: vi.fn(), reset: vi.fn() }),
}));

import ForgotPassword from '@/Pages/Auth/ForgotPassword.vue';

// jsdom has no canvas; axe's colour-contrast probe logs a "Not implemented" error per audit
// (noise — contrast is scripts/contrast.mjs's job). Same shim as __tests__/a11y.axe.spec.js.
beforeAll(() => {
    if (!('__a11yCanvasShim' in HTMLCanvasElement.prototype)) {
        HTMLCanvasElement.prototype.getContext = () => null;
        HTMLCanvasElement.prototype.__a11yCanvasShim = true;
    }
});

let wrappers = [];
const mountAttached = (props = {}) => {
    const host = document.createElement('div');
    document.body.appendChild(host);
    const w = mount(ForgotPassword, { props, attachTo: host });
    wrappers.push({ w, host });
    return w;
};
afterEach(() => {
    for (const { w, host } of wrappers) { w.unmount(); host.remove(); }
    wrappers = [];
    for (const k of Object.keys(errors)) delete errors[k];
});

describe('Auth/ForgotPassword — accessible names (UX-04)', () => {
    it('has no axe violations', async () => {
        expect(await axe(mountAttached().element)).toHaveNoViolations();
    });

    it('pairs the label with its input via for/id', () => {
        const w = mountAttached();
        const labels = w.findAll('label[for]');
        expect(labels.map((l) => l.text())).toEqual(['Username or email']);
        const control = document.getElementById(labels[0].attributes('for'));   // jsdom has no CSS.escape
        expect(control).not.toBeNull();
        expect(control.tagName).toBe('INPUT');
    });

    it('announces a server-side error through aria-describedby', () => {
        errors.email = 'We could not find an account with that username or email.';
        const w = mountAttached();
        const input = w.find('input[autocomplete="username"]');
        const described = input.attributes('aria-describedby');
        expect(described).toBeTruthy();
        expect(document.getElementById(described)?.textContent).toContain('could not find');
    });
});
