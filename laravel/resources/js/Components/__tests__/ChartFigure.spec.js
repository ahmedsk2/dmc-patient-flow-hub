import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import ChartFigure from '@/Components/ChartFigure.vue';

const COLS = ['Day', 'Admissions', 'Discharges'];
const ROWS = [['Mon', 3, 1], ['Tue', 5, 2], ['Wed', 2, 4]];

// The default slot stands in for the caller's <apexchart>, keeping its own role="img".
const mountFig = (props = {}, slots = {}) =>
    mount(ChartFigure, {
        props: { title: 'Admissions vs Discharges', columns: COLS, rows: ROWS, ...props },
        slots: { default: '<div class="chart" role="img" aria-label="area chart"></div>', ...slots },
    });

describe('ChartFigure', () => {
    it('renders the default slot (the chart) inside a <figure>, untouched', () => {
        const w = mountFig();
        const chart = w.find('figure .chart');
        expect(chart.exists()).toBe(true);
        expect(chart.attributes('role')).toBe('img');   // caller's own role is left alone
    });

    it('uses the caption as the figcaption text when provided', () => {
        const w = mountFig({ caption: 'Admissions outpaced discharges all week.' });
        expect(w.find('figcaption').text()).toBe('Admissions outpaced discharges all week.');
        expect(w.find('figcaption').classes()).toContain('sr-only');
    });

    it('falls back to the title for the figcaption when no caption is given', () => {
        expect(mountFig().find('figcaption').text()).toBe('Admissions vs Discharges');
    });

    it('builds a visually-hidden data table: caption=title, scoped headers, one row per data row', () => {
        const table = mountFig().find('table');
        expect(table.exists()).toBe(true);
        expect(table.classes()).toContain('sr-only');
        expect(table.find('caption').text()).toBe('Admissions vs Discharges');
        const headers = table.findAll('thead th');
        expect(headers.map((h) => h.text())).toEqual(COLS);
        expect(headers.every((h) => h.attributes('scope') === 'col')).toBe(true);
        expect(table.findAll('tbody tr')).toHaveLength(ROWS.length);
    });

    it('renders each cell value in order', () => {
        const cells = mountFig().findAll('tbody tr')[0].findAll('td').map((td) => td.text());
        expect(cells).toEqual(['Mon', '3', '1']);
    });

    it('shows the default empty-state note and NO table when rows is empty', () => {
        const w = mountFig({ rows: [] });
        expect(w.find('table').exists()).toBe(false);
        expect(w.text()).toContain('No data for this period.');
    });

    it('lets the caller override the empty state via the #empty slot', () => {
        const w = mountFig({ rows: [] }, { empty: 'Nothing admitted this week.' });
        expect(w.find('table').exists()).toBe(false);
        expect(w.text()).toContain('Nothing admitted this week.');
    });
});
