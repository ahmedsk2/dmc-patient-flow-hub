import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { vi } from 'vitest';

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import DataQuality from '@/Pages/Admin/DataQuality.vue';

/**
 * The "Unknown ICD-10 codes" and "Patients with >1 open episode" sections carry a new InfoTip
 * (2026-09-24 role/UX review, §5 Control panel / info marks). InfoTip renders a real <button>, so
 * it MUST sit outside the section's own toggle <button> — nesting a <button> inside another
 * <button> is invalid HTML and browsers silently close the outer one early, which would break the
 * collapse/expand control. This spec is the regression guard for that restructure.
 */
const mountPage = (props = {}) => mount(DataQuality, {
    props: { overLos: [], noDx: [], badDates: [], orphanDx: [], doubleOpen: [], longLos: 11, multiplier: 2, ...props },
});

describe('Admin/DataQuality — orphan-codes and double-open sections', () => {
    it('renders exactly one <button> ancestor per toggle control (no button-in-button nesting)', () => {
        const w = mountPage();
        // every button on the page must have at most itself as a <button> ancestor
        for (const btn of w.findAll('button')) {
            const nestedButtons = btn.element.querySelectorAll('button');
            expect(nestedButtons.length, `a <button> must not contain another <button>: ${btn.text()}`).toBe(0);
        }
    });

    it('carries a "More information" InfoTip for the orphan-codes and double-open sections', () => {
        const w = mountPage();
        expect(w.find('button[aria-label="More information: Unknown ICD-10 codes"]').exists()).toBe(true);
        expect(w.find('button[aria-label="More information: Patients with more than one open episode"]').exists()).toBe(true);
    });

    /**
     * Review fix: the orphan-codes InfoTip previously claimed the full list was "under Data
     * Management → Orphan Diagnoses", but no such nav entry exists anywhere in AppLayout.vue.
     * Reworded to not claim a nav path, and a real link to the Orphan Diagnoses page was added
     * directly on this page instead (2026-09-24 review fix-up).
     */
    it('does not claim a "Data Management → Orphan Diagnoses" nav path that does not exist, and links to the page directly', () => {
        const w = mountPage();
        expect(w.text()).not.toContain('Data Management → Orphan Diagnoses');
        const link = w.find('a[href="/admin/orphan-diagnoses"]');
        expect(link.exists()).toBe(true);
        expect(link.text().toLowerCase()).toContain('orphan diagnoses');
    });

    // Style attribute (not `.isVisible()`) — a wrapper queried before a later state change can report
    // a stale visibility verdict in this environment even after a fresh re-query; the v-show'd
    // element's own `style` attribute is the ground truth and always reflects the current DOM.
    const styleOf = (w) => w.findAll('table').find((t) => t.text().includes('Z99.9')).attributes('style') || '';

    it('both toggle buttons in the orphan-codes row still expand/collapse the table', async () => {
        const w = mountPage({ orphanDx: [{ mrn: '123', name: 'A', icd10_code: 'Z99.9' }] });
        expect(styleOf(w)).not.toContain('display: none');

        // the label button (flex-1) toggles it closed
        const labelBtn = w.findAll('button').find((b) => b.text().includes('Unknown ICD-10 codes'));
        await labelBtn.trigger('click');
        expect(styleOf(w)).toContain('display: none');

        // the trailing +/- indicator button toggles it back open
        const indicatorBtn = w.find('button[aria-label="Expand"]');
        await indicatorBtn.trigger('click');
        expect(styleOf(w)).not.toContain('display: none');
    });
});
