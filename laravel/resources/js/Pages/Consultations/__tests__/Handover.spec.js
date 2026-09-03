import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Stub the layout chrome — this spec is about the sheet's content, and Handover.vue uses no
// Inertia primitives of its own (the page is read-only, so it needs no router/form).
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', template: '<div><slot /></div>' } }));

import Handover from '@/Pages/Consultations/Handover.vue';

const consult = (over = {}) => ({
    id: 1, name: 'Ward Patient', mrn: '90000001', age: 61, bed: 'W-12', location: 'Ward',
    from: 'ER', status: 'active', specialty: 'Cardiology',
    consultant: 'Dr Cardio', entered_by: 'Dr Coordinator',
    reasons: ['Chest pain'], other: null, requested_on: '2026-08-15', open_days: 6,
    last_followup: { date: '2026-08-21', note: 'Rate controlled, continue beta blocker.', author: 'Dr Cardio', is_today: true },
    ...over,
});

const group = (key, name, consultations) => ({
    key, name, consultations,
    counts: {
        total: consultations.length,
        active: consultations.filter((c) => c.status === 'active').length,
        ongoing: consultations.filter((c) => c.status === 'ongoing').length,
        seen_today: consultations.filter((c) => c.last_followup && c.last_followup.is_today).length,
    },
});

const cardiology = () => group('Cardiology', 'Cardiology', [
    consult(),
    consult({ id: 2, name: 'Second Patient', mrn: '90000002', bed: 'W-20', status: 'ongoing', open_days: 12, last_followup: null }),
]);

const props = (over = {}) => ({
    groups: [cardiology()], generatedAt: 'Fri, 21 Aug 2026 · 07:00', today: '2026-08-21', ...over,
});

const mountPage = (p = props()) => mount(Handover, { props: p });

describe('Consultations/Handover — the shift sheet', () => {
    it('names the service and lists every consult in it', () => {
        const report = mountPage().find('.report').text();
        expect(report).toContain('Cardiology');
        expect(report).toContain('Ward Patient');
        expect(report).toContain('90000001');
        expect(report).toContain('W-12');
        expect(report).toContain('Second Patient');
    });

    it('shows the owning consultant separately from whoever entered the consult', () => {
        const report = mountPage().find('.report').text();
        expect(report).toContain('Dr Cardio');
        expect(report).toContain('Dr Coordinator');
    });

    it('renders the latest follow-up note, and says so when there is none', () => {
        const report = mountPage().find('.report').text();
        expect(report).toContain('Rate controlled, continue beta blocker.');
        expect(report).toContain('No follow-up recorded');
    });

    it('marks a consult already followed up today', () => {
        expect(mountPage().find('.report').text()).toContain('seen today');
    });

    it('labels active and ongoing distinctly', () => {
        const report = mountPage().find('.report').text();
        expect(report).toContain('Active · daily F/U');
        expect(report).toContain('Ongoing');
    });

    it('shows how long each consult has been open', () => {
        const report = mountPage().find('.report').text();
        expect(report).toContain('open 6d');
        expect(report).toContain('open 12d');
    });

    it('heads the sheet with the open total and today’s follow-up completeness', () => {
        const text = mountPage().text();
        expect(text).toContain('2 open consult(s)');
        expect(text).toContain('1 of 1 active seen today');
        expect(text).toContain('Fri, 21 Aug 2026 · 07:00');
    });

    it('says so when there is nothing to hand over', () => {
        expect(mountPage(props({ groups: [] })).text()).toContain('No active or ongoing consultations');
    });

    it('does not count an ongoing consult toward "active seen today", even if it was followed up today', () => {
        // 1 active (not yet seen today) + 1 ongoing (seen today) — the ongoing row's today
        // follow-up must not inflate the active-seen-today numerator, or the sheet would print
        // a false all-clear ("1 of 1 active seen today") while the one active patient with the
        // daily-review obligation was never actually seen.
        const consultations = [
            consult({ id: 1, status: 'active', last_followup: null }),
            consult({ id: 2, status: 'ongoing', last_followup: { date: '2026-08-21', note: '', author: 'Dr Cardio', is_today: true } }),
        ];
        const g = group('Cardiology', 'Cardiology', consultations);
        const text = mountPage(props({ groups: [g] })).text();
        expect(text).toContain('0 of 1 active seen today');
        expect(text).not.toContain('1 of 1 active seen today');
    });
});

