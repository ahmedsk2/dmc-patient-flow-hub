import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * IcdTypeahead — the ICD-10 picker behind every diagnosis field (admission, modify, registry).
 * Until 2026-09-22 no spec loaded it: every page spec vi.mock()s it, and vitest 3 misreported it as
 * 100% covered. The contract:
 *   • no lookup under two characters; a 250 ms debounce collapses a burst of keystrokes into ONE
 *     request for the final, trimmed, URL-encoded term;
 *   • combobox a11y — role="combobox" / "listbox" / "option", aria-expanded and aria-selected;
 *   • ArrowUp/ArrowDown move a bounded highlight, Enter picks it, mousedown picks a row, the first
 *     Esc closes only the dropdown (and must not bubble to close the surrounding modal), blur closes;
 *   • a STALE answer is never shown. Lookups race: one typed earlier can resolve later, and one can
 *     resolve after the user has already picked, cleared or left the field. Showing it would put the
 *     wrong diagnoses under the cursor of a clinician about to press Enter.
 */
import IcdTypeahead from '@/Components/IcdTypeahead.vue';

const rows = (...codes) => codes.map((c) => ({ code: c, name: `Name ${c}` }));
const ok = (data) => ({ ok: true, status: 200, json: async () => data });

// A fetch whose responses the test releases one by one, in any order.
let calls;
const controlledFetch = () => {
    calls = [];
    vi.stubGlobal('fetch', vi.fn((url, opts) => new Promise((resolve, reject) => {
        calls.push({ url, opts, resolve, reject });
    })));
};

const mountIt = () => mount(IcdTypeahead, { attachTo: document.body });
const input = (w) => w.get('input[role="combobox"]');
const type = async (w, text) => { await input(w).setValue(text); };
const settle = async (ms = 260) => { await vi.advanceTimersByTimeAsync(ms); await flushPromises(); };
const answer = async (i, data) => { calls[i].resolve(ok(data)); await flushPromises(); };
const options = (w) => w.findAll('[role="option"]');

beforeEach(() => { vi.useFakeTimers(); controlledFetch(); });
afterEach(() => { vi.runOnlyPendingTimers(); vi.useRealTimers(); vi.unstubAllGlobals(); document.body.innerHTML = ''; });

describe('lookup', () => {
    it('does not look anything up for fewer than two characters', async () => {
        const w = mountIt();
        await type(w, 'd');
        await settle();
        await type(w, ' e ');          // trims to one character
        await settle();
        expect(calls).toHaveLength(0);
        w.unmount();
    });

    it('debounces a burst of keystrokes into one request for the final term', async () => {
        const w = mountIt();
        await type(w, 'di');
        await vi.advanceTimersByTimeAsync(100);
        await type(w, 'dia');
        await vi.advanceTimersByTimeAsync(100);
        await type(w, 'diab');
        await settle();
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe('/api/icd10?q=diab');
        w.unmount();
    });

    it('trims and URL-encodes the term and asks for JSON', async () => {
        const w = mountIt();
        await type(w, '  a&b c  ');
        await settle();
        expect(calls[0].url).toBe('/api/icd10?q=a%26b%20c');
        expect(calls[0].opts.headers.Accept).toBe('application/json');
        w.unmount();
    });

    // RES-01: every lookup carries a bounded AbortSignal so a stalled network can't leave the field
    // waiting forever (see the "timeout" case under "failures" for what happens when it fires).
    it('sends an AbortSignal with every lookup', async () => {
        const w = mountIt();
        await type(w, 'dia');
        await settle();
        expect(calls[0].opts.signal).toBeInstanceOf(AbortSignal);
        expect(calls[0].opts.signal.aborted).toBe(false);
        w.unmount();
    });
});

