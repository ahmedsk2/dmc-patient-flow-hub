import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { vi } from 'vitest';

// U-signin(c) (role walkthrough 2026-09-25): only Email carried a required-field asterisk, though
// RegisterController::store() requires username/full_name/role too — a user could reasonably think
// only email mattered at this stage. All four required fields now carry the same marker, and "Role"
// gets an InfoTip explaining what it actually does (matches RegisterController::store(): the role
// is stored, the account is created inactive pending admin activation, and an administrator can
// change it later via Control → Users).

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    useForm: (obj) => reactive({ ...obj, errors: {}, processing: false, post: vi.fn(), reset: vi.fn() }),
}));
vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(async () => 'data:image/png;base64,mockqr') } }));
vi.mock('@/Components/PasswordMeter.vue', () => ({ default: { template: '<div />' } }));

import Register from '@/Pages/Auth/Register.vue';

const roles = { 2: 'Registrar', 3: 'Consultant', 4: 'Resident', 5: 'Observer' };
const mountForm = () => mount(Register, { props: { roles } });

const labelFor = (w, forId) => w.findAll('label').find((l) => l.attributes('for') === forId);

describe('Register — every required field is marked the same way (U-signin c)', () => {
    it('marks Username, Role, Full name and Email all with a required asterisk', () => {
        const w = mountForm();
        for (const id of ['reg-username', 'reg-role', 'reg-fullname', 'reg-email']) {
            expect(labelFor(w, id).text()).toContain('*');
        }
    });

    it('gives the Role label an InfoTip explaining what it does', () => {
        const w = mountForm();
        const tipButton = w.findAll('button[data-infotip]').find((b) => b.attributes('aria-label')?.includes('Role'));
        expect(tipButton).toBeTruthy();
    });

    // fix-up (role walkthrough 2026-09-25): the InfoTip used to nest INSIDE <label for="reg-role">,
    // a labelable/interactive descendant that pollutes the label's computed accessible name ("Role *
    // !" instead of "Role *") — same anti-pattern already fixed the same day in Statistics/Index.vue
    // and AdminBandCard.vue. It now sits as a sibling, so the label itself has no nested button.
    it('keeps the InfoTip button out of the Role label so its accessible name stays clean', () => {
        const w = mountForm();
        const roleLabel = labelFor(w, 'reg-role');
        expect(roleLabel.find('button[data-infotip]').exists()).toBe(false);
        expect(roleLabel.text().trim()).toBe('Role *');
    });
});