const nephrology = () => group('Nephrology', 'Nephrology', [
    consult({ id: 3, name: 'Renal Patient', mrn: '90000003', specialty: 'Nephrology', consultant: 'Dr Nephro', status: 'ongoing', last_followup: null }),
]);

describe('Consultations/Handover — printing', () => {
    it('keeps the toolbar off the printout', () => {
        expect(mountPage().find('.no-print').exists()).toBe(true);
    });

    it('wraps each service in a block the print stylesheet can keep whole', () => {
        expect(mountPage().findAll('.group-block').length).toBe(1);
    });

    it('ships a print stylesheet that sets an A4 page box and hides the app chrome', () => {
        const src = readFileSync(resolve(__dirname, '../Handover.vue'), 'utf8');
        expect(src).toContain('@page { size: A4; margin: 12mm; }');
        expect(src).toContain('@media print');
        expect(src).toContain('break-inside: avoid');
        expect(src).toContain('table-header-group');
    });

    it('renders the sheet at full page width so columns fit their text', () => {
        expect(mountPage().find('.report').classes()).toContain('max-w-full');
    });

    it('hides the service picker for a single-service viewer', () => {
        expect(mountPage().find('select').exists()).toBe(false);
    });

    it('offers "All services" plus one option per service when several are visible', () => {
        const opts = mountPage(props({ groups: [cardiology(), nephrology()] })).findAll('option').map((o) => o.text());
        expect(opts[0]).toContain('All services');
        expect(opts.some((t) => t.includes('Cardiology'))).toBe(true);
        expect(opts.some((t) => t.includes('Nephrology'))).toBe(true);
    });

    it('selecting one service drops the others from the printout and labels the header', async () => {
        const w = mountPage(props({ groups: [cardiology(), nephrology()] }));
        await w.find('select').setValue('Nephrology');
        const report = w.find('.report').text();
        expect(report).toContain('Renal Patient');
        expect(report).not.toContain('Ward Patient');
        expect(report).toContain('— Nephrology');
    });

    it('the header totals track the selection', async () => {
        const w = mountPage(props({ groups: [cardiology(), nephrology()] }));
        expect(w.text()).toContain('3 open consult(s)');
        await w.find('select').setValue('Nephrology');
        expect(w.text()).toContain('1 open consult(s)');
    });
});

// DATA-CLASSIFICATION.md §4: every printed sheet carries the bilingual classification label. It is
// print-only (hidden on screen, .print-classification), but the text must still be in the DOM.
describe('Handover — print classification label (DATA-CLASSIFICATION.md §4)', () => {
    it('carries the bilingual SECRET / سري classification line', () => {
        const text = mountPage().find('.print-classification').text();
        expect(text).toContain('SECRET — Patient data');
        expect(text).toContain('سري — بيانات مرضى');
    });
});

describe('Handover is reachable from the Clinical nav', () => {
    // Asserted at source level: mounting AppLayout.vue would mean stubbing Inertia's Head/Link/
    // router/usePage plus the tour, session-timeout and recent-patient modules for what is a
    // one-line nav registration. The route string is the thing that must not silently disappear.
    it('registers the handover route in AppLayout', () => {
        const src = readFileSync(resolve(__dirname, '../../../Layouts/AppLayout.vue'), 'utf8');
        expect(src).toContain("href: '/consultations/handover'");
    });
});
