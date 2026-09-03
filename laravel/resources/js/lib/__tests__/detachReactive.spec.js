import { describe, it, expect } from 'vitest';
import { reactive } from 'vue';
import { detachReactive } from '@/lib/chartjs';

// Regression guard for the ApexCharts→Chart.js migration: ChartCanvas hands Chart.js a non-reactive
// copy of data/options. It must NOT drop function-valued config (the old JSON.parse(JSON.stringify)
// clone did — silently breaking donut % formatters, onClick drill-through, and scriptable gradient
// fills, invisibly to jsdom because Chart.js never instantiates there).
describe('detachReactive', () => {
    it('preserves function-valued options (datalabels formatter, onClick, scriptable colour)', () => {
        const fmt = (v) => Math.round(v) + '%';
        const onClick = () => 'drill';
        const bg = () => 'gradient';
        const opts = reactive({ plugins: { datalabels: { formatter: fmt, display: true } }, onClick });
        const data = reactive({ labels: ['a'], datasets: [{ data: [1], backgroundColor: bg }] });

        const o = detachReactive(opts);
        const d = detachReactive(data);

        expect(o.plugins.datalabels.formatter).toBe(fmt);
        expect(o.plugins.datalabels.formatter(12.4)).toBe('12%');
        expect(o.onClick).toBe(onClick);
        expect(d.datasets[0].backgroundColor).toBe(bg);
    });

    it('deep-copies plain objects/arrays so Chart.js never receives a reactive proxy', () => {
        const src = reactive({ a: { b: [1, 2] } });
        const out = detachReactive(src);
        expect(out).toEqual({ a: { b: [1, 2] } });
        expect(out).not.toBe(src);
        expect(out.a).not.toBe(src.a);
        expect(Array.isArray(out.a.b)).toBe(true);
    });

    it('passes primitives and null through', () => {
        expect(detachReactive(null)).toBe(null);
        expect(detachReactive(5)).toBe(5);
        expect(detachReactive('x')).toBe('x');
    });
});
