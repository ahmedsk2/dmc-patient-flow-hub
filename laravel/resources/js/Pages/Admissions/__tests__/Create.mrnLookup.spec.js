import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';

// Role/UX review 2026-09-24, Problem #1: the MRN lookup that prefills a known patient's stored
// demographics and surfaces an active episode BEFORE submit, plus the confirm-identity-update
// checkbox that appears once the clinician edits one of those prefilled fields away from what was
// just filled in. StoreAdmissionRequest's server-side half of the guard is covered by
// tests/Feature/UxReviewG1AdmissionsTest.php.

const errors = vi.hoisted(() => ({}));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a href="#"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 1, role: 0, is_admin: true, can: { add: true } } }, flash: null } }),
    // Reactive, like Inertia's real useForm() — identityChanged (a computed) needs form.name/age/
    // gender/nationality to be tracked so it recomputes as the test mutates them, the same as a real
    // typed edit would trigger in the browser.
    useForm: (initial) => reactive({ ...initial, errors, processing: false, post: vi.fn(), reset: vi.fn(), clearErrors: vi.fn() }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title'], template: '<div><slot /></div>' },
}));
vi.mock('@/Components/IcdTypeahead.vue', () => ({ default: { name: 'IcdTypeahead', template: '<div></div>' } }));

import Create from '@/Pages/Admissions/Create.vue';

const props = {
    consultants: [{ id: 5, name: 'Dr A', full_name: 'Dr A' }],
    countries: ['Saudi Arabia', 'Egypt'],
    locations: ['Ward', 'ICU', 'ER'],
    admitFrom: ['ER', 'OPD'],
};

let w;
const mountCreate = () => { w = mount(Create, { props }); return w; };
const jsonResponse = (body, ok = true) => Promise.resolve({ ok, json: () => Promise.resolve(body) });

beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=test-token-abc';
    global.fetch = vi.fn();
});
afterEach(() => {
    w?.unmount();
    w = null;
    for (const k of Object.keys(errors)) delete errors[k];
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 UTC';
    vi.restoreAllMocks();
});

