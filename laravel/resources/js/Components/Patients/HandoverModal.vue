<script setup>
import { ref, watch } from 'vue';
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

const data = ref(null);          // null | { body, today, updated_at, updated_by_name, revisions }
const hForm = useForm({ body: '' });
const editing = ref(false);
const histOpen = ref(false);
let requestId = 0;               // guards against a stale fetch resolving out of order

const fmtAt = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');

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
        if (my === requestId && props.open && props.patient?.id === id) { data.value = d; hForm.body = d.body || ''; }
    },
    { immediate: true },
);

const close = () => emit('close');
const submitHandover = () => hForm.post(`/admissions/${props.patient.id}/handover`, {
    preserveScroll: true, preserveState: true, onSuccess: () => emit('saved'),
});

defineExpose({ data, hForm, editing, histOpen, submitHandover });
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
                    <textarea v-model="hForm.body" rows="6" maxlength="5000" aria-label="Handover text" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>
                    <p v-if="hForm.errors.body" class="mt-1 text-xs text-on-danger">{{ hForm.errors.body }}</p>
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" @click="editing = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                        <button type="button" @click="submitHandover" :disabled="hForm.processing || !hForm.body.trim()" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save</button>
                    </div>
                </template>
                <template v-else>
                    <p class="whitespace-pre-wrap rounded-xl bg-app/70 px-3 py-2.5 text-sm leading-relaxed text-ink-700">{{ data.body || 'No handover text recorded.' }}</p>
                    <div class="mt-3 flex items-center justify-between">
                        <button v-if="data.revisions?.length" type="button" @click="histOpen = !histOpen" :aria-expanded="histOpen" class="text-xs font-semibold text-brand-600 hover:underline">{{ histOpen ? 'Hide history' : `History (${data.revisions.length})` }}</button>
                        <span v-else class="text-xs text-ink-400">No history yet.</span>
                        <button v-if="canManage && !isObserver" type="button" @click="editing = true" class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Edit</button>
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
