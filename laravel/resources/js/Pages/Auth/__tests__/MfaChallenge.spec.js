import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// The trusted-device opt-in on the MFA challenge (2026-07-19 spec, "Surfaces" §1).
//
// Two properties carry the whole clinical safety argument and are pinned here: the box is only
// rendered when the admin window is non-zero, and it is NEVER pre-ticked. Ward workstations are
// shared — a default-on box would silently waive the second factor for whoever sits down next.

const post = vi.hoisted(() => vi.fn());
const formState = vi.hoisted(() => ({}));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    useForm: (initial) => {
        Object.assign(formState, initial, { processing: false, errors: {}, post, reset: vi.fn() });
        return formState;
    },
}));

import MfaChallenge from '@/Pages/Auth/MfaChallenge.vue';

const mountPage = (trustedDeviceHours = 24) => mount(MfaChallenge, { props: { trustedDeviceHours } });
const box = (w) => w.find('#trust_device');

beforeEach(() => post.mockClear());

describe('MfaChallenge — trusted-device opt-in', () => {
    it('renders the checkbox when the window is configured', () => {
        expect(box(mountPage(24)).exists()).toBe(true);
    });

    it('does not render the checkbox when the feature is off (0 hours)', () => {
        expect(box(mountPage(0)).exists()).toBe(false);
    });

    it('starts unticked — trust is never granted by default', () => {
        const w = mountPage(24);
        expect(box(w).element.checked).toBe(false);
        expect(formState.trust_device).toBe(false);
    });

    it('interpolates the real configured hours into the label', () => {
        expect(mountPage(72).text()).toContain("Don't ask for a code on this device for the next 72 hours.");
    });

    it('warns about shared computers', () => {
        expect(mountPage(24).text()).toContain('Leave this unticked on a shared or ward computer.');
    });

    it('binds a real <label for> to the input id (a11y)', () => {
        const w = mountPage(24);
        const label = w.findAll('label').find((l) => l.attributes('for') === 'trust_device');
        expect(label).toBeTruthy();
        expect(box(w).attributes('id')).toBe('trust_device');
    });

    it('submits trust_device alongside the code', async () => {
        const w = mountPage(24);
        await box(w).setValue(true);
        await w.find('form').trigger('submit');

        expect(formState.trust_device).toBe(true);
        expect(post).toHaveBeenCalledWith('/mfa/challenge', expect.anything());
    });
});
