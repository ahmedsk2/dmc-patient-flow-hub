import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

/**
 * Patient Modify (full edit) composable (Wave 3, Item 3). The Modify form was triplicated
 * near-verbatim across Patients/Index, Admissions/Index, and Registry/Index (which had drifted to a
 * free-text nationality). This owns the ONE form + fetch-on-open + addDx/removeDx + identity-confirm
 * submit; PatientForm.vue renders the grid v-model'd to `form`.
 *
 * Uses Inertia's useForm + router-style .post() (the existing pattern — NOT a raw fetch with a
 * method-override) so validation errors and `processing` flow exactly as before. All three callers
 * edit ADMISSIONS, so the edit + submit endpoints default to the shared admission routes; pass
 * overrides if a caller ever needs different ones.
 *
 * @param {Object}   opts
 * @param {Function} opts.ask         the useConfirm() ask() — for the identity-change confirm
 * @param {Function} [opts.onSuccess] called after a successful save (e.g. close the modal)
 * @param {Function} [opts.editEndpoint]   (id) => string, defaults to /admissions/{id}/edit
 * @param {Function} [opts.submitEndpoint] (id) => string, defaults to /admissions/{id}/modify
 */
export function usePatientEdit({ ask, onSuccess = () => {}, editEndpoint, submitEndpoint } = {}) {
    const editUrl = editEndpoint ?? ((id) => `/admissions/${id}/edit`);
    const submitUrl = submitEndpoint ?? ((id) => `/admissions/${id}/modify`);

    const form = useForm({
        mrn: '', name: '', age: '', gender: '', nationality: '', bed: '',
        admit_date: '', admitted_from: '', current_location: 'Ward', consultant_id: '', diagnoses: [],
    });
    const editing = ref(null);       // { id, mrn, name } — the LOADED identity (for the change-confirm)
    const selectedDx = ref([]);      // [{ code, name }] chips
    const activity = ref([]);        // per-patient audit trail (rendered as <ActivityPanel> by the host)

    async function open(p) {
        const d = await (await fetch(editUrl(p.id), { headers: { Accept: 'application/json' } })).json();
        activity.value = d.activity || [];
        // keep the LOADED identity so a changed MRN/name is confirmed before posting (K1-3)
        editing.value = { id: p.id, mrn: d.mrn || '', name: d.name || '' };
        form.clearErrors();
        form.mrn = d.mrn || ''; form.name = d.name || ''; form.age = d.age ?? ''; form.gender = d.gender || '';
        form.nationality = d.nationality || ''; form.bed = d.bed || '';
        form.admit_date = d.admit_date || ''; form.admitted_from = d.admitted_from || ''; form.current_location = d.current_location || 'Ward';
        form.consultant_id = d.consultant_id || '';   // QUIET reassignment, legacy Modify semantics (J2-13)
        selectedDx.value = d.diagnoses || [];
        form.diagnoses = selectedDx.value.map((x) => x.code);
    }

    function addDx(d) {
        if (!selectedDx.value.find((x) => x.code === d.code)) { selectedDx.value.push(d); form.diagnoses.push(d.code); }
    }
    function removeDx(code) {
        selectedDx.value = selectedDx.value.filter((x) => x.code !== code);
        form.diagnoses = form.diagnoses.filter((c) => c !== code);
    }

    // identity confirm (K1-3): an MRN/name edit re-points or renames the patient — make it deliberate
    const identityUnchanged = (loaded, mrn, name) =>
        String(mrn) === String(loaded.mrn) && String(name) === String(loaded.name);

    async function submit() {
        const loaded = editing.value;
        if (!identityUnchanged(loaded, form.mrn, form.name)
            && !(await ask('Change patient identity',
                `Change patient identity from ${loaded.name} (MRN ${loaded.mrn}) to ${form.name} (MRN ${form.mrn})?`, 'danger'))) return;   // declined — no post
        form.post(submitUrl(loaded.id), { preserveScroll: true, onSuccess: () => onSuccess() });
    }

    return { form, editing, selectedDx, activity, open, addDx, removeDx, submit, identityUnchanged };
}
