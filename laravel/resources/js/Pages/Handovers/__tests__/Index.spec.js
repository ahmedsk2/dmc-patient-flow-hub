import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// Handovers/Index.vue talks to the server via `router`/`useForm` (sign / sign-all / save-text) and
// a confirm dialog for "sign all" — none of that is exercised here, so both are stubbed. AppLayout
// is stubbed to strip the app chrome; CheckpointChips and InfoTip render for real (small pure
// components).
const { askMock } = vi.hoisted(() => ({ askMock: vi.fn(() => Promise.resolve(true)) }));
vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn() },
    useForm: (obj) => ({ ...obj, post: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: askMock }) }));

import { router } from '@inertiajs/vue3';
import Index from '@/Pages/Handovers/Index.vue';

const baseProps = (over = {}) => ({ awaiting: [], outgoing: [], needsHandover: [], ...over });

beforeEach(() => {
    askMock.mockClear();
    askMock.mockImplementation(() => Promise.resolve(true));
    router.post.mockClear();
});

describe('Handovers/Index — Needs handover tab (HC-T9)', () => {
    it('renders a Needs handover tab listing each stale admission', async () => {
        const w = mount(Index, {
            props: baseProps({
                needsHandover: [{ admission_id: 7, patient: 'Ahmed M.', mrn: '44219', bed: '12', consultant: 'Dr A', last_updated: null, checkpoints: null }],
            }),
        });

        const tabButton = w.findAll('button').find((b) => b.text().includes('Needs handover'));
        expect(tabButton).toBeTruthy();
        await tabButton.trigger('click');

        expect(w.text()).toContain('Ahmed M.');
        expect(w.text()).toContain('44219');
    });

    it('shows a friendly empty state when nothing needs a handover', async () => {
        const w = mount(Index, { props: baseProps({ needsHandover: [] }) });
        const tabButton = w.findAll('button').find((b) => b.text().includes('Needs handover'));
        await tabButton.trigger('click');
        expect(w.text()).toContain('No patients are missing a handover today.');
    });

    it('shows the Write link only for a row the viewer can actually write (can_write, #32)', async () => {
        const w = mount(Index, {
            props: baseProps({
                needsHandover: [
                    { admission_id: 7, patient: 'Ahmed M.', mrn: '44219', bed: '12', consultant: 'Dr A', last_updated: null, checkpoints: null, can_write: true },
                    { admission_id: 8, patient: 'Sara K.', mrn: '55210', bed: '9', consultant: 'Dr B', last_updated: null, checkpoints: null, can_write: false },
                ],
            }),
        });
        const tabButton = w.findAll('button').find((b) => b.text().includes('Needs handover'));
        await tabButton.trigger('click');

        const rows = w.findAll('tbody tr');
        const writableRow = rows.find((r) => r.text().includes('Ahmed M.'));
        const readonlyRow = rows.find((r) => r.text().includes('Sara K.'));
        expect(writableRow.find('a').exists()).toBe(true);
        expect(writableRow.text()).toContain('Write');
        expect(readonlyRow.find('a').exists()).toBe(false);
    });
});

describe('Handovers/Index — Sign (#12: confirm + explicit acknowledgement)', () => {
    const row = { id: 1, admission_id: 9, patient: 'Amal K.', mrn: '900', bed: null, from: 'Dr X', required_at: null, body: 'Stable.', checkpoints: null };

    it('confirms before signing a single row, then posts acknowledged:true', async () => {
        const w = mount(Index, { props: baseProps({ awaiting: [row] }) });
        const signBtn = w.findAll('button').find((b) => b.text() === 'Sign');
        expect(signBtn).toBeTruthy();

        await signBtn.trigger('click');
        await flushPromises();

        expect(askMock).toHaveBeenCalledTimes(1);
        expect(askMock.mock.calls[0][0]).toBe('Sign handover');
        expect(router.post).toHaveBeenCalledWith('/handovers/1/sign', { acknowledged: true }, expect.objectContaining({ preserveScroll: true }));
    });

    it('does not sign when the confirmation is declined', async () => {
        askMock.mockImplementation(() => Promise.resolve(false));
        const w = mount(Index, { props: baseProps({ awaiting: [row] }) });
        const signBtn = w.findAll('button').find((b) => b.text() === 'Sign');

        await signBtn.trigger('click');
        await flushPromises();

        expect(router.post).not.toHaveBeenCalled();
    });

    it('Sign all also sends acknowledged:true', async () => {
        const two = [row, { ...row, id: 2, patient: 'Bilal T.' }];
        const w = mount(Index, { props: baseProps({ awaiting: two }) });
        const signAllBtn = w.findAll('button').find((b) => b.text().includes('Sign all'));
        expect(signAllBtn).toBeTruthy();

        await signAllBtn.trigger('click');
        await flushPromises();

        expect(router.post).toHaveBeenCalledWith('/handovers/sign-many', { ids: [1, 2], acknowledged: true }, expect.objectContaining({ preserveScroll: true }));
    });
});
