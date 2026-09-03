import { describe, it, expect, vi, afterEach, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { axe } from 'vitest-axe';
import * as axeMatchers from 'vitest-axe/matchers';

// UX-04 (engineering closeout): the reset-password page's three controls (email, new password,
// confirm password) had bare sibling <label>s with no for/id pairing. Same gate shape as
// Auth/Login.a11y.spec.js. PasswordMeter is left un-stubbed — it renders nothing while the
// password field is empty (its v-if="password" guard), which is all these fixtures need.
expect.extend(axeMatchers);

const errors = vi.hoisted(() => ({}));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a href="#"><slot /></a>' },
    useForm: (initial) => ({ ...initial, errors, processing: false, post: vi.fn(), reset: vi.fn() }),
}));

import ResetPassword from '@/Pages/Auth/ResetPassword.vue';

// jsdom has no canvas; axe's colour-contrast probe logs a "Not implemented" error per audit
// (noise — contrast is scripts/contrast.mjs's job). Same shim as __tests__/a11y.axe.spec.js.
beforeAll(() => {
    if (!('__a11yCanvasShim' in HTMLCanvasElement.prototype)) {
        HTMLCanvasElement.prototype.getContext = () => null;
        HTMLCanvasElement.prototype.__a11yCanvasShim = true;
    }
});

const props = { token: 'abc123', email: 'nurse@dmc-im.com' };

let wrappers = [];
const mountAttached = () => {
    const host = document.createElement('div');
    document.body.appendChild(host);
    const w = mount(ResetPassword, { props, attachTo: host });
    wrappers.push({ w, host });
    return w;
};
afterEach(() => {
    for (const { w, host } of wrappers) { w.unmount(); host.remove(); }
    wrappers = [];
    for (const k of Object.keys(errors)) delete errors[k];
});

const EXPECTED = ['Email', 'New password', 'Confirm password'];

describe('Auth/ResetPassword — accessible names (UX-04)', () => {
    it('has no axe violations', async () => {
        expect(await axe(mountAttached().element)).toHaveNoViolations();
    });

    it('pairs all three labels with a control via for/id, each id unique', () => {
        const w = mountAttached();
        const labels = w.findAll('label[for]');
        expect(labels.map((l) => l.text())).toEqual(EXPECTED);
        const ids = labels.map((l) => l.attributes('for'));
        expect(new Set(ids).size).toBe(ids.length);
        for (const l of labels) {
            const control = document.getElementById(l.attributes('for'));   // jsdom has no CSS.escape
            expect(control, `label "${l.text()}" points at nothing`).not.toBeNull();
            expect(control.tagName).toBe('INPUT');
        }
    });

    it('links the password error to its control through aria-describedby', () => {
        errors.password = 'The password must be at least 8 characters.';
        const w = mountAttached();
        const pwLabel = w.findAll('label[for]').find((l) => l.text() === 'New password');
        const input = document.getElementById(pwLabel.attributes('for'));
        const described = input.getAttribute('aria-describedby');
        expect(described).toBeTruthy();
        expect(document.getElementById(described)?.textContent).toContain('at least 8 characters');
    });
});