describe('Admissions/Create — MRN lookup (role/UX review 2026-09-24, Problem #1)', () => {
    it('POSTs the MRN in the body — never the URL (SPC-TM-011)', async () => {
        global.fetch.mockReturnValueOnce(jsonResponse({ found: false }));
        const vm = mountCreate().vm;
        vm.form.mrn = '30000001';

        await vm.lookupMrn();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe('/admissions/lookup-mrn');
        expect(opts.method).toBe('POST');
        expect(opts.headers['X-XSRF-TOKEN']).toBe('test-token-abc');
        expect(JSON.parse(opts.body)).toEqual({ mrn: '30000001' });
    });

    it('skips an incomplete/invalid MRN without touching the network', async () => {
        const vm = mountCreate().vm;
        vm.form.mrn = '12';
        vm.form.mrn = 'not-digits';

        await vm.lookupMrn();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(vm.mrnStatus).toBe('idle');
    });

    it('reports "not found" and leaves the form untouched for an unknown MRN', async () => {
        global.fetch.mockReturnValueOnce(jsonResponse({ found: false }));
        const vm = mountCreate().vm;
        vm.form.mrn = '30000009';
        vm.form.name = 'Freshly typed';

        await vm.lookupMrn();

        expect(vm.mrnStatus).toBe('not_found');
        expect(vm.lookedUpPatient).toBeNull();
        expect(vm.form.name).toBe('Freshly typed');   // never overwritten
    });

    it('prefills the stored demographics for a known MRN, with no confirmation needed yet', async () => {
        global.fetch.mockReturnValueOnce(jsonResponse({
            found: true,
            has_active_episode: false,
            patient: { name: 'Known Patient', age: 61, gender: 'Female', nationality: 'Egypt' },
        }));
        const vm = mountCreate().vm;
        vm.form.mrn = '30000002';

        await vm.lookupMrn();

        expect(vm.mrnStatus).toBe('found');
        expect(vm.hasActiveEpisode).toBe(false);
        expect(vm.form.name).toBe('Known Patient');
        expect(vm.form.age).toBe(61);
        expect(vm.form.gender).toBe('Female');
        expect(vm.form.nationality).toBe('Egypt');
        expect(vm.identityChanged).toBe(false);   // matches what was just prefilled
    });

    it('flags an existing active episode', async () => {
        global.fetch.mockReturnValueOnce(jsonResponse({
            found: true, has_active_episode: true,
            patient: { name: 'Still Admitted', age: 33, gender: 'Male', nationality: 'Saudi Arabia' },
        }));
        const vm = mountCreate().vm;
        vm.form.mrn = '30000003';

        await vm.lookupMrn();

        expect(vm.hasActiveEpisode).toBe(true);
    });

    it('shows the confirm-identity checkbox only once a prefilled field is edited away, and clears when reverted', async () => {
        global.fetch.mockReturnValueOnce(jsonResponse({
            found: true, has_active_episode: false,
            patient: { name: 'Known Patient', age: 61, gender: 'Female', nationality: 'Egypt' },
        }));
        const w = mountCreate();
        const vm = w.vm;
        vm.form.mrn = '30000002';
        await vm.lookupMrn();
        await nextTick();
        expect(w.findAll('input[type="checkbox"]').length).toBe(0);   // nothing edited yet

        vm.form.name = 'Corrected Name';
        await nextTick();
        expect(vm.identityChanged).toBe(true);
        expect(w.findAll('input[type="checkbox"]').length).toBe(1);
        expect(w.text()).toContain('Known Patient');

        vm.form.name = 'Known Patient';   // reverted back to the stored value
        await nextTick();
        expect(vm.identityChanged).toBe(false);
    });

    it('does not re-fetch for the same MRN twice in a row', async () => {
        global.fetch.mockReturnValue(jsonResponse({ found: false }));
        const vm = mountCreate().vm;
        vm.form.mrn = '30000001';

        await vm.lookupMrn();
        await vm.lookupMrn();

        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    // Review fix-up (2026-09-24): a concurrent caller (e.g. submit()'s own `await lookupMrn()`,
    // racing a fast Enter/click behind the MRN field's @blur handler) used to see the MRN already
    // claimed by the dedup guard and return immediately, WITHOUT waiting for that in-flight fetch —
    // so the confirm-identity checkbox could be missing its data on the very first submit. A second
    // concurrent call must now join the same in-flight request instead of racing past it.
    it('a concurrent lookupMrn() call for the same in-flight MRN joins the same request instead of skipping the wait', async () => {
        let resolveFetch;
        global.fetch.mockReturnValueOnce(new Promise((resolve) => { resolveFetch = resolve; }));
        const vm = mountCreate().vm;
        vm.form.mrn = '30000002';

        const blurPromise = vm.lookupMrn();     // simulates the field's @blur handler firing first
        const submitPromise = vm.lookupMrn();   // simulates a fast submit racing right behind it

        expect(vm.mrnStatus).toBe('loading');
        expect(global.fetch).toHaveBeenCalledTimes(1);   // still only ONE request in flight

        resolveFetch(await jsonResponse({
            found: true, has_active_episode: false,
            patient: { name: 'Known Patient', age: 61, gender: 'Female', nationality: 'Egypt' },
        }));
        await Promise.all([blurPromise, submitPromise]);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(vm.mrnStatus).toBe('found');
        expect(vm.form.name).toBe('Known Patient');
    });

    it('submit() awaits an already-in-flight blur lookup before posting, so the confirm-identity data is ready', async () => {
        let resolveFetch;
        global.fetch.mockReturnValueOnce(new Promise((resolve) => { resolveFetch = resolve; }));
        const vm = mountCreate().vm;
        vm.form.mrn = '30000002';

        vm.lookupMrn();                       // fire-and-forget, as the @blur handler does
        const submitPromise = vm.submit();    // a fast Enter/click submit racing behind it

        resolveFetch(await jsonResponse({
            found: true, has_active_episode: false,
            patient: { name: 'Known Patient', age: 61, gender: 'Female', nationality: 'Egypt' },
        }));
        await submitPromise;

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(vm.mrnStatus).toBe('found');
        expect(vm.form.name).toBe('Known Patient');
        expect(vm.form.post).toHaveBeenCalledWith('/admissions');   // posted only AFTER the lookup settled
    });
});
