<script setup>
import { useId, computed } from 'vue';
import IcdTypeahead from '@/Components/IcdTypeahead.vue';
import DxChips from '@/Components/DxChips.vue';
import ErrorSummary from '@/Components/ErrorSummary.vue';
import { FIELD, ADMIT_FROM_OPTIONS, consultantOptions } from '@/lib/ui.js';

/**
 * Canonical Modify-patient form grid (Wave 3, Item 3). Renders the demographic + admission-fact +
 * diagnosis grid v-model'd onto the reactive `form` from usePatientEdit. The three Modify modals
 * (Patients/Index, Admissions/Index, Registry/Index) all render THIS now — resolving the Registry
 * free-text-nationality drift (it gets the canonical select-with-legacy-option like the others).
 *
 * Nationality uses a select; a value not present in `countries` is kept selectable as a
 * "(legacy)" option so dirty legacy data survives without a migration (validate-only-on-change).
 *
 * The consultant select is shown only when `consultants` is provided (omit it at admit time).
 * `fieldClass` lets a caller pass its exact input class (Registry uses the focus-ring variant) so
 * appearance is unchanged per page; default is the shared FIELD constant.
 *
 * Error summary + focus-jump ids (Wave 3, Item 4): every field carries a stable per-instance `id`
 * (prefixed with useId() so multiple mounted instances never collide) with `aria-describedby`
 * pointing at its own field-level message. An <ErrorSummary> sits at the TOP of the grid, built
 * from the SAME form.errors map keyed onto these ids, so a save failure focus-jumps straight to
 * the offending field — this lands in every host page's Modify modal for free, with no changes
 * needed on their side. Field-level messages are unchanged/additive.
 */
const props = defineProps({
    form: { type: Object, required: true },
    selectedDx: { type: Array, default: () => [] },
    countries: { type: Array, default: () => [] },
    consultants: { type: Array, default: null },     // null → hide the consultant field
    today: { type: String, default: '' },
    admitFromOptions: { type: Array, default: () => ADMIT_FROM_OPTIONS },
    fieldClass: { type: String, default: FIELD },
});
const emit = defineEmits(['add-dx', 'remove-dx']);

// on-service consultants for the select; the current assignee stays selectable (keepId)
const consultantList = () => consultantOptions(props.consultants || [], { keepId: props.form.consultant_id });
const uid = useId();
const dlId = `admit-from-${uid}`;
const fid = (name) => `patient-form-${uid}-${name}`;

// form.errors keys are form-data field names (mrn, name, …) — map them onto this instance's DOM
// ids so ErrorSummary's focus-jump links resolve to the actual inputs below.
const summaryErrors = computed(() => Object.fromEntries(
    Object.entries(props.form.errors || {}).map(([key, message]) => [fid(key), message]),
));
</script>

