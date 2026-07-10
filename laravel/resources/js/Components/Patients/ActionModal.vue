<script setup>
import { ref, computed, watch, useId } from 'vue';
import { useForm } from '@inertiajs/vue3';
import BaseModal from '@/Components/BaseModal.vue';
import IdentityChip from '@/Components/IdentityChip.vue';
import ErrorSummary from '@/Components/ErrorSummary.vue';
import AdmissionSummary from '@/Components/Patients/AdmissionSummary.vue';
import { useHandover } from '@/composables/useHandover';
import { useConfirm } from '@/composables/useConfirm';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { consultantOptions, DISCHARGE_DESTINATIONS, OUTCOME_STATUSES, guardSubmit } from '@/lib/ui.js';

/**
 * Per-patient action modal (Wave 3, Item 4) — extracted verbatim from Patients/Index.vue. Hosts the
 * five flow sub-forms (assign / medical-discharge / complete-discharge / ICU-discharge / the 3-mode
 * transfer) + the read-only AdmissionSummary review block, each owning its own useForm. Behavior is
 * preserved exactly: same endpoints, same prefills, same Dead→Mortuary locking, same transferReady
 * gate, and — critically — the HANDOVER GATE-THEN-RETRY clinical safety control (assign-to-a-
 * different-consultant and specialty-transfer require today's handover; on the server's 'handover'
 * validation error the inline editor opens, saves via useHandover, then retries the original action).
 *
 * Open/close + a11y (focus-trap / aria-modal / Esc / return-focus) come from BaseModal. On a
 * successful submit the form emits `saved` (Index reloads/flashes); Cancel/Esc/backdrop emit `close`.
 *
 * Unsaved-changes guard (Wave 3, Item 1/2): `modalDirty` reads the ACTIVE mode's own Inertia form's
 * `.isDirty` (real Inertia forms track this natively — unlike usePatientEdit's hand-rolled form,
 * these five are never pre-populated past construction, so the built-in flag is exactly right here).
 * Passed to BaseModal as `:dirty`, so Esc/backdrop/X ask before discarding; the Cancel button below
 * routes through the SAME useUnsavedGuard instance so all four close paths behave identically.
 *
 * Error summary + focus-jump ids (Item 4): each mode's fields carry a stable id (`am-<uid>-<mode>-
 * <field>`) with aria-describedby → its own message; `modeErrors` maps the ACTIVE form's `.errors`
 * onto those ids for <ErrorSummary>. The handover-gate error is excluded — it already has its own
 * dedicated inline block just below.
 *
 * Double-submit guard (Item 5): every submit* handler is wrapped in guardSubmit() (in addition to
 * the existing `:disabled="…Form.processing"` on each button) so a race can't fire the POST twice.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    mode: { type: String, default: '' },          // 'assign'|'medical'|'complete'|'icu'|'transfer'
    patient: { type: Object, default: null },      // the selected row
    consultants: { type: Array, default: () => [] },
    specialties: { type: Array, default: () => [] },
    externalServices: { type: Array, default: () => [] },
    today: { type: String, required: true },
});
const emit = defineEmits(['saved', 'close']);

const { saveHandover } = useHandover();

const aForm = useForm({ consultant_id: '', mark_new: true });
const mdForm = useForm({ outcome: 'Alive', medical_discharge_date: props.today, discharge_to: '', delay_reason: '', complete: false });
const cdForm = useForm({ discharge_date: props.today, outcome: '', discharge_to: '' });
const icuForm = useForm({ outcome: 'Alive', discharge_date: props.today, discharge_to: '' });
const tForm = useForm({ mode: 'location', target: 'ICU', specialty_id: '', consultant_id: '', service: '' });

// inline gate editor (assign + specialty-transfer): when the server rejects with the handover error,
// write today's handover right here, save, then retry the original submit.
const gateBody = ref('');
const gateBusy = ref(false);

// prime the right sub-form when the modal opens for a (mode, patient). Mirrors the old openModal().
watch(
    () => [props.open, props.mode, props.patient?.id],
    ([open]) => {
        if (!open || !props.patient) return;
        const row = props.patient;
        gateBody.value = '';   // fresh inline-handover editor per patient
        if (props.mode === 'assign') { aForm.consultant_id = row.consultant_id || ''; aForm.mark_new = true; aForm.clearErrors(); }
        if (props.mode === 'medical') mdForm.reset();   // never carry a previous patient's type/destination over
        if (props.mode === 'complete') { cdForm.reset(); cdForm.outcome = row.outcome || ''; cdForm.discharge_to = row.discharge_to || ''; }   // prefill the optional override from phase-1
        if (props.mode === 'icu') icuForm.reset();
        if (props.mode === 'transfer') { tForm.reset(); tForm.target = row.location === 'ICU' ? 'Ward' : 'ICU'; }
    },
    { immediate: true },
);

// controlled destination vocabulary (legacy "Discharged to" list); a death locks to Mortuary.
// Status (outcome) is strictly Alive/Dead — LAMA/Absconded are DESTINATIONS, not outcomes.
const destinations = DISCHARGE_DESTINATIONS;
const statuses = OUTCOME_STATUSES;
watch(() => mdForm.outcome, (o) => { if (o === 'Dead') mdForm.discharge_to = 'Mortuary'; else if (mdForm.discharge_to === 'Mortuary') mdForm.discharge_to = ''; });
watch(() => cdForm.outcome, (o) => { if (o === 'Dead') cdForm.discharge_to = 'Mortuary'; else if (cdForm.discharge_to === 'Mortuary') cdForm.discharge_to = ''; });
watch(() => icuForm.outcome, (o) => { if (o === 'Dead') icuForm.discharge_to = 'Mortuary'; else if (icuForm.discharge_to === 'Mortuary') icuForm.discharge_to = ''; });
// a closed file has no "still-in" delay; medical-only locks the status to Alive (legacy phase-1 —
// the status + destination are asked at the COMPLETE step instead)
watch(() => mdForm.complete, (c) => { if (c) { mdForm.delay_reason = ''; } else { mdForm.outcome = 'Alive'; mdForm.discharge_to = ''; } });

// internal-specialty handover: consultants offered are the ON-SERVICE ones of the chosen specialty
const specConsultants = computed(() => consultantOptions(props.consultants, { specialtyId: tForm.specialty_id }));
// single-assign modal: on-service only too, but keep the CURRENT assignee selectable even when
// they just went off service (so the prefilled selection isn't silently dropped) — J1-15a
const assignConsultants = computed(() => consultantOptions(props.consultants, { keepId: props.patient?.consultant_id }));
watch(() => tForm.specialty_id, () => (tForm.consultant_id = ''));
const transferReady = computed(() =>
    tForm.mode === 'location' ? !!tForm.target
    : tForm.mode === 'specialty' ? !!(tForm.specialty_id && tForm.consultant_id)
    : !!tForm.service);

// Wave 2, Item 5: one title map so "assign" always reads "Assign consultant" — matching the queue's
// assign modal (Admissions/Index) and the standardized verb table (Item 8: "Assign" / "Reassign").
const modalTitle = computed(() => ({
    assign: 'Assign consultant',
    medical: 'Discharge',
    complete: 'Complete discharge',
    icu: 'ICU discharge',
    transfer: 'Transfer',
}[props.mode] ?? ''));

const { ask } = useConfirm();
// the ACTIVE mode's own form is the one the user could actually be editing right now
const activeForm = computed(() => ({ assign: aForm, medical: mdForm, complete: cdForm, icu: icuForm, transfer: tForm }[props.mode]));
const modalDirty = computed(() => !!activeForm.value?.isDirty);
const { guardedClose } = useUnsavedGuard(modalDirty, ask);
const doClose = () => emit('close');
const close = () => guardedClose(doClose);

const uid = useId();
const fid = (field) => `am-${uid}-${props.mode}-${field}`;
// map the active form's errors onto this mode's field ids for <ErrorSummary>; 'handover' is excluded
// — it already renders in its own dedicated gate block just below each affected form.
const modeErrors = computed(() => Object.fromEntries(
    Object.entries(activeForm.value?.errors || {}).filter(([k]) => k !== 'handover').map(([k, v]) => [fid(k), v]),
));

const opts = { preserveScroll: true, onSuccess: () => emit('saved') };
const submitAssign = guardSubmit(aForm, () => aForm.post(`/admissions/${props.patient.id}/assign`, opts));
const submitMedical = guardSubmit(mdForm, () => mdForm.post(`/admissions/${props.patient.id}/medical-discharge`, opts));
const submitComplete = guardSubmit(cdForm, () => cdForm.post(`/admissions/${props.patient.id}/complete-discharge`, opts));
const submitIcu = guardSubmit(icuForm, () => icuForm.post(`/admissions/${props.patient.id}/icu-discharge`, opts));
const submitTransfer = guardSubmit(tForm, () => tForm.post(`/admissions/${props.patient.id}/transfer`, opts));

// gate-then-retry: save today's handover, then re-fire the original submit on success.
const saveGateThen = async (retry) => {
    const body = gateBody.value.trim();
    if (!body || !props.patient) return;
    gateBusy.value = true;
    try { if (await saveHandover(props.patient.id, body)) { gateBody.value = ''; retry(); } } finally { gateBusy.value = false; }
};

defineExpose({
    modalTitle, aForm, mdForm, cdForm, icuForm, tForm, gateBody,
    assignConsultants, specConsultants, transferReady, modalDirty, modeErrors, fid,
    submitAssign, submitMedical, submitComplete, submitIcu, submitTransfer, saveGateThen,
});
</script>

<template>
    <BaseModal :open="open" :title="modalTitle" size="md" tall field-first :dirty="modalDirty" @close="close">
        <!-- Wave 1 (EHC UI): the header carries the SAME identity tuple the user selected on —
             IdentityChip re-verifies name/MRN/age·sex/location at the moment of action. Data is
             unchanged; only the presentation moved from a plain-text subtitle to the chip. -->
        <template #subtitle>
            <IdentityChip v-if="patient" class="mt-1" :name="patient.name" :mrn="patient.mrn"
                :age="patient.age" :sex="patient.gender || ''" :location="patient.location || ''"
                :status="patient.outcome === 'Dead' ? 'deceased' : (patient.discharged ? 'discharged' : '')" />
        </template>
        <template v-if="patient">
            <ErrorSummary :errors="modeErrors" />
            <form v-if="mode === 'assign'" @submit.prevent="submitAssign" class="space-y-4">
                <div><label :for="fid('consultant_id')" class="sr-only">Consultant</label><select :id="fid('consultant_id')" v-model="aForm.consultant_id" title="On-service consultants only" :aria-describedby="aForm.errors.consultant_id ? fid('consultant_id') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select consultant…</option><option v-for="c in assignConsultants" :key="c.id" :value="c.id">{{ c.name }}{{ !c.on_service ? ' (off service)' : '' }}</option></select><p v-if="aForm.errors.consultant_id" :id="fid('consultant_id') + '-err'" class="mt-1 text-xs text-on-danger">{{ aForm.errors.consultant_id }}</p></div>
                <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="aForm.mark_new" class="rounded text-brand-600" /> Mark as new patient <span class="text-xs text-ink-400">(uncheck for a quiet administrative move — no “New” badge)</span></label>
                <!-- handover gate: write today's handover here, save, retry the assign -->
                <div v-if="aForm.errors.handover" class="rounded-xl bg-tint-warning/60 p-3 ring-1 ring-warning-500/30">
                    <p class="text-xs font-semibold text-on-warning">{{ aForm.errors.handover }}</p>
                    <textarea v-model="gateBody" rows="3" maxlength="5000" placeholder="Write today's handover for this patient…" aria-label="Handover text" class="mt-2 w-full rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>
                    <div class="mt-2 flex justify-end"><button type="button" @click="saveGateThen(submitAssign)" :disabled="gateBusy || !gateBody.trim()" class="rounded-lg bg-brand-solid px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Save handover & assign</button></div>
                </div>
                <div class="flex justify-end gap-2"><button type="button" @click="close" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="aForm.processing || !aForm.consultant_id" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Assign</button></div>
            </form>
            <form v-else-if="mode === 'medical'" @submit.prevent="submitMedical" class="space-y-4">
                <!-- record-review step (J1-15c): legacy discharge embedded the admission record
                     above the discharge fields — read-only summary here; edits go via Modify -->
                <AdmissionSummary :patient="patient" />
                <div><label class="mb-1 block text-sm font-semibold text-ink-700">Discharge type</label>
                    <div class="flex gap-2">
                        <label v-for="t in [[false, 'Medical only (still in bed)'], [true, 'Complete (leaving now)']]" :key="String(t[0])" class="flex-1 cursor-pointer rounded-xl border-2 px-3 py-2.5 text-center text-sm font-semibold transition" :class="mdForm.complete === t[0] ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'"><input type="radio" v-model="mdForm.complete" :value="t[0]" class="hidden" /> {{ t[1] }}</label>
                    </div>
                </div>
                <div><label :for="fid('medical_discharge_date')" class="mb-1 block text-sm font-semibold text-ink-700">Medical discharge date</label><input :id="fid('medical_discharge_date')" v-model="mdForm.medical_discharge_date" type="date" :max="today" :aria-describedby="mdForm.errors.medical_discharge_date ? fid('medical_discharge_date') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" /><p v-if="mdForm.errors.medical_discharge_date" :id="fid('medical_discharge_date') + '-err'" class="mt-1 text-xs text-on-danger">{{ mdForm.errors.medical_discharge_date }}</p></div>
                <!-- one-step close asks Status + Destination; medical-only locks Alive (asked at completion) -->
                <div v-if="mdForm.complete" class="grid grid-cols-2 gap-3">
                    <div><label :for="fid('outcome')" class="mb-1 block text-sm font-semibold text-ink-700">Status</label>
                        <select :id="fid('outcome')" v-model="mdForm.outcome" :aria-describedby="mdForm.errors.outcome ? fid('outcome') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option v-for="s in statuses" :key="s">{{ s }}</option></select>
                        <p v-if="mdForm.errors.outcome" :id="fid('outcome') + '-err'" class="mt-1 text-xs text-on-danger">{{ mdForm.errors.outcome }}</p></div>
                    <!-- destination is REQUIRED on a close unless Dead (auto-Mortuary) — N1-4 -->
                    <div><label :for="fid('discharge_to')" class="mb-1 block text-sm font-semibold text-ink-700">Discharge to <span v-if="mdForm.outcome !== 'Dead'" class="text-on-danger">*</span></label>
                        <select :id="fid('discharge_to')" v-model="mdForm.discharge_to" :disabled="mdForm.outcome === 'Dead'" :required="mdForm.outcome !== 'Dead'" :aria-describedby="mdForm.errors.discharge_to ? fid('discharge_to') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 disabled:bg-ink-50"><option value="">—</option><option v-for="d in destinations" :key="d">{{ d }}</option></select>
                        <p v-if="mdForm.errors.discharge_to" :id="fid('discharge_to') + '-err'" class="mt-1 text-xs text-on-danger">{{ mdForm.errors.discharge_to }}</p></div>
                </div>
                <!-- still-in delay reason is REQUIRED on a medical-only discharge — N1-4 -->
                <div v-else><label :for="fid('delay_reason')" class="mb-1 block text-sm font-semibold text-ink-700">Delay reason <span class="text-on-danger">*</span></label>
                    <select :id="fid('delay_reason')" v-model="mdForm.delay_reason" required :aria-describedby="mdForm.errors.delay_reason ? fid('delay_reason') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">—</option><option value="Physical">Physical bed availability</option><option value="System">System</option></select>
                    <p v-if="mdForm.errors.delay_reason" :id="fid('delay_reason') + '-err'" class="mt-1 text-xs text-on-danger">{{ mdForm.errors.delay_reason }}</p></div>
                <p class="text-xs text-ink-400">{{ mdForm.complete ? 'Closes the file and frees the bed in one step.' : 'Marks the patient clinically discharged but still in a bed. Status and destination are recorded at Complete discharge, when they physically leave.' }}</p>
                <!-- W0-T3d. `text-white` on bg-warning-500 was 2.48:1 — a WCAG AA failure for this
                     14px semibold label. The amber FILL stays (a fill is not text; the tone is the
                     signal); the label goes dark instead. NOT `text-ink-900`: the ink scale inverts
                     under `.dark`, so it would resolve to #f4f8f8 and sit at 2.31:1 on the
                     theme-invariant amber — fixing light while breaking dark. `text-navy-950`
                     (#00252a) is a literal in @theme that `.dark` never remaps: 6.53:1 in both.

                     W0-T3e/f. The `hover:opacity-*` fade is GONE from this button. (Wildcard on
                     purpose: Tailwind's extractor reads COMMENTS, so spelling the real utility here
                     would resurrect its rule in the shipped CSS.) CSS `opacity` composites the WHOLE
                     element — the fill AND the label — over whatever is behind it, so it
                     quietly degrades the label rather than just the fill. White on success-600 fell
                     from 5.02:1 to 4.17:1 over the light bg-card panel (4.68:1 dark). 1.4.3 exempts
                     `disabled` controls (so `disabled:opacity-50` stays) but has NO hover exemption.
                     Both branches now hover on a real, darker FILL, which touches only the fill.

                     W0-T3h. The two branches are NOT symmetric, and the amber one needed a second
                     pass. Its label is near-black, so darkening the fill LOWERS contrast — the first
                     hover step (#c87d06) made hover the button's WEAKEST state, 4.92:1 against a
                     6.53:1 rest. Only the white-labelled branch gains from a darker hover:
                       success-700 #166534 under text-white     7.13:1  (rest 5.02:1 — RISES)
                       warning-600 #d58506 under text-navy-950  5.54:1  (rest 6.53:1 — still falls,
                                                                         but stays above 4.5:1)
                     #d58506 is a LIGHTER 600 than the original. It keeps the amber unmistakably
                     darker than the 500 rest fill (luminance 0.3093 vs 0.3740; dE76 6.55, ~3x the
                     JND), so the state change is still obvious. See app.css for why warning-600 may
                     never be used as TEXT (2.92:1 on the light card). -->
                <div class="flex justify-end gap-2"><button type="button" @click="close" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="mdForm.processing" class="rounded-xl px-5 py-2 text-sm font-semibold disabled:opacity-50" :class="mdForm.complete ? 'bg-success-600 text-white hover:bg-success-700' : 'bg-warning-500 text-navy-950 hover:bg-warning-600'">{{ mdForm.complete ? 'Discharge & close file' : 'Medical discharge' }}</button></div>
            </form>
            <form v-else-if="mode === 'complete'" @submit.prevent="submitComplete" class="space-y-4">
                <p class="text-sm text-ink-600">Close the file and free the bed.</p>
                <div><label :for="fid('discharge_date')" class="mb-1 block text-sm font-semibold text-ink-700">Discharge date</label><input :id="fid('discharge_date')" v-model="cdForm.discharge_date" type="date" :max="today" :aria-describedby="cdForm.errors.discharge_date ? fid('discharge_date') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" /><p v-if="cdForm.errors.discharge_date" :id="fid('discharge_date') + '-err'" class="mt-1 text-xs text-on-danger">{{ cdForm.errors.discharge_date }}</p></div>
                <!-- optional override of the phase-1 outcome/destination (prefilled with the current values) -->
                <div class="grid grid-cols-2 gap-3">
                    <div><label :for="fid('outcome')" class="mb-1 block text-sm font-semibold text-ink-700">Status</label>
                        <select :id="fid('outcome')" v-model="cdForm.outcome" :aria-describedby="cdForm.errors.outcome ? fid('outcome') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">—</option><option v-for="s in statuses" :key="s">{{ s }}</option></select>
                        <p v-if="cdForm.errors.outcome" :id="fid('outcome') + '-err'" class="mt-1 text-xs text-on-danger">{{ cdForm.errors.outcome }}</p></div>
                    <!-- destination is REQUIRED on the close unless Dead (auto-Mortuary) — N1-4 -->
                    <div><label :for="fid('discharge_to')" class="mb-1 block text-sm font-semibold text-ink-700">Destination <span v-if="cdForm.outcome !== 'Dead'" class="text-on-danger">*</span></label>
                        <select :id="fid('discharge_to')" v-model="cdForm.discharge_to" :disabled="cdForm.outcome === 'Dead'" :required="cdForm.outcome !== 'Dead'" :aria-describedby="cdForm.errors.discharge_to ? fid('discharge_to') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 disabled:bg-ink-50"><option value="">—</option><option v-for="d in destinations" :key="d">{{ d }}</option></select>
                        <p v-if="cdForm.errors.discharge_to" :id="fid('discharge_to') + '-err'" class="mt-1 text-xs text-on-danger">{{ cdForm.errors.discharge_to }}</p></div>
                </div>
                <p class="text-xs text-ink-400">Status and destination carry over from the medical discharge — change them here only if the situation changed before the bed exit.</p>
                <div class="flex justify-end gap-2"><button type="button" @click="close" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="cdForm.processing" class="rounded-xl bg-success-600 px-5 py-2 text-sm font-semibold text-white hover:bg-success-700 disabled:opacity-50">Complete discharge</button></div>
            </form>
            <form v-else-if="mode === 'icu'" @submit.prevent="submitIcu" class="space-y-4">
                <!-- record-review step (K1-11): same read-only summary as the medical-discharge
                     modal — review the admission record before closing the ICU file -->
                <AdmissionSummary :patient="patient" />
                <div><label :for="fid('outcome')" class="mb-1 block text-sm font-semibold text-ink-700">Status</label><select :id="fid('outcome')" v-model="icuForm.outcome" :aria-describedby="icuForm.errors.outcome ? fid('outcome') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option v-for="s in statuses" :key="s">{{ s }}</option></select><p v-if="icuForm.errors.outcome" :id="fid('outcome') + '-err'" class="mt-1 text-xs text-on-danger">{{ icuForm.errors.outcome }}</p></div>
                <!-- destination is REQUIRED on the ICU close unless Dead (auto-Mortuary) — N1-4 -->
                <div><label :for="fid('discharge_to')" class="mb-1 block text-sm font-semibold text-ink-700">Discharge to <span v-if="icuForm.outcome !== 'Dead'" class="text-on-danger">*</span></label>
                    <select :id="fid('discharge_to')" v-model="icuForm.discharge_to" :disabled="icuForm.outcome === 'Dead'" :required="icuForm.outcome !== 'Dead'" :aria-describedby="icuForm.errors.discharge_to ? fid('discharge_to') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 disabled:bg-ink-50"><option value="">—</option><option v-for="d in destinations" :key="d">{{ d }}</option></select>
                    <p v-if="icuForm.errors.discharge_to" :id="fid('discharge_to') + '-err'" class="mt-1 text-xs text-on-danger">{{ icuForm.errors.discharge_to }}</p></div>
                <div><label :for="fid('discharge_date')" class="mb-1 block text-sm font-semibold text-ink-700">Discharge date</label><input :id="fid('discharge_date')" v-model="icuForm.discharge_date" type="date" :max="today" :aria-describedby="icuForm.errors.discharge_date ? fid('discharge_date') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" /><p v-if="icuForm.errors.discharge_date" :id="fid('discharge_date') + '-err'" class="mt-1 text-xs text-on-danger">{{ icuForm.errors.discharge_date }}</p></div>
                <div class="flex justify-end gap-2"><button type="button" @click="close" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="icuForm.processing" class="rounded-xl bg-success-600 px-5 py-2 text-sm font-semibold text-white hover:bg-success-700 disabled:opacity-50">ICU discharge</button></div>
            </form>
            <form v-else @submit.prevent="submitTransfer" class="space-y-4">
                <div class="flex gap-1 rounded-xl bg-app p-1 text-sm font-semibold">
                    <button v-for="m in [['location','Ward / ICU'],['specialty','Internal specialty'],['external','External service']]" :key="m[0]" type="button" @click="tForm.mode = m[0]"
                        class="flex-1 rounded-lg px-2 py-1.5 transition" :class="tForm.mode === m[0] ? 'bg-card text-brand-700 shadow-sm ring-1 ring-line' : 'text-ink-500 hover:text-ink-700'">{{ m[1] }}</button>
                </div>
                <template v-if="tForm.mode === 'location'">
                    <p class="text-sm text-ink-600">Currently in <span class="font-semibold">{{ patient.location || '—' }}</span>. Transfer to:</p>
                    <div class="flex gap-2"><label v-for="loc in ['Ward','ICU']" :key="loc" class="flex-1 cursor-pointer rounded-xl border-2 px-4 py-3 text-center text-sm font-semibold transition" :class="tForm.target === loc ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'"><input type="radio" v-model="tForm.target" :value="loc" class="hidden" /> {{ loc }}</label></div>
                    <p class="text-xs text-ink-400">Keeps the same consultant; opens a new episode in the receiving location.</p>
                </template>
                <template v-else-if="tForm.mode === 'specialty'">
                    <div><label :for="fid('specialty_id')" class="mb-1 block text-sm font-semibold text-ink-700">Receiving specialty</label>
                        <select :id="fid('specialty_id')" v-model="tForm.specialty_id" :aria-describedby="tForm.errors.specialty_id ? fid('specialty_id') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select specialty…</option><option v-for="s in specialties" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                        <p v-if="tForm.errors.specialty_id" :id="fid('specialty_id') + '-err'" class="mt-1 text-xs text-on-danger">{{ tForm.errors.specialty_id }}</p></div>
                    <div><label :for="fid('consultant_id')" class="mb-1 block text-sm font-semibold text-ink-700">Receiving consultant <span class="font-normal text-ink-400">(on-service only)</span></label>
                        <select :id="fid('consultant_id')" v-model="tForm.consultant_id" :disabled="!tForm.specialty_id" title="On-service consultants only" :aria-describedby="tForm.errors.consultant_id ? fid('consultant_id') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 disabled:bg-ink-50"><option value="">Select consultant…</option><option v-for="c in specConsultants" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                        <p v-if="tForm.specialty_id && !specConsultants.length" class="mt-1 text-xs text-on-warning">No on-service consultants under this specialty.</p>
                        <p v-if="tForm.errors.consultant_id" :id="fid('consultant_id') + '-err'" class="mt-1 text-xs text-on-danger">{{ tForm.errors.consultant_id }}</p></div>
                    <p class="text-xs text-ink-400">Closes this episode as a specialty handover and opens a new one under the chosen consultant.</p>
                    <!-- handover gate: write today's handover here, save, retry the transfer -->
                    <div v-if="tForm.errors.handover" class="rounded-xl bg-tint-warning/60 p-3 ring-1 ring-warning-500/30">
                        <p class="text-xs font-semibold text-on-warning">{{ tForm.errors.handover }}</p>
                        <textarea v-model="gateBody" rows="3" maxlength="5000" placeholder="Write today's handover for this patient…" aria-label="Handover text" class="mt-2 w-full rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>
                        <div class="mt-2 flex justify-end"><button type="button" @click="saveGateThen(submitTransfer)" :disabled="gateBusy || !gateBody.trim()" class="rounded-lg bg-brand-solid px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Save handover & transfer</button></div>
                    </div>
                </template>
                <template v-else>
                    <div><label :for="fid('service')" class="mb-1 block text-sm font-semibold text-ink-700">External / allied service</label>
                        <select :id="fid('service')" v-model="tForm.service" :aria-describedby="tForm.errors.service ? fid('service') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select service…</option><option v-for="s in externalServices" :key="s" :value="s">{{ s }}</option></select>
                        <p v-if="tForm.errors.service" :id="fid('service') + '-err'" class="mt-1 text-xs text-on-danger">{{ tForm.errors.service }}</p></div>
                    <p class="text-xs text-ink-400">Closes this episode — the patient leaves the department (no new episode).</p>
                </template>
                <div class="flex justify-end gap-2"><button type="button" @click="close" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="tForm.processing || !transferReady" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Transfer</button></div>
            </form>
        </template>
    </BaseModal>
</template>