describe('dropdown and keyboard', () => {
    const open = async (w, data = rows('E10', 'E11', 'E13')) => {
        await type(w, 'e1');
        await settle();
        await answer(0, data);
    };

    it('renders the results as options with the first one highlighted', async () => {
        const w = mountIt();
        expect(input(w).attributes('aria-expanded')).toBe('false');
        await open(w);
        expect(w.find('[role="listbox"]').exists()).toBe(true);
        expect(options(w)).toHaveLength(3);
        expect(options(w)[0].attributes('aria-selected')).toBe('true');
        expect(options(w)[0].text()).toContain('E10');
        expect(input(w).attributes('aria-expanded')).toBe('true');
        w.unmount();
    });

    it('moves the highlight with the arrow keys and keeps it inside the list', async () => {
        const w = mountIt();
        await open(w);
        await input(w).trigger('keydown', { key: 'ArrowUp' });            // already at the top
        expect(options(w)[0].attributes('aria-selected')).toBe('true');
        await input(w).trigger('keydown', { key: 'ArrowDown' });
        await input(w).trigger('keydown', { key: 'ArrowDown' });
        await input(w).trigger('keydown', { key: 'ArrowDown' });          // one past the end
        expect(options(w)[2].attributes('aria-selected')).toBe('true');
        await input(w).trigger('keydown', { key: 'ArrowUp' });
        expect(options(w)[1].attributes('aria-selected')).toBe('true');
        w.unmount();
    });

    it('Enter picks the highlighted diagnosis, clears the field and closes', async () => {
        const w = mountIt();
        await open(w);
        await input(w).trigger('keydown', { key: 'ArrowDown' });
        await input(w).trigger('keydown', { key: 'Enter' });
        expect(w.emitted('select')).toEqual([[{ code: 'E11', name: 'Name E11' }]]);
        expect(input(w).element.value).toBe('');
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });

    it('mousedown on a row picks it; hovering moves the highlight', async () => {
        const w = mountIt();
        await open(w);
        await options(w)[2].trigger('mouseenter');
        expect(options(w)[2].attributes('aria-selected')).toBe('true');
        await options(w)[2].trigger('mousedown');
        expect(w.emitted('select')).toEqual([[{ code: 'E13', name: 'Name E13' }]]);
        w.unmount();
    });

    it('the first Esc closes only the dropdown and does not bubble to the modal', async () => {
        const w = mountIt();
        const outer = vi.fn();
        document.body.addEventListener('keydown', outer);
        await open(w);
        await input(w).trigger('keydown', { key: 'Escape' });
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        expect(outer).not.toHaveBeenCalled();
        // with nothing open, a second Esc is left alone so the modal can close
        await input(w).trigger('keydown', { key: 'Escape' });
        expect(outer).toHaveBeenCalledTimes(1);
        document.body.removeEventListener('keydown', outer);
        w.unmount();
    });

    it('ignores the keys when there is nothing to choose, and closes on blur', async () => {
        const w = mountIt();
        await input(w).trigger('keydown', { key: 'Enter' });
        expect(w.emitted('select')).toBeUndefined();
        await open(w);
        await input(w).trigger('blur');
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });
});

describe('stale answers are never shown', () => {
    it('an earlier lookup that resolves LATE does not overwrite the newer results', async () => {
        const w = mountIt();
        await type(w, 'dia');
        await settle();                         // lookup #0 in flight for "dia"
        await type(w, 'diab');
        await settle();                         // lookup #1 in flight for "diab"
        await answer(1, rows('E11'));           // the newer one answers first
        await answer(0, rows('D50', 'D51'));    // then the stale one
        expect(options(w).map((o) => o.text())).toEqual([expect.stringContaining('E11')]);
        w.unmount();
    });

    it('an answer that arrives after a pick does not reopen the dropdown', async () => {
        const w = mountIt();
        await type(w, 'dia');
        await settle();
        await answer(0, rows('E10'));
        await type(w, 'diab');
        await settle();                         // lookup #1 in flight
        await input(w).trigger('keydown', { key: 'Enter' });   // picks E10 from the open list
        expect(w.emitted('select')).toHaveLength(1);
        await answer(1, rows('E11', 'E13'));    // the lookup the pick made obsolete
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        // ...so a reflexive second Enter adds nothing
        await input(w).trigger('keydown', { key: 'Enter' });
        expect(w.emitted('select')).toHaveLength(1);
        w.unmount();
    });

    it('an answer that arrives after the field was left does not reopen the dropdown', async () => {
        const w = mountIt();
        await type(w, 'dia');
        await settle();
        await input(w).trigger('blur');
        await answer(0, rows('E10'));
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });

    it('an answer for a term the user has since cleared is dropped', async () => {
        const w = mountIt();
        await type(w, 'dia');
        await settle();
        await type(w, '');
        await answer(0, rows('E10'));
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });
});

describe('failures', () => {
    it('a failed request shows no dropdown and does not throw', async () => {
        const w = mountIt();
        await type(w, 'dia');
        await settle();
        calls[0].reject(new TypeError('Failed to fetch'));
        await flushPromises();
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });

    it('a non-OK response (expired session, server error) shows no dropdown', async () => {
        const w = mountIt();
        await type(w, 'dia');
        await settle();
        calls[0].resolve({ ok: false, status: 419, json: async () => ({ message: 'CSRF token mismatch.' }) });
        await flushPromises();
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });

    // RES-01: a real AbortSignal.timeout() rejects the fetch with a TimeoutError DOMException, not a
    // TypeError — it must be treated exactly like any other failed lookup: no dropdown, no throw.
    it('a timed-out lookup (AbortSignal fires) shows no dropdown and does not throw', async () => {
        const w = mountIt();
        await type(w, 'dia');
        await settle();
        calls[0].reject(new DOMException('The operation timed out.', 'TimeoutError'));
        await flushPromises();
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });

    // A timeout that fires AFTER the user already typed on / picked / left must not reopen or
    // disturb anything — same generation-guard contract as a slow, eventually-successful answer.
    it('a timed-out lookup that resolves late (after a newer query) is dropped, not shown', async () => {
        const w = mountIt();
        await type(w, 'dia');
        await settle();                         // lookup #0 in flight for "dia"
        await type(w, 'diab');
        await settle();                         // lookup #1 in flight for "diab"
        await answer(1, rows('E11'));
        calls[0].reject(new DOMException('The operation timed out.', 'TimeoutError'));
        await flushPromises();
        expect(options(w).map((o) => o.text())).toEqual([expect.stringContaining('E11')]);
        w.unmount();
    });
});