<template>
    <ErrorSummary :errors="summaryErrors" />
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div><label :for="fid('mrn')" class="mb-1 block text-sm font-semibold text-ink-700">MRN</label><input :id="fid('mrn')" v-model="form.mrn" inputmode="numeric" autocomplete="off" :aria-describedby="form.errors.mrn ? fid('mrn') + '-err' : undefined" :class="[fieldClass, form.errors.mrn && 'border-danger-500']" /><p v-if="form.errors.mrn" :id="fid('mrn') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.mrn }}</p></div>
        <div><label :for="fid('bed')" class="mb-1 block text-sm font-semibold text-ink-700">Bed</label><input :id="fid('bed')" v-model="form.bed" :aria-describedby="form.errors.bed ? fid('bed') + '-err' : undefined" :class="fieldClass" /><p v-if="form.errors.bed" :id="fid('bed') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.bed }}</p></div>
        <div class="col-span-2"><label :for="fid('name')" class="mb-1 block text-sm font-semibold text-ink-700">Name</label><input :id="fid('name')" v-model="form.name" :aria-describedby="form.errors.name ? fid('name') + '-err' : undefined" :class="[fieldClass, form.errors.name && 'border-danger-500']" /><p v-if="form.errors.name" :id="fid('name') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.name }}</p></div>
        <div><label :for="fid('age')" class="mb-1 block text-sm font-semibold text-ink-700">Age</label><input :id="fid('age')" v-model="form.age" inputmode="numeric" :aria-describedby="form.errors.age ? fid('age') + '-err' : undefined" :class="fieldClass" /><p v-if="form.errors.age" :id="fid('age') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.age }}</p></div>
        <div><label :for="fid('gender')" class="mb-1 block text-sm font-semibold text-ink-700">Gender</label><select :id="fid('gender')" v-model="form.gender" :aria-describedby="form.errors.gender ? fid('gender') + '-err' : undefined" :class="fieldClass"><option value="">—</option><option>Male</option><option>Female</option></select><p v-if="form.errors.gender" :id="fid('gender') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.gender }}</p></div>
        <div class="col-span-2"><label :for="fid('nationality')" class="mb-1 block text-sm font-semibold text-ink-700">Nationality</label>
            <select :id="fid('nationality')" v-model="form.nationality" :aria-describedby="form.errors.nationality ? fid('nationality') + '-err' : undefined" :class="fieldClass">
                <option value="">—</option>
                <!-- keep a dirty legacy value selectable (validate-only-on-change) -->
                <option v-if="form.nationality && !countries.includes(form.nationality)" :value="form.nationality">{{ form.nationality }} (legacy)</option>
                <option v-for="c in countries" :key="c">{{ c }}</option>
            </select>
            <p v-if="form.errors.nationality" :id="fid('nationality') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.nationality }}</p></div>
        <div><label :for="fid('admit_date')" class="mb-1 block text-sm font-semibold text-ink-700">Admit date</label><input :id="fid('admit_date')" v-model="form.admit_date" type="date" :max="today" :aria-describedby="form.errors.admit_date ? fid('admit_date') + '-err' : undefined" :class="[fieldClass, form.errors.admit_date && 'border-danger-500']" /><p v-if="form.errors.admit_date" :id="fid('admit_date') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.admit_date }}</p></div>
        <div><label :for="fid('current_location')" class="mb-1 block text-sm font-semibold text-ink-700">Location</label><select :id="fid('current_location')" v-model="form.current_location" :aria-describedby="form.errors.current_location ? fid('current_location') + '-err' : undefined" :class="fieldClass"><option>ER</option><option>Ward</option><option>ICU</option></select><p v-if="form.errors.current_location" :id="fid('current_location') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.current_location }}</p></div>
        <div class="col-span-2"><label :for="fid('admitted_from')" class="mb-1 block text-sm font-semibold text-ink-700">Admitted from</label><input :id="fid('admitted_from')" v-model="form.admitted_from" :list="dlId" placeholder="ER, Clinic, Referral…" :aria-describedby="form.errors.admitted_from ? fid('admitted_from') + '-err' : undefined" :class="fieldClass" /><datalist :id="dlId"><option v-for="o in admitFromOptions" :key="o" :value="o" /></datalist><p v-if="form.errors.admitted_from" :id="fid('admitted_from') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.admitted_from }}</p></div>
        <div v-if="consultants" class="col-span-2"><label :for="fid('consultant_id')" class="mb-1 block text-sm font-semibold text-ink-700">Consultant <span class="font-normal text-ink-400">(quiet change — no “New” badge)</span></label>
            <select :id="fid('consultant_id')" v-model="form.consultant_id" title="On-service consultants only" :aria-describedby="form.errors.consultant_id ? fid('consultant_id') + '-err' : undefined" :class="fieldClass">
                <option value="">— no change —</option>
                <option v-for="c in consultantList()" :key="c.id" :value="c.id">{{ c.name }}{{ !c.on_service ? ' (off service)' : '' }}</option>
            </select>
            <p v-if="form.errors.consultant_id" :id="fid('consultant_id') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.consultant_id }}</p></div>
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-ink-700">Diagnoses</label>
        <IcdTypeahead :input-class="fieldClass" @select="emit('add-dx', $event)" />
        <DxChips :diagnoses="selectedDx" removable @remove="emit('remove-dx', $event)" />
    </div>
</template>
