<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import PatientForm from '@/Components/PatientForm.vue';
import { useConfirm } from '@/composables/useConfirm';
import { usePatientEdit } from '@/composables/usePatientEdit';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { localToday, locTone, consultantOptions, FIELD } from '@/lib/ui.js';

const { ask } = useConfirm();

// modals now use BaseModal, which owns ONE useModalA11y() instance each (focus-trap + Esc).

const props = defineProps({ queue: Array, icuPatients: Array, consultants: Array, countries: Array });

const page = usePage();
const me = computed(() => page.props.auth.user);
const canAssign = computed(() => me.value.role !== 5 && (me.value.is_admin || me.value.can.assign));   // observers never see assign controls
// K1-9: ANY clinical role may self-assign (legacy Q1 — a registrar self-assigning is normal);
// the page itself is denied to observers, and the server re-checks
const canSelfAssign = computed(() => me.value.role !== 5);
const canAdd = computed(() => me.value.is_admin || me.value.can.add);
const canModify = computed(() => me.value.is_admin || me.value.can.modify);

// group the queue by admission date
const byDate = computed(() => {
    const groups = {};
    for (const p of props.queue) {
        (groups[p.admit_date || 'Undated'] ||= []).push(p);
    }
    return Object.entries(groups);
});
const dayName = (d) => d && d !== 'Undated' ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long' }) : '';

const shuffle = async () => { if (await ask('Auto-assign unassigned patients', 'Distribute all unassigned patients across the on-service consultants using the balancing shuffle.', 'neutral')) router.post('/admissions/shuffle', {}, { preserveScroll: true }); };

// queue patients are unassigned — the assign-to-primary list offers ON-SERVICE consultants only
// (J1-15a; no current assignee to preserve here, unlike the board's reassign modal)
const onServiceConsultants = computed(() => consultantOptions(props.consultants, { onServiceOnly: true }));

// diagnosis list expand — clicking the "N dx" badge reveals the names (like the board cards)
const dxOpen = ref(null);
const toggleDx = (id) => (dxOpen.value = dxOpen.value === id ? null : id);

// assign-to-primary modal — mark_new defaults UNCHECKED on the queue (the established G1 default).
// Unifying it to the board's checked-default is a New-badge / new-today metric change the spec
// gates behind explicit owner sign-off, so it's left at false pending that call. The rest of Item 5
// (wording, modal title, assign-to-me toast) is applied. Check the box to show the "New" badge.
const assigning = ref(null);
const aForm = useForm({ consultant_id: '', mark_new: false });
const closeAssign = () => { assigning.value = null; };
const openAssign = (p) => { assigning.value = p; aForm.consultant_id = ''; aForm.mark_new = false; };
const submitAssign = () => aForm.post(`/admissions/${assigning.value.id}/assign`, { preserveScroll: true, onSuccess: closeAssign });
const assignToMe = (p) => router.post(`/admissions/${p.id}/assign-to-me`, {}, { preserveScroll: true });

// admission from ICU — dedicated icu-pull endpoint (Add capability; new episode is unassigned)
const showIcu = ref(false);
const openIcu = () => { showIcu.value = true; };
const closeIcu = () => { showIcu.value = false; };
// Wave 2, Item 4: no confirm — the ICU-pull creates a new unassigned queue entry (reversible via
// discharge); the server flashes 'Patient admitted from ICU — now in the assignment queue.'
const fromIcu = (p) => router.post(`/admissions/${p.id}/icu-pull`, {}, { preserveScroll: true, onSuccess: () => (showIcu.value = false) });

// modify a queued (unassigned) patient — reuses the canonical PatientForm + usePatientEdit
const today = localToday();
const { form: mForm, editing, selectedDx: mDx, open: openModify, addDx: mAdd, removeDx: mRemove, submit: submitModify, isDirty: mIsDirty } =
    usePatientEdit({ ask, onSuccess: () => (editing.value = null) });
