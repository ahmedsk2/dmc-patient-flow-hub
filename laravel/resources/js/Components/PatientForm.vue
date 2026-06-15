<script setup>
import { useId } from 'vue';
import IcdTypeahead from '@/Components/IcdTypeahead.vue';
import DxChips from '@/Components/DxChips.vue';
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
const dlId = `admit-from-${useId()}`;
</script>

<template>
    <div class="grid grid-cols-2 gap-3">
        <div><label class="mb-1 block text-sm font-semibold text-ink-700">MRN</label><input v-model="form.mrn" inputmode="numeric" :class="[fieldClass, form.errors.mrn && 'border-danger-500']" /><p v-if="form.errors.mrn" class="mt-1 text-xs text-danger-600">{{ form.errors.mrn }}</p></div>
        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Bed</label><input v-model="form.bed" :class="fieldClass" /><p v-if="form.errors.bed" class="mt-1 text-xs text-danger-600">{{ form.errors.bed }}</p></div>
        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Name</label><input v-model="form.name" :class="[fieldClass, form.errors.name && 'border-danger-500']" /><p v-if="form.errors.name" class="mt-1 text-xs text-danger-600">{{ form.errors.name }}</p></div>
        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Age</label><input v-model="form.age" inputmode="numeric" :class="fieldClass" /><p v-if="form.errors.age" class="mt-1 text-xs text-danger-600">{{ form.errors.age }}</p></div>
        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Gender</label><select v-model="form.gender" :class="fieldClass"><option value="">—</option><option>Male</option><option>Female</option></select><p v-if="form.errors.gender" class="mt-1 text-xs text-danger-600">{{ form.errors.gender }}</p></div>
        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Nationality</label>
            <select v-model="form.nationality" :class="fieldClass">
                <option value="">—</option>
                <!-- keep a dirty legacy value selectable (validate-only-on-change) -->
                <option v-if="form.nationality && !countries.includes(form.nationality)" :value="form.nationality">{{ form.nationality }} (legacy)</option>
                <option v-for="c in countries" :key="c">{{ c }}</option>
            </select>
            <p v-if="form.errors.nationality" class="mt-1 text-xs text-danger-600">{{ form.errors.nationality }}</p></div>
        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Admit date</label><input v-model="form.admit_date" type="date" :max="today" :class="[fieldClass, form.errors.admit_date && 'border-danger-500']" /><p v-if="form.errors.admit_date" class="mt-1 text-xs text-danger-600">{{ form.errors.admit_date }}</p></div>
        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Location</label><select v-model="form.current_location" :class="fieldClass"><option>ER</option><option>Ward</option><option>ICU</option></select><p v-if="form.errors.current_location" class="mt-1 text-xs text-danger-600">{{ form.errors.current_location }}</p></div>
        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Admitted from</label><input v-model="form.admitted_from" :list="dlId" placeholder="ER, Clinic, Referral…" :class="fieldClass" /><datalist :id="dlId"><option v-for="o in admitFromOptions" :key="o" :value="o" /></datalist><p v-if="form.errors.admitted_from" class="mt-1 text-xs text-danger-600">{{ form.errors.admitted_from }}</p></div>
        <div v-if="consultants" class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Consultant <span class="font-normal text-ink-400">(quiet change — no “New” badge)</span></label>
            <select v-model="form.consultant_id" title="On-service consultants only" :class="fieldClass">
                <option value="">— no change —</option>
                <option v-for="c in consultantList()" :key="c.id" :value="c.id">{{ c.name }}{{ !c.on_service ? ' (off service)' : '' }}</option>
            </select>
            <p v-if="form.errors.consultant_id" class="mt-1 text-xs text-danger-600">{{ form.errors.consultant_id }}</p></div>
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-ink-700">Diagnoses</label>
        <IcdTypeahead :input-class="fieldClass" @select="emit('add-dx', $event)" />
        <DxChips :diagnoses="selectedDx" removable @remove="emit('remove-dx', $event)" />
    </div>
</template>
