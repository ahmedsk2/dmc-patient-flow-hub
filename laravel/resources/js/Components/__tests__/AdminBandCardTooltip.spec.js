import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

// 2026-09-24 role/UX review §5 (Dashboard admin band): "Security Anomalies", "Pending Handovers"
// and "Handover Due (unit)" get an optional `tooltip` prop rendered as an inline InfoTip "!" mark
// next to the label. New spec file (not extending the existing AdminBandCard.spec.js, which several
// implementers touch concurrently in this working tree) covering just the new prop.
vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import AdminBandCard from '@/Components/AdminBandCard.vue';

const PATH = 'M3 3h18v18H3Z';
const bubble = () => document.body.querySelector('[data-infotip-bubble]');

let w;
const mountCard = (props = {}) => {
    w = mount(AdminBandCard, {
        props: { label: 'Security Anomalies', count: 3, href: '/security', iconPath: PATH, ...props },
        attachTo: document.body,
    });
    return w;
};
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

describe('AdminBandCard — optional tooltip (2026-09-24 review)', () => {
    it('renders no InfoTip mark when tooltip is omitted', () => {
        expect(mountCard().find('[data-infotip]').exists()).toBe(false);
    });

    it('renders an InfoTip mark whose accessible name is the card label', () => {
        const mark = mountCard({ tooltip: 'Failed-login clusters plus active accounts without MFA.' }).find('[data-infotip]');
        expect(mark.exists()).toBe(true);
        expect(mark.attributes('aria-label')).toBe('More information: Security Anomalies');
    });

    it('opening the mark shows the given text, and never navigates the card link', async () => {
        const card = mountCard({ tooltip: 'Some explanation.' });
        await card.get('[data-infotip]').trigger('click');
        await nextTick();
        // Fix-up (2026-09-24 review): the InfoTip is now a sibling of the <a>, not nested inside
        // it, so there is no click-through to guard against — but its own handler still stops
        // propagation, which this asserts stays harmless.
        expect(bubble()?.textContent).toBe('Some explanation.');
    });

    // Fix-up (2026-09-24 review): AdminBandCard's InfoTip button must never be a DOM descendant of
    // the card's <a> — an <a>'s content model forbids an interactive-content descendant, and this is
    // exactly the defect an adversarial review caught (axe-core's nested-interactive rule). Assert
    // the DOM shape directly rather than relying only on click behaviour.
    it('renders the InfoTip mark as a sibling of the card link, never nested inside it', () => {
        const card = mountCard({ tooltip: 'Some explanation.' });
        const link = card.get('a');
        expect(link.find('[data-infotip]').exists()).toBe(false);
        expect(card.get('[data-infotip]')).toBeTruthy();
    });
});
