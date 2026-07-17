<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import BaseModal from '@/Components/BaseModal.vue';
import { useHandover } from '@/composables/useHandover';

/**
 * Per-patient handover editor (Wave 3, Item 4) — extracted verbatim from Patients/Index.vue. On open
 * it fetchHandover()s the body + meta + revision history, shows a read-only view for ALL roles, and
 * an Edit→Save path for canManage (non-Observer) via Inertia useForm.post through useHandover's POST
 * route. History is a collapsible. Open/close + a11y from BaseModal.
 *
 * The stale-fetch guard is preserved: a fetch that resolves after a DIFFERENT patient opened (or the
 * modal closed) is dropped — only the latest-requested patient's data is applied.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    patient: { type: Object, default: null },     // the selected row
    canManage: { type: Boolean, default: false }, // primary consultant / manage / admin → may Edit
    isObserver: { type: Boolean, default: false },
});
const emit = defineEmits(['saved', 'close']);

const { fetchHandover } = useHandover();

// canonical checkpoint shape — used both as the form default and as the fallback when a fetched
// payload's checkpoints are null (no handover row yet) or partial (older revision row).
const defaultCheckpoints = () => ({
    vte_completed: false, ready_for_discharge: false, high_risk: false,
    needs_workup: false, workup_pending: false, code_status: null,
});
const CODE_STATUS_LABEL = { full: 'Full', dnr: 'DNR', dni: 'DNI' };

const data = ref(null);          // null | { body, checkpoints, today, updated_at, updated_by_name, revisions }
const hForm = useForm({ body: '', checkpoints: defaultCheckpoints() });
const editing = ref(false);
const histOpen = ref(false);
let requestId = 0;               // guards against a stale fetch resolving out of order

const fmtAt = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');

// read-view chip row: one chip per SET flag / non-null code_status. Colour tokens are reused
// verbatim from PatientFlags.vue (bg-tint-warning/text-on-warning, bg-tint-danger/text-on-danger)
// and DxChips.vue (bg-brand-100/text-brand-700) — no new Tailwind utilities introduced.
const checkpointChips = computed(() => {
    const cp = data.value?.checkpoints;
    if (!cp) return [];
    const chips = [];
    if (cp.vte_completed) chips.push({ key: 'vte', label: 'VTE', classes: 'bg-brand-100 text-brand-700' });
    if (cp.ready_for_discharge) chips.push({ key: 'dc', label: 'D/C ready', classes: 'bg-brand-100 text-brand-700' });
    if (cp.high_risk) chips.push({ key: 'hr', label: 'High-risk', classes: 'bg-tint-warning text-on-warning' });
    if (cp.needs_workup) chips.push({ key: 'nw', label: 'Needs workup', classes: 'bg-brand-100 text-brand-700' });
    if (cp.workup_pending) chips.push({ key: 'wp', label: 'Workup pending', classes: 'bg-brand-100 text-brand-700' });
    if (cp.code_status) {
        chips.push({
            key: 'cs',
            label: CODE_STATUS_LABEL[cp.code_status] || cp.code_status,
            classes: cp.code_status === 'full' ? 'bg-brand-100 text-brand-700' : 'bg-tint-danger text-on-danger',
        });
    }
    return chips;
});

// open → reset view + fetch; close → tear down. Mirrors the old imperative openHandover/closeHandover.
watch(
    () => [props.open, props.patient?.id],
    async ([open, id]) => {
        if (!open || !props.patient) { data.value = null; return; }
        const my = ++requestId;
        data.value = null; editing.value = false; histOpen.value = false;
        hForm.reset(); hForm.clearErrors();
        const d = await fetchHandover(props.patient.id);
        // drop a fetch that resolved after a different patient opened (or the modal closed)
        if (my === requestId && props.open && props.patient?.id === id) {
            data.value = d;
            hForm.body = d.body || '';
            hForm.checkpoints = { ...defaultCheckpoints(), ...(d.checkpoints || {}) };
        }
    },
    { immediate: true },
);

const close = () => emit('close');
const submitHandover = () => hForm.post(`/admissions/${props.patient.id}/handover`, {
    preserveScroll: true, preserveState: true, onSuccess: () => emit('saved'),
});

defineExpose({ data, hForm, editing, histOpen, submitHandover, checkpointChips });
</script>

<template>
    <BaseModal :open="open" title="Handover" :subtitle="patient ? `${patient.name} · MRN ${patient.mrn}` : ''" size="lg" tall @close="close">
        <template v-if="patient">
            <p v-if="!data" class="py-6 text-center text-sm text-ink-400">Loading…</p>
            <template v-else>
                <p class="mb-2 text-xs text-ink-400">
                    {{ data.updated_at ? `Last updated by ${data.updated_by_name || '—'} · ${fmtAt(data.updated_at)}` : 'No handover yet.' }}
                    <span v-if="data.updated_at" class="ml-1 rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="data.today ? 'bg-brand-100 text-brand-700' : 'bg-tint-warning text-on-warning'">{{ data.today ? 'Updated today' : 'Stale' }}</span>
                </p>
                <template v-if="editing">
                    <div class="mb-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm text-ink-600 sm:grid-cols-3">
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="hForm.checkpoints.vte_completed" class="rounded text-brand-600" /> VTE prophylaxis</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="hForm.checkpoints.ready_for_discharge" class="rounded text-brand-600" /> Ready for discharge</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="hForm.checkpoints.high_risk" class="rounded text-brand-600" /> High-risk</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="hForm.checkpoints.needs_workup" class="rounded text-brand-600" /> Needs more workup</label>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="hForm.checkpoints.workup_pending" class="rounded text-brand-600" /> Workup pending</label>
                        <label class="flex items-center gap-2">Code status
                            <select v-model="hForm.checkpoints.code_status" aria-label="Code status" class="rounded-lg border border-ink-200 px-2 py-1 text-xs outline-none focus:border-brand-500">
                                <option :value="null">None</option>
                                <option value="full">Full</option>
                                <option value="dnr">DNR</option>
                                <option value="dni">DNI</option>
                            </select>
                        </label>
                    </div>
                    <textarea v-model="hForm.body" rows="6" maxlength="5000" aria-label="Handover text" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>
                    <p v-if="hForm.errors.body" class="mt-1 text-xs text-on-danger">{{ hForm.errors.body }}</p>
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" @click="editing = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                        <button type="button" @click="submitHandover" :disabled="hForm.processing || !hForm.body.trim()" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Save</button>
                    </div>
                </template>
                <template v-else>
                    <div v-if="checkpointChips.length" class="mb-2 flex flex-wrap gap-1.5">
                        <span v-for="c in checkpointChips" :key="c.key" class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold" :class="c.classes">{{ c.label }}</span>
                    </div>
                    <p class="whitespace-pre-wrap rounded-xl bg-app/70 px-3 py-2.5 text-sm leading-relaxed text-ink-700">{{ data.body || 'No handover text recorded.' }}</p>
                    <div class="mt-3 flex items-center justify-between">
                        <button v-if="data.revisions?.length" type="button" @click="histOpen = !histOpen" :aria-expanded="histOpen" class="text-xs font-semibold text-brand-600 hover:underline">{{ histOpen ? 'Hide history' : `History (${data.revisions.length})` }}</button>
                        <span v-else class="text-xs text-ink-400">No history yet.</span>
                        <button v-if="canManage && !isObserver" type="button" @click="editing = true" class="rounded-xl bg-brand-solid px-4 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover">Edit</button>
                    </div>
                    <ul v-if="histOpen" class="mt-3 max-h-56 space-y-2 overflow-auto">
                        <li v-for="(r, i) in data.revisions" :key="i" class="rounded-xl ring-1 ring-line">
                            <p class="rounded-t-xl bg-app/60 px-3 py-1.5 text-[11px] font-semibold text-ink-500">{{ r.author || '—' }} · {{ fmtAt(r.at) }}</p>
                            <p class="whitespace-pre-wrap px-3 py-2 text-xs leading-relaxed text-ink-600">{{ r.body }}</p>
                        </li>
                    </ul>
                </template>
            </template>
        </template>
    </BaseModal>
</template>
