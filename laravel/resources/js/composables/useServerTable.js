import { reactive } from 'vue';

/**
 * Wave 2 — Item 4: server-authoritative table sort state, shared by any page with a
 * SortableTh-driven table (Registry's admissions/diagnosis + consultations tables today).
 *
 * Holds `{ key, dir }` (dir ∈ 'asc' | 'desc' | null) and implements the SAME none -> desc -> asc
 * -> none cycle that SortableTh.vue computes for its own aria-sort — the two are independent,
 * deliberately duplicated implementations of one algorithm (each unit-tested on its own), so a
 * page can drive the request-building half without mounting any component.
 *
 * The actual navigation is delegated to an INJECTED `navigate` callback rather than a hardcoded
 * `router.get`/`router.post` call: Registry/Index.vue's free-text search term travels in a POST
 * body (SPC-TM-011), so ITS navigate is `router.post`; a GET-only filter page can inject
 * `router.get` instead. This composable never assumes which.
 *
 * @param {Object} [opts]
 * @param {string|null}       [opts.key]      initial active sort column (e.g. from the server's
 *                                            Inertia props, so the header renders correctly on load)
 * @param {'asc'|'desc'|null} [opts.dir]      initial direction
 * @param {Function}          [opts.filters]  () => plain object of the page's CURRENT non-sort
 *                                            filters, merged into every navigate() call
 * @param {Function|null}     [opts.navigate] (params) => void — performs the actual page visit;
 *                                            omit in a context that only needs the computed state
 */
export function useServerTable({ key = null, dir = null, filters = () => ({}), navigate = null } = {}) {
    const state = reactive({ key, dir });

    // none -> desc -> asc -> none for the clicked column; any OTHER column always starts at desc.
    const nextDir = (col) => {
        if (state.key !== col) return 'desc';
        if (state.dir === 'desc') return 'asc';
        if (state.dir === 'asc') return null;
        return 'desc';
    };

    // sort is nulled out alongside dir — a dir-less sort key is meaningless to the server, and
    // keeping it around would let a stale key leak into the next request.
    const params = () => ({ ...filters(), sort: state.dir ? state.key : null, dir: state.dir });

    const toggle = (col) => {
        state.dir = nextDir(col);
        state.key = col;
        const p = params();
        navigate?.(p);
        return p;
    };

    return { state, toggle, params };
}
