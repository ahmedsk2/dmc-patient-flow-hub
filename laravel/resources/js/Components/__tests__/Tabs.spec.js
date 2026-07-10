import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import Tabs from '@/Components/Tabs.vue';

const TABS = [
    { id: 'summary', label: 'Summary' },
    { id: 'meds', label: 'Medications', badge: true },
    { id: 'notes', label: 'Notes' },
];

// The slot probe mirrors the real contract: the CALLER receives { active } and switches its own
// content on it — Tabs owns no panel content of its own.
const mountTabs = (props = {}, options = {}) =>
    mount(Tabs, {
        props: { tabs: TABS, ...props },
        slots: { default: `<template #default="{ active }"><p data-panel-probe>{{ active }}</p></template>` },
        ...options,
    });

const tabButtons = (w) => w.findAll('[role="tab"]');
const tabByIndex = (w, i) => tabButtons(w)[i];
const panel = (w) => w.get('[role="tabpanel"]');

describe('Tabs', () => {
    it('renders a tablist of real buttons — one role=tab per entry — and exactly one tabpanel', () => {
        const w = mountTabs();
        expect(w.find('[role="tablist"]').exists()).toBe(true);
        const tabs = tabButtons(w);
        expect(tabs).toHaveLength(3);
        for (const t of tabs) {
            expect(t.element.tagName).toBe('BUTTON');
            expect(t.attributes('type')).toBe('button');
        }
        expect(w.findAll('[role="tabpanel"]')).toHaveLength(1);
        expect(w.text()).toContain('Summary');
        expect(w.text()).toContain('Medications');
        expect(w.text()).toContain('Notes');
    });

    it('marks the active tab aria-selected=true / tabindex=0 and the rest false / -1 (roving tabindex)', () => {
        const w = mountTabs();
        expect(tabByIndex(w, 0).attributes('aria-selected')).toBe('true');
        expect(tabByIndex(w, 0).attributes('tabindex')).toBe('0');
        for (const i of [1, 2]) {
            expect(tabByIndex(w, i).attributes('aria-selected')).toBe('false');
            expect(tabByIndex(w, i).attributes('tabindex')).toBe('-1');
        }
    });

    // With ONE recycled panel node, a per-tab aria-controls idref would dangle for every inactive
    // tab (aria-valid-attr-value); only the ACTIVE tab points at the panel, and the panel points
    // back via aria-labelledby. The panel is itself a tab-stop (tabindex 0) so keyboard users can
    // reach panel content with no focusable elements of its own.
    it('wires the panel to the active tab: aria-labelledby ↔ aria-controls, panel tabindex=0', () => {
        const w = mountTabs();
        const activeTab = tabByIndex(w, 0);
        expect(panel(w).attributes('aria-labelledby')).toBe(activeTab.attributes('id'));
        expect(activeTab.attributes('aria-controls')).toBe(panel(w).attributes('id'));
        expect(panel(w).attributes('tabindex')).toBe('0');
        // Inactive tabs carry NO aria-controls (nothing to dangle).
        expect(tabByIndex(w, 1).attributes('aria-controls')).toBeUndefined();
        expect(tabByIndex(w, 2).attributes('aria-controls')).toBeUndefined();
    });

    it('passes { active } to the default slot so the caller switches its own content', async () => {
        const w = mountTabs();
        expect(w.get('[data-panel-probe]').text()).toBe('summary');
        await tabByIndex(w, 2).trigger('click');
        expect(w.get('[data-panel-probe]').text()).toBe('notes');
    });

    it('click selects: aria-selected, tabindex, and the panel wiring all move to the clicked tab', async () => {
        const w = mountTabs();
        await tabByIndex(w, 1).trigger('click');
        expect(tabByIndex(w, 1).attributes('aria-selected')).toBe('true');
        expect(tabByIndex(w, 1).attributes('tabindex')).toBe('0');
        expect(tabByIndex(w, 0).attributes('aria-selected')).toBe('false');
        expect(tabByIndex(w, 0).attributes('tabindex')).toBe('-1');
        expect(panel(w).attributes('aria-labelledby')).toBe(tabByIndex(w, 1).attributes('id'));
    });

    // Automatic activation: an arrow press on the tablist MOVES selection (no Enter step) and the
    // roving tab-stop follows. Mounted into the document so real focus can land on the new tab.
    it('ArrowRight on the tablist moves selection, updates aria, and focuses the new tab', async () => {
        const w = mountTabs({}, { attachTo: document.body });
        try {
            tabByIndex(w, 0).element.focus();
            await w.get('[role="tablist"]').trigger('keydown', { key: 'ArrowRight' });
            expect(tabByIndex(w, 1).attributes('aria-selected')).toBe('true');
            expect(tabByIndex(w, 1).attributes('tabindex')).toBe('0');
            expect(tabByIndex(w, 0).attributes('aria-selected')).toBe('false');
            expect(document.activeElement).toBe(tabByIndex(w, 1).element);
            expect(w.get('[data-panel-probe]').text()).toBe('meds');
        } finally {
            w.unmount();
        }
    });

    it('wraps: ArrowLeft from the first tab lands on the last', async () => {
        const w = mountTabs();
        await w.get('[role="tablist"]').trigger('keydown', { key: 'ArrowLeft' });
        expect(tabByIndex(w, 2).attributes('aria-selected')).toBe('true');
    });

    // The dirty-tab marker is never colour-alone: AT gets the words via the aria-label suffix,
    // sighted users get the dot. The accessible name keeps the visible label as its prefix
    // (WCAG 2.5.3 label-in-name).
    it('badge renders a decorative dot plus the "(unsaved changes)" aria-label suffix', () => {
        const w = mountTabs();
        const dirty = tabByIndex(w, 1); // meds has badge: true
        const dot = dirty.find('[data-dirty-dot]');
        expect(dot.exists()).toBe(true);
        expect(dot.attributes('aria-hidden')).toBe('true');
        expect(dirty.attributes('aria-label')).toBe('Medications (unsaved changes)');
        // Clean tabs: no dot, no aria-label override (the visible text is the name).
        expect(tabByIndex(w, 0).find('[data-dirty-dot]').exists()).toBe(false);
        expect(tabByIndex(w, 0).attributes('aria-label')).toBeUndefined();
    });

    it('v-model: modelValue drives the initial tab, user moves emit update:modelValue, setProps follows', async () => {
        const w = mountTabs({ modelValue: 'notes' });
        expect(tabByIndex(w, 2).attributes('aria-selected')).toBe('true');

        await tabByIndex(w, 0).trigger('click');
        const emitted = w.emitted('update:modelValue');
        expect(emitted?.at(-1)).toEqual(['summary']);

        // Parent-driven change (the other half of two-way) — and no echo back.
        const before = emitted.length;
        await w.setProps({ modelValue: 'meds' });
        expect(tabByIndex(w, 1).attributes('aria-selected')).toBe('true');
        expect(w.emitted('update:modelValue').length).toBe(before);
    });

    it('recovers when the active tab is removed from the tabs prop', async () => {
        const w = mountTabs();
        await tabByIndex(w, 1).trigger('click'); // meds active
        await w.setProps({ tabs: [TABS[0], TABS[2]] }); // meds gone
        expect(tabByIndex(w, 0).attributes('aria-selected')).toBe('true');
        expect(w.get('[data-panel-probe]').text()).toBe('summary');
    });

    it('active tab reads as brand-700 text with the brand keel; inactive tabs are ink-500', async () => {
        const w = mountTabs();
        expect(tabByIndex(w, 0).classes()).toContain('text-brand-700');
        expect(tabByIndex(w, 0).classes()).toContain('border-brand-500');
        expect(tabByIndex(w, 1).classes()).toContain('text-ink-500');
        await tabByIndex(w, 1).trigger('click');
        expect(tabByIndex(w, 1).classes()).toContain('text-brand-700');
        expect(tabByIndex(w, 0).classes()).toContain('text-ink-500');
    });

    it('gives every tab the 40px coarse-pointer target and the single sanctioned 120ms motion', () => {
        const w = mountTabs();
        for (const t of tabButtons(w)) {
            expect(t.classes()).toContain('min-h-10');
            expect(t.classes()).toContain('transition-row');
        }
        // Exactly ONE motion utility — no second transition-* class anywhere in the markup.
        expect(w.html().match(/transition-[a-z-]+/g).every((c) => c === 'transition-row')).toBe(true);
    });

    // Mirrors the a11y-status-text guard: no status colour is ever used AS TEXT here.
    it('never uses a status colour as text anywhere it renders', () => {
        const w = mountTabs();
        expect(w.html()).not.toMatch(/text-(?:warning|success|danger|info)-(?:400|500|600|700)/);
        expect(w.html()).not.toMatch(/\btext-brand-500\b/);
    });
});
