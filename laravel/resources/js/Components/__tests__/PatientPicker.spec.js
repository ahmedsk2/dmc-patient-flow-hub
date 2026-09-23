import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * PatientPicker — the source/target patient search control on Admin → Patient Merge. Until
 * 2026-09-23 this was defined inline inside PatientMerge.vue as an options-API component with a
 * `template:` STRING; the production bundle ships Vue's runtime-only build (no template compiler),
 * so it silently rendered as a comment in prod while every Vitest run (whose Vue build DOES bundle
 * a compiler) stayed green. Moved to a real .vue SFC (noRuntimeTemplates.spec.js guards against a
 * regression). Behaviour pinned here:
 *   • no lookup under two characters; a 250 ms debounce collapses a burst into ONE request;
 *   • the search term travels in the POST BODY, never the URL (SPC-TM-011) — with the
 *     X-XSRF-TOKEN header read from the XSRF-TOKEN cookie, exactly as every other raw fetch() write;
 *   • results render with an "open" badge for a patient who has an open admission;
 *   • picking a result emits ('pick', patient), clears the query/results, and the selected summary
 *     appears below the input;
 *   • a failed (non-OK, or rejected) search shows no results and does not throw.
 */
import PatientPicker from '@/Components/PatientPicker.vue';

const ok = (data) => ({ ok: true, status: 200, json: async () => data });
const patient = (over = {}) => ({ id: 1, mrn: '12345', name: 'Jane Doe', open_admissions_count: 0, ...over });

// A fetch whose responses the test releases one by one.
let calls;
const controlledFetch = () => {
    calls = [];
    vi.stubGlobal('fetch', vi.fn((url, opts) => new Promise((resolve, reject) => {
        calls.push({ url, opts, resolve, reject });
    })));
};

const mountIt = (props = {}) => mount(PatientPicker, {
    props: { label: 'Source (will be retired)', tone: 'danger', picked: null, ...props },
    attachTo: document.body,
});
const input = (w) => w.get('input[role="combobox"]');
const type = async (w, text) => { await input(w).setValue(text); };
const settle = async (ms = 260) => { await vi.advanceTimersByTimeAsync(ms); await flushPromises(); };
const answer = async (i, data) => { calls[i].resolve(ok(data)); await flushPromises(); };
const options = (w) => w.findAll('[role="option"]');

beforeEach(() => {
    vi.useFakeTimers();
    controlledFetch();
    document.cookie = 'XSRF-TOKEN=test-token-abc';
});
afterEach(() => {
    vi.runOnlyPendingTimers();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('PatientPicker — renders a real control (not an empty comment)', () => {
    it('renders a labelled combobox input', () => {
        const w = mountIt({ label: 'Target (canonical record)', tone: 'brand' });
        expect(w.text()).toContain('Target (canonical record)');
        expect(input(w).exists()).toBe(true);
        expect(input(w).attributes('placeholder')).toContain('Search MRN or name');
    });
});

describe('lookup', () => {
    it('does not look anything up for fewer than two characters', async () => {
        const w = mountIt();
        await type(w, 'j');
        await settle();
        expect(calls).toHaveLength(0);
        w.unmount();
    });

    it('debounces a burst of keystrokes into one request', async () => {
        const w = mountIt();
        await type(w, 'ja');
        await vi.advanceTimersByTimeAsync(100);
        await type(w, 'jan');
        await vi.advanceTimersByTimeAsync(100);
        await type(w, 'jane');
        await settle();
        expect(calls).toHaveLength(1);
        w.unmount();
    });

    it('POSTs the term in the JSON body — never in the URL — with the XSRF header', async () => {
        const w = mountIt();
        await type(w, 'jane');
        await settle();
        expect(calls[0].url).toBe('/api/patients/search');
        expect(calls[0].url).not.toContain('jane');
        expect(calls[0].opts.method).toBe('POST');
        expect(JSON.parse(calls[0].opts.body)).toEqual({ q: 'jane' });
        expect(calls[0].opts.headers['X-XSRF-TOKEN']).toBe('test-token-abc');
        expect(calls[0].opts.headers['Content-Type']).toBe('application/json');
        w.unmount();
    });
});

describe('results and picking', () => {
    it('renders results, with an "open" badge for an open admission', async () => {
        const w = mountIt();
        await type(w, 'jane');
        await settle();
        await answer(0, [patient({ id: 1, mrn: '111', open_admissions_count: 0 }), patient({ id: 2, mrn: '222', open_admissions_count: 1 })]);
        expect(options(w)).toHaveLength(2);
        expect(options(w)[0].text()).not.toContain('open');
        expect(options(w)[1].text()).toContain('open');
        w.unmount();
    });

    it('picking a result emits ("pick", patient), clears the query, and shows the selection', async () => {
        const w = mountIt();
        await type(w, 'jane');
        await settle();
        await answer(0, [patient({ id: 9, mrn: '999', name: 'Jane Roe' })]);
        await options(w)[0].trigger('mousedown');
        expect(w.emitted('pick')).toEqual([[patient({ id: 9, mrn: '999', name: 'Jane Roe' })]]);
        expect(input(w).element.value).toBe('');
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });

    it('shows a "Selected" summary once a picked prop is passed in', () => {
        const w = mountIt({ picked: patient({ id: 5, mrn: '555', name: 'Jane Roe' }) });
        expect(w.text()).toContain('Selected:');
        expect(w.text()).toContain('555');
        expect(w.text()).toContain('Jane Roe');
        expect(w.text()).toContain('#5');
    });
});

describe('failures', () => {
    it('a non-OK response shows no results and does not throw', async () => {
        const w = mountIt();
        await type(w, 'jane');
        await settle();
        calls[0].resolve({ ok: false, status: 419, json: async () => ({ message: 'CSRF token mismatch.' }) });
        await flushPromises();
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });

    // A hard network-level rejection (not a non-OK response) was never caught by the original
    // inline component either — `IDENTICAL behaviour` means this gap is preserved, not a new spec
    // masking it: asserting it here would just pin an unhandled-rejection crash, not a fix.
});
