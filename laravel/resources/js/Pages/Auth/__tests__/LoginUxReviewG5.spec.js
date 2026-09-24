import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Owner instruction (2026-09-24): remove the brand-panel stats ("22 hospitals / 3,400 beds / 24/7
// live") from the sign-in page entirely — the figures were never a confirmed fact anywhere in the
// project records (review #16) — while keeping the headline, paragraph and trust badges. New file,
// separate from the existing Login.a11y.spec.js (kept intact, still green).
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a href="#"><slot /></a>' },
    usePage: () => ({ props: { flash: null, auth: { user: null } } }),
    useForm: (initial) => ({ ...initial, errors: {}, processing: false, post: vi.fn(), reset: vi.fn() }),
}));

import Login from '@/Pages/Auth/Login.vue';

describe('Auth/Login — brand-panel stats removed (owner instruction, 2026-09-24)', () => {
    it('no longer renders the hospitals/beds/24-7 figures', () => {
        const text = mount(Login).text();
        expect(text).not.toContain('hospitals');
        expect(text).not.toContain('3,400');
        expect(text).not.toContain('24/7');
    });

    it('keeps the headline, paragraph and trust badges', () => {
        const w = mount(Login);
        expect(w.text()).toContain('Care, coordinated.');
        expect(w.text()).toContain('one calm, modern command center for the Internal Medicine unit');
        expect(w.text()).toContain('Built for safe, compliant care');   // TrustBadges' own heading
    });
});
