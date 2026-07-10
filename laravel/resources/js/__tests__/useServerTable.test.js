import { describe, it, expect, vi } from 'vitest';
import { useServerTable } from '@/composables/useServerTable';

// Wave 2 — Item 4: server-authoritative table sort state. Holds {key, dir}, implements the
// none -> desc -> asc -> none cycle (mirrors SortableTh.vue's own cycle so a header's aria-sort
// and the request actually sent never disagree), and produces the request params merged with
// the page's existing filters. The actual navigation is delegated to an INJECTED callback so a
// page can POST (Registry's term-in-body flow, SPC-TM-011) or GET as it sees fit — this
// composable must never hardcode router.get/post itself.
describe('useServerTable', () => {
    it('defaults to an unsorted state', () => {
        const t = useServerTable();
        expect(t.state.key).toBeNull();
        expect(t.state.dir).toBeNull();
    });

    it('accepts an initial sort', () => {
        const t = useServerTable({ key: 'admit_date', dir: 'desc' });
        expect(t.state).toEqual({ key: 'admit_date', dir: 'desc' });
    });

    it('toggle() cycles none -> desc -> asc -> none for the same column', () => {
        const t = useServerTable();
        t.toggle('name');
        expect(t.state).toEqual({ key: 'name', dir: 'desc' });
        t.toggle('name');
        expect(t.state).toEqual({ key: 'name', dir: 'asc' });
        t.toggle('name');
        expect(t.state).toEqual({ key: 'name', dir: null });
        // clicking the SAME column again after reaching none starts the cycle over at desc
        t.toggle('name');
        expect(t.state).toEqual({ key: 'name', dir: 'desc' });
    });

    it('toggling a different column resets straight to desc for the new column', () => {
        const t = useServerTable({ key: 'name', dir: 'asc' });
        t.toggle('admit_date');
        expect(t.state).toEqual({ key: 'admit_date', dir: 'desc' });
    });

    it('params() merges the injected filters() output with the current sort/dir', () => {
        const t = useServerTable({ filters: () => ({ search: 'abc', outcome: 'Alive' }) });
        t.toggle('name');
        expect(t.params()).toEqual({ search: 'abc', outcome: 'Alive', sort: 'name', dir: 'desc' });
    });

    it('params() nulls out sort once the cycle returns to none (no sort override sent)', () => {
        const t = useServerTable();
        t.toggle('name'); t.toggle('name'); t.toggle('name'); // desc -> asc -> none
        expect(t.params()).toEqual({ sort: null, dir: null });
    });

    it('toggle() routes the computed params through the injected navigate callback', () => {
        const navigate = vi.fn();
        const t = useServerTable({ navigate, filters: () => ({ mode: 'admissions' }) });
        t.toggle('name');
        expect(navigate).toHaveBeenCalledTimes(1);
        expect(navigate).toHaveBeenCalledWith({ mode: 'admissions', sort: 'name', dir: 'desc' });
    });

    it('never invokes a global router itself — toggling with no navigate injected is a no-op, not a throw', () => {
        const t = useServerTable();
        expect(() => t.toggle('name')).not.toThrow();
    });
});