// Wave 3, Item 1: unsaved-edit guard on both close routes (@close and the footer Cancel).
const { guardedClose: guardModify } = useUnsavedGuard(mIsDirty, ask);
const closeModify = () => guardModify(() => { editing.value = null; });
const fld = FIELD;

// hard delete (admin only — server re-checks)
const destroyAdmission = async (p) => {
    if (await ask('Delete admission',
        `Permanently remove the episode for ${p.name} (MRN ${p.mrn}) and its diagnoses. This cannot be undone.`, 'danger'))
        router.delete(`/admissions/${p.id}`, { preserveScroll: true });
};

</script>

<template>
    <AppLayout title="New Admissions">
        <!-- toolbar -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">
                Awaiting assignment <span class="nums ml-1 text-on-accent">{{ queue.length }}</span>
            </span>
            <div class="ml-auto flex gap-2">
                <button v-if="canAssign && queue.length" @click="shuffle" class="inline-flex items-center gap-1.5 rounded-xl bg-card px-4 py-2 text-sm font-semibold text-ink-600 shadow ring-1 ring-ink-200 transition hover:bg-ink-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>
                    Shuffle / auto-assign
                </button>
                <button v-if="canAdd && icuPatients.length" @click="openIcu" class="inline-flex items-center gap-1.5 rounded-xl bg-card px-4 py-2 text-sm font-semibold text-ink-600 shadow ring-1 ring-ink-200 transition hover:bg-ink-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m12.75 15 3-3m0 0-3-3m3 3h-7.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    Admission from ICU <span class="nums text-on-danger">{{ icuPatients.length }}</span>
                </button>
                <Link v-if="canAdd" href="/admissions/create" class="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Admit patient
                </Link>
            </div>
        </div>

        <!-- empty -->
        <div v-if="!queue.length" class="rounded-2xl bg-card p-12 text-center shadow-card ring-1 ring-line">
            <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-success-100 text-success-600">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <p class="font-semibold text-ink-700">No unassigned admissions.</p>
            <p class="mt-1 text-sm text-ink-400">New patients you admit appear here until they're assigned to a consultant (or shuffled).</p>
        </div>

        <!-- queue grouped by admit date -->
        <div v-for="[date, patients] in byDate" :key="date" class="mb-5">
            <h3 class="mb-2 text-sm font-semibold text-ink-500">{{ dayName(date) }} <span class="text-ink-400">— {{ date }}</span> <span class="text-ink-300">({{ patients.length }})</span></h3>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div v-for="p in patients" :key="p.id" class="rounded-2xl bg-card p-4 shadow-card ring-1 ring-line">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="font-semibold text-ink-800">{{ p.name }}</div>
                            <div class="nums text-xs text-ink-400">MRN {{ p.mrn }}</div>
                        </div>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="locTone(p.location)">{{ p.location || '—' }}</span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink-500">
                        <span class="nums">{{ p.age ?? '—' }}y · {{ (p.gender || '—').slice(0,1) }}</span>
                        <span>Bed {{ p.bed || '—' }}</span>
                        <span>from {{ p.admitted_from || '—' }}</span>
                        <button v-if="p.dx_count" type="button" @click="toggleDx(p.id)" :aria-expanded="dxOpen === p.id"
                            :aria-label="`${p.dx_count} diagnoses — ${dxOpen === p.id ? 'hide' : 'show'} names`"
                            class="rounded-full px-2 py-0.5 font-semibold transition"
                            :class="dxOpen === p.id ? 'bg-brand-100 text-brand-700' : 'bg-ink-50 hover:bg-ink-100'">{{ p.dx_count }} dx</button>
                    </div>
                    <ul v-if="dxOpen === p.id && p.diagnoses?.length" class="mt-1.5 space-y-0.5 rounded-lg bg-app/70 px-2 py-1.5 text-[11px] leading-snug text-ink-600">
                        <li v-for="d in p.diagnoses" :key="d.code"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> {{ d.name }}</li>
                    </ul>
                    <div class="mt-3 flex items-center gap-2 border-t border-ink-50 pt-3">
                        <button v-if="canModify" @click="openModify(p)" title="Edit details" class="grid h-7 w-8 shrink-0 place-items-center rounded-lg text-ink-500 ring-1 ring-ink-200 hover:bg-ink-50"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg></button>
                        <button v-if="me.is_admin" @click="destroyAdmission(p)" title="Delete admission" aria-label="Delete admission" class="grid h-7 w-8 shrink-0 place-items-center rounded-lg text-ink-500 ring-1 ring-ink-200 hover:bg-danger-100 hover:text-danger-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg></button>
                        <button v-if="canAssign" @click="openAssign(p)" class="flex-1 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">Assign to primary</button>
                        <button v-if="canSelfAssign" @click="assignToMe(p)" class="flex-1 rounded-lg bg-card px-3 py-1.5 text-xs font-semibold text-brand-700 ring-1 ring-brand-200 hover:bg-brand-50">Assign to me</button>
                        <span v-if="!canAssign && !canSelfAssign && !canModify" class="text-xs text-ink-300">awaiting assignment</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- assign modal -->
        <BaseModal :open="!!assigning" :title="'Assign consultant'" :subtitle="assigning ? `${assigning.name} · MRN ${assigning.mrn}` : ''" size="md" field-first :closable="false" @close="closeAssign">
            <form @submit.prevent="submitAssign" class="space-y-4">
                <select v-model="aForm.consultant_id" title="On-service consultants only" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500">
                    <option value="">Select consultant…</option>
                    <option v-for="c in onServiceConsultants" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="aForm.mark_new" class="rounded text-brand-600" /> Mark as new patient <span class="text-xs text-ink-400">(check to show the “New” badge)</span></label>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="closeAssign" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                    <button type="submit" :disabled="aForm.processing || !aForm.consultant_id" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Assign</button>
                </div>
            </form>
        </BaseModal>

        <!-- admission-from-ICU modal -->
        <BaseModal :open="showIcu" title="Admit from ICU" size="2xl" tall @close="closeIcu">
            <p class="mb-3 text-sm text-ink-400">Pull a current ICU patient onto the ward — they enter the assignment queue for a (new) consultant.</p>
            <table class="w-full text-sm">
                <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400"><th scope="col" class="px-3 py-2">MRN</th><th scope="col" class="px-3 py-2">Patient</th><th scope="col" class="px-3 py-2">Bed</th><th scope="col" class="px-3 py-2">Consultant</th><th scope="col" class="px-3 py-2"></th></tr></thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="p in icuPatients" :key="p.id" class="hover:bg-brand-50/40">
                        <td class="nums px-3 py-2 text-ink-500">{{ p.mrn }}</td>
                        <td class="px-3 py-2 font-semibold text-ink-800">{{ p.name }}</td>
                        <td class="px-3 py-2 text-ink-600">{{ p.bed || '—' }}</td>
                        <td class="px-3 py-2 text-ink-600">{{ p.consultant }}</td>
                        <td class="px-3 py-2 text-right"><button @click="fromIcu(p)" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">To ward</button></td>
                    </tr>
                    <tr v-if="!icuPatients.length"><td colspan="5" class="px-3 py-6 text-center text-ink-400">No ICU patients.</td></tr>
                </tbody>
            </table>
        </BaseModal>

        <!-- modify queued patient modal -->
        <BaseModal :open="!!editing" title="Edit patient" size="lg" tall @close="closeModify">
                <form @submit.prevent="submitModify" class="space-y-3">
                    <PatientForm :form="mForm" :selected-dx="mDx" :countries="countries" :consultants="consultants" :today="today" :field-class="fld" @add-dx="mAdd" @remove-dx="mRemove" />
                    <div class="flex justify-end gap-2 pt-1"><button type="button" @click="closeModify" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="mForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save changes</button></div>
                </form>
        </BaseModal>
    </AppLayout>
</template>
