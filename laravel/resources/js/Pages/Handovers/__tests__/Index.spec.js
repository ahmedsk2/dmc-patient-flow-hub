import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Handovers/Index.vue talks to the server via `router`/`useForm` (sign / sign-all / save-text) and
// a confirm dialog for "sign all" — none of that is exercised here, so both are stubbed. AppLayout
// is stubbed to strip the app chrome; CheckpointChips renders for real (it's a small pure component).
vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn() },
    useForm: (obj) => ({ ...obj, post: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: vi.fn(() => Promise.resolve(true)) }) }));

import Index from '@/Pages/Handovers/Index.vue';

const baseProps = (over = {}) => ({ awaiting: [], outgoing: [], needsHandover: [], ...over });

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
});
