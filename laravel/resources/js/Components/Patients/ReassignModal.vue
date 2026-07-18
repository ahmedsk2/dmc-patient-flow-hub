<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { useForm } from '@inertiajs/vue3';
import BaseModal from '@/Components/BaseModal.vue';
import IdentityChip from '@/Components/IdentityChip.vue';
import HandoverCapture from '@/Components/Patients/HandoverCapture.vue';
import { useHandover } from '@/composables/useHandover';
import { useConfirm } from '@/composables/useConfirm';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { consultantOptions, guardSubmit } from '@/lib/ui.js';
import { withCheckpointDefaults } from '@/lib/handover.js';

/**
 * Bulk-reassign modal (Wave 3, Item 4) — extracted verbatim from Patients/Index.vue. Owns the rForm,
 * per-patient selection (selectedIds checkboxes), the handover PREFLIGHT, the per-stale-patient
 * handover editors + save-all-stale + "Uncheck all stale", the "X of Y still need today's note"
 * counter, and the preflightReady gate.
 *
 * THE GATE (soft, HO-T5): Confirm unlocks (`preflightReady === true`) once preflight has loaded and
 * at least one patient is selected — a stale handover no longer blocks the move. Selecting a stale
 * row shows a warning instead: the move proceeds, and persistent reminders go to the mover + outgoing
 * consultant until each incomplete handover is written. Stale rows can still be resolved in-modal
 * (saveAllStale) or dropped from the move set (uncheckAllStale) before confirming. The submit is a
 * SUBSET move — only the checked patients travel.
 *
 * Open/close + a11y from BaseModal; group-header opens it pre-filled (from=this consultant), the
 * toolbar opens it blank — both via openModal(fromId?). On success emits `saved` (Index reloads).
 *
 * Unsaved-changes guard (Wave 3, Item 1/2): `modalDirty` reads rForm.isDirty (real Inertia
 * tracking); passed to BaseModal as `:dirty` and reused for the Cancel button via the SAME
 * useUnsavedGuard instance, so Esc/backdrop/X/Cancel all behave identically.
 *
 * Double-submit guard (Item 5): submitReassign is wrapped in guardSubmit() alongside the existing
 * `:disabled="rForm.processing || …"` binding.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    consultants: { type: Array, default: () => [] },
});
const emit = defineEmits(['saved', 'close']);

const { saveHandover, preflight: preflightHandover } = useHandover();

const rForm = useForm({ from_consultant_id: '', to_consultant_id: '', mark_new: true, admission_ids: [] });

// bulk reassign: the RECEIVING consultant must be on service ('from' stays unfiltered — patients
// may be moved away from someone who just went off service)
const onServiceConsultants = computed(() => consultantOptions(props.consultants, { onServiceOnly: true }));

// group-header 'Change consultant' opens the modal PRE-FILLED with from=this consultant (J2-11);
// the toolbar button opens it blank
const openModal = (fromId = '') => { rForm.from_consultant_id = fromId; rForm.to_consultant_id = ''; };

// preflight: lists the consultant's patients with per-patient CHECKBOXES (all checked by default —
// uncheck to leave someone behind). A stale SELECTED handover no longer blocks Confirm (soft gate,
// HO-T5) — it just surfaces a warning; Confirm unlocks once preflight has loaded and ≥1 is selected.
const preflight = ref(null);   // null | { loading, rows: [{id,name,mrn,handover_today,body,checkpoints}] }
const preflightBodies = ref({});
const preflightCheckpoints = ref({});
const selectedIds = ref(new Set());
const toggleSelected = (id) => { selectedIds.value.has(id) ? selectedIds.value.delete(id) : selectedIds.value.add(id); selectedIds.value = new Set(selectedIds.value); };
const loadPreflight = async (id) => {
    preflight.value = { loading: true, rows: [] };
    const rows = await preflightHandover(id);
    preflightBodies.value = Object.fromEntries(rows.filter((r) => !r.handover_today).map((r) => [r.id, r.body || '']));
    preflightCheckpoints.value = Object.fromEntries(rows.filter((r) => !r.handover_today).map((r) => [r.id, withCheckpointDefaults(r.checkpoints)]));
    selectedIds.value = new Set(rows.map((r) => r.id));   // all checked by default (legacy move-everything)
    preflight.value = { loading: false, rows };
    // Wave 2, Item 9: jump straight to the first handover that needs today's note (no scrolling).
    nextTick(() => document.querySelector('[data-stale-capture] textarea')?.focus());
};
// Wave 2, Item 9: exclude all stale rows from the move set — the user accepts those patients won't
// be reassigned in this batch (valid when partial reassignment is intended). preflightReady no longer
// depends on this (soft gate) but it's still the easy way to move only the already-current patients.
const uncheckAllStale = () => {
    const staleIds = new Set(staleRows.value.map((r) => r.id));
    selectedIds.value = new Set([...selectedIds.value].filter((id) => !staleIds.has(id)));
};
watch(() => rForm.from_consultant_id, (id) => { preflight.value = null; selectedIds.value = new Set(); if (id) loadPreflight(id); });
const staleRows = computed(() => (preflight.value?.rows || []).filter((r) => !r.handover_today && selectedIds.value.has(r.id)));
const preflightReady = computed(() => !!preflight.value && !preflight.value.loading && selectedIds.value.size > 0);
const allStaleFilled = computed(() => staleRows.value.every((r) => (preflightBodies.value[r.id] || '').trim().length > 0));
const savingAll = ref(false);
const saveAllStale = async () => {
    if (!allStaleFilled.value) return;
    savingAll.value = true;
    try {
        const keep = new Set(selectedIds.value);
        for (const r of staleRows.value) await saveHandover(r.id, preflightBodies.value[r.id].trim(), preflightCheckpoints.value[r.id]);
        await loadPreflight(rForm.from_consultant_id);   // re-check — flips handover_today, unlocks Confirm
        selectedIds.value = keep;                        // reload defaults to all — restore the user's picks
    } finally { savingAll.value = false; }
};

const { ask } = useConfirm();
const modalDirty = computed(() => !!rForm.isDirty);
const { guardedClose } = useUnsavedGuard(modalDirty, ask);
const doClose = () => emit('close');
const close = () => guardedClose(doClose);
const submitReassign = guardSubmit(rForm, () => {
    if (!preflightReady.value) return;   // mirror the disabled-button gate (e.g. a keyboard Enter): still require preflight loaded + ≥1 selected — a stale handover no longer blocks (soft gate)
    rForm.admission_ids = [...selectedIds.value];   // SUBSET move: only the checked patients travel
    rForm.post('/admissions/reassign', { preserveScroll: true, onSuccess: () => { emit('saved'); rForm.reset(); selectedIds.value = new Set(); } });
});

defineExpose({
    rForm, onServiceConsultants, openModal, preflight, preflightBodies, preflightCheckpoints, selectedIds,
    toggleSelected, loadPreflight, uncheckAllStale, staleRows, preflightReady, allStaleFilled,
    savingAll, saveAllStale, submitReassign, modalDirty,
});
</script>

<template>
    <BaseModal :open="open" title="Reassign a consultant's patients" subtitle="Moves the selected active patients from one consultant to another." size="wide" tall field-first :closable="false" :dirty="modalDirty" @close="close">
        <form @submit.prevent="submitReassign" class="space-y-4">
            <div><label class="mb-1 block text-sm font-semibold text-ink-700">From</label><select v-model="rForm.from_consultant_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select…</option><option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
            <div><label class="mb-1 block text-sm font-semibold text-ink-700">To <span class="font-normal text-ink-400">(on-service only)</span></label><select v-model="rForm.to_consultant_id" title="On-service consultants only" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select…</option><option v-for="c in onServiceConsultants" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
            <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="rForm.mark_new" class="rounded text-brand-600" /> Mark as new patients <span class="text-xs text-ink-400">(uncheck to keep their current “New” status)</span></label>

            <!-- preflight: pick WHO moves (all checked by default); every SELECTED patient
                 needs a handover updated TODAY before the move unlocks -->
            <div v-if="preflight" class="rounded-xl bg-app/70 p-3 ring-1 ring-line">
                <p v-if="preflight.loading" class="text-sm text-ink-400">Checking handovers…</p>
                <template v-else-if="preflight.rows.length">
                    <p class="text-xs font-semibold text-ink-600">{{ selectedIds.size }} of {{ preflight.rows.length }} patient(s) selected to move — uncheck to leave someone behind.</p>
                    <ul class="mt-2 max-h-44 space-y-1 overflow-auto">
                        <li v-for="r in preflight.rows" :key="r.id">
                            <label class="flex items-center gap-2 text-sm text-ink-700">
                                <input type="checkbox" :checked="selectedIds.has(r.id)" @change="toggleSelected(r.id)" class="rounded text-brand-600" />
                                <!-- Wave 1 (EHC UI): the same identity tuple as the palette rows — data unchanged -->
                                <IdentityChip :name="r.name" :mrn="String(r.mrn ?? '')" />
                                <span v-if="!r.handover_today" class="ml-auto rounded-full bg-tint-warning px-2 py-0.5 text-[10px] font-semibold text-on-warning">handover stale</span>
                                <span v-else class="ml-auto rounded-full bg-tint-success px-2 py-0.5 text-[10px] font-semibold text-on-success">today ✓</span>
                            </label>
                        </li>
                    </ul>
                    <template v-if="staleRows.length">
                        <!-- HO-T5: soft gate — the move is allowed; this warns rather than blocks -->
                        <p class="mt-3 rounded-lg bg-tint-warning px-2.5 py-1.5 text-sm font-semibold text-on-warning">{{ staleRows.length }} of {{ selectedIds.size }} selected patient(s) will move with an incomplete handover — a reminder will be sent to you and the outgoing consultant until each is completed. You can write the note(s) below now, or proceed.</p>
                        <div v-for="(r, i) in staleRows" :key="'h' + r.id" class="mt-2" :data-stale-capture="i === 0 ? '' : undefined">
                            <p class="text-xs font-semibold text-ink-700"><IdentityChip :name="r.name" :mrn="String(r.mrn ?? '')" /></p>
                            <HandoverCapture density="compact" :label="r.name"
                                :body="preflightBodies[r.id] || ''"
                                :checkpoints="preflightCheckpoints[r.id]"
                                :today="false"
                                @update:body="preflightBodies[r.id] = $event"
                                @update:checkpoints="preflightCheckpoints[r.id] = $event" />
                        </div>
                        <div class="mt-2 flex justify-end gap-2">
                            <!-- Item 9: skip note-writing for stale rows by dropping them from the move set -->
                            <button type="button" @click="uncheckAllStale" class="rounded-lg border border-ink-200 px-3 py-1.5 text-xs font-semibold text-ink-600 hover:bg-ink-50">Uncheck all stale ({{ staleRows.length }})</button>
                            <button type="button" @click="saveAllStale" :disabled="savingAll || !allStaleFilled" class="rounded-lg bg-brand-solid px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">{{ savingAll ? 'Saving…' : 'Save all handovers' }}</button>
                        </div>
                    </template>
                    <p v-else-if="selectedIds.size" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-on-success">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        All {{ selectedIds.size }} selected handover(s) updated today — ready to move.
                    </p>
                    <p v-else class="mt-2 text-xs font-semibold text-on-warning">Select at least one patient to move.</p>
                </template>
                <p v-else class="text-xs text-ink-400">No active patients under this consultant.</p>
            </div>
            <p v-if="rForm.errors.handover" class="text-xs font-semibold text-on-danger">{{ rForm.errors.handover }}</p>
            <p v-if="rForm.errors.admission_ids" class="text-xs font-semibold text-on-danger">{{ rForm.errors.admission_ids }}</p>

            <div class="flex justify-end gap-2"><button type="button" @click="close" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="rForm.processing || !rForm.from_consultant_id || !rForm.to_consultant_id || !preflightReady" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Reassign {{ selectedIds.size || '' }} selected</button></div>
        </form>
    </BaseModal>
</template>
