<script setup>
// One active-patient card — extracted verbatim from Patients/Index.vue so the Grouped board and the
// new Split (master–detail) board render the SAME card with zero duplication. The card owns what is
// purely local to it (inline bed edit, the "N dx" names expander, the touch kebab, and the three
// self-contained router actions: long-term / undo-medical / delete) plus its own permission checks
// (read from usePage, same shape as Index). Actions that open Index-level MODALS are emitted up
// (`open-modal` for assign/transfer/icu/complete/medical, `modify`, `handover`) — Index still owns
// those modals + the reload-on-saved flow, so behaviour is identical to the pre-extraction inline card.
import { ref, computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PatientFlags from '@/Components/PatientFlags.vue';
import CheckpointChips from '@/Components/Patients/CheckpointChips.vue';
import { locTone, vFocus, formatDate } from '@/lib/ui.js';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
    patient: { type: Object, required: true },
    compact: { type: Boolean, default: false },
    readmitWindow: { type: Number, default: 0 },
});
defineEmits(['open-modal', 'modify', 'handover']);

const { ask } = useConfirm();
const page = usePage();
const me = computed(() => page.props.auth.user);
const isObserver = computed(() => me.value.role === 5);                                        // observers stay read-only
const canAssign = computed(() => me.value.role !== 5 && (me.value.is_admin || me.value.can.assign));
const canModify = computed(() => me.value.is_admin || me.value.can.modify);
const canManage = computed(() => me.value.is_admin || me.value.can.manage || props.patient.consultant_id === me.value.id);

// inline bed edit — ANY clinical role (matches the /bed endpoint); observers/discharged read-only.
// Saves on blur/Enter, Esc cancels. (One card = one bed edit, so a plain boolean flag replaces the
// keyed {id,value} the shared-board version needed.)
const bedEditing = ref(false);
const bedDraft = ref('');
const startBed = () => { if (!isObserver.value && !props.patient.discharged) { bedDraft.value = props.patient.bed || ''; bedEditing.value = true; } };
const cancelBed = () => { bedEditing.value = false; };
const saveBed = () => {
    if (!bedEditing.value) return;
    bedEditing.value = false;
    const value = bedDraft.value.trim();
    if (value === (props.patient.bed || '')) return;   // unchanged — no request
    router.post(`/admissions/${props.patient.id}/bed`, { bed: value || null }, { preserveScroll: true });
};

// diagnosis-names expander + touch kebab — both per-card local toggles now.
const dxOpen = ref(false);
const toggleDx = () => { dxOpen.value = !dxOpen.value; };
const kebabOpen = ref(false);
const toggleKebab = () => { kebabOpen.value = !kebabOpen.value; };
const closeKebab = () => { kebabOpen.value = false; };

// self-contained row actions (no Index modal) — long-term/undo-medical are low-stakes reversals
// (no confirm; server flashes), delete is admin-only + confirmed + server-rechecked.
const longterm = () => router.post(`/admissions/${props.patient.id}/longterm`, {}, { preserveScroll: true });
const undoMedical = () => router.post(`/admissions/${props.patient.id}/undo-medical-discharge`, {}, { preserveScroll: true });
const destroyAdmission = async () => {
    if (await ask('Delete admission',
        `Permanently remove the episode for ${props.patient.name} (MRN ${props.patient.mrn}) and its diagnoses. This cannot be undone.`, 'danger'))
        router.delete(`/admissions/${props.patient.id}`, { preserveScroll: true });
};

// presentational helpers (were inline in Index) — pure, patient-scoped.
const fmtAt = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
const handoverTone = computed(() => !props.patient.handover ? 'text-ink-300 hover:text-ink-500' : props.patient.handover.today ? 'text-brand-600 hover:text-brand-700' : 'text-on-warning hover:text-on-warning');
const handoverTitle = computed(() => props.patient.handover ? `Handover — last updated ${props.patient.handover.updated_by || '—'} ${fmtAt(props.patient.handover.updated_at)}` : 'No handover yet');
const losTone = (b) => b === 'short' ? 'bg-tint-success text-on-success' : b === 'long' ? 'bg-tint-danger text-on-danger' : 'bg-tint-warning text-on-warning';
</script>

<template>
    <div class="rounded-xl ring-1 ring-line">
        <div class="flex items-center justify-between rounded-t-xl bg-app/60" :class="compact ? 'px-2.5 py-1' : 'px-3 py-2'">
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="locTone(patient.location)">
                {{ patient.location || '—' }} ·
                <input v-if="bedEditing" v-model="bedDraft" v-focus maxlength="64"
                    aria-label="Bed" class="w-14 rounded border border-ink-200 bg-card px-1 py-0 text-[11px] font-semibold text-ink-700 outline-none focus:border-brand-500"
                    @blur="saveBed" @keydown.enter.prevent="$event.target.blur()" @keydown.esc.prevent="cancelBed" />
                <button v-else-if="!isObserver && !patient.discharged" type="button" @click="startBed" title="Edit bed" aria-label="Edit bed"
                    class="rounded underline decoration-dotted underline-offset-2 hover:opacity-75">{{ patient.bed || '—' }}</button>
                <template v-else>{{ patient.bed || '—' }}</template>
            </span>
            <span class="flex items-center gap-1">
                <button type="button" @click="$emit('handover', patient)" :title="handoverTitle" :aria-label="handoverTitle"
                    class="grid h-6 w-6 place-items-center rounded-lg transition hover:bg-white/70" :class="handoverTone">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>
                </button>
                <span v-if="patient.los !== null" class="nums rounded-full px-2 py-0.5 text-[11px] font-bold" :class="losTone(patient.los_band)">{{ patient.los }}d</span>
            </span>
        </div>
        <div :class="compact ? 'px-2.5 py-1.5' : 'px-3 py-2'">
            <div class="font-semibold text-ink-800">{{ patient.name }}</div>
            <div class="nums text-xs text-ink-400">MRN {{ patient.mrn }} · {{ patient.age ?? '—' }}y · {{ (patient.gender||'—').slice(0,1) }}</div>
            <div class="nums text-xs text-ink-400">Admitted {{ formatDate(patient.admit_date) || '—' }}</div>
            <!-- refined badge set (owner-approved, supersedes the loud legacy hex; J2-10):
                 token-based so the .dark remap covers both themes. Semantics kept:
                 New=info(blue), Readmit=warning(amber), Long-term=accent(gold subtle),
                 TB=danger(red infection alert — NOT success-green), Disch-still-in=neutral "in progress". -->
            <div class="mt-1.5 flex flex-wrap gap-1">
                <PatientFlags :patient="patient" :readmit-window="readmitWindow" variant="badge" />
                <Link v-if="patient.sign_pending" href="/handovers" title="Handover awaiting your signature" class="rounded-full bg-brand-100 px-1.5 py-0.5 text-[10px] font-semibold text-brand-700 hover:bg-brand-200">Sign pending</Link>
                <button v-if="patient.dx_count" type="button" @click="toggleDx" :aria-expanded="dxOpen"
                    :aria-label="`${patient.dx_count} diagnoses — ${dxOpen ? 'hide' : 'show'} names`"
                    class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold transition"
                    :class="dxOpen ? 'bg-brand-100 text-brand-700' : 'bg-ink-50 text-ink-500 hover:bg-ink-100'">{{ patient.dx_count }} dx</button>
                <!-- handover checkpoint chips (spec §D4) — only renders when a flag/code-status is set -->
                <CheckpointChips :checkpoints="patient.handover?.checkpoints" />
            </div>
            <ul v-if="dxOpen && patient.diagnoses?.length" class="mt-1.5 space-y-0.5 rounded-lg bg-app/70 px-2 py-1.5 text-[11px] leading-snug text-ink-600">
                <li v-for="d in patient.diagnoses" :key="d.code"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> {{ d.name }}</li>
            </ul>
        </div>
        <!-- Touch (coarse): primary buttons lift to 40px and the three rare actions
             (long-term / undo-medical / delete) fold away into a kebab so the row stays
             uncramped. Desktop layout is unchanged (h-7 + every action inline). -->
        <div v-if="!isObserver && !patient.discharged" class="flex items-center gap-1 coarse:gap-0.5 border-t border-ink-50 px-2" :class="compact ? 'py-1' : 'py-1.5'">
            <button v-if="canAssign" @click="$emit('open-modal', 'assign', patient)" title="Reassign consultant" aria-label="Reassign consultant" class="grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-ink-400 hover:bg-info-100 hover:text-info-500"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v6m3-3h-6m-3.75-1.875a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" /></svg></button>
            <button v-if="canModify" @click="$emit('modify', patient)" title="Modify details" aria-label="Modify details" class="grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-ink-400 hover:bg-brand-100 hover:text-brand-700"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg></button>
            <!-- long-term: rare action — hidden on touch (lives in the kebab below) -->
            <button @click="longterm" :title="patient.is_longterm ? 'Remove long-term' : 'Mark long-term'" :aria-label="patient.is_longterm ? 'Remove long-term' : 'Mark long-term'" class="grid h-7 w-7 coarse:hidden place-items-center rounded-lg hover:bg-tint-accent" :class="patient.is_longterm ? 'text-on-accent' : 'text-ink-400 hover:text-on-accent'"><svg class="h-4 w-4" :fill="patient.is_longterm ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z" /></svg></button>
            <button v-if="canManage" @click="$emit('open-modal', 'transfer', patient)" title="Transfer" aria-label="Transfer" class="grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-ink-400 hover:bg-brand-100 hover:text-brand-700"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg></button>
            <template v-if="canManage">
                <button v-if="patient.location === 'ICU'" @click="$emit('open-modal', 'icu', patient)" title="ICU discharge" aria-label="ICU discharge" class="ms-auto grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-ink-400 hover:bg-success-100 hover:text-success-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></button>
                <template v-else-if="patient.medically_discharged">
                    <button @click="$emit('open-modal', 'complete', patient)" title="Complete discharge" aria-label="Complete discharge" class="ms-auto grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-success-600 hover:bg-success-100"><svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd" /></svg></button>
                    <!-- undo medical: rare action — hidden on touch (kebab) -->
                    <button @click="undoMedical" title="Undo medical discharge" aria-label="Undo medical discharge" class="grid h-7 w-7 coarse:hidden place-items-center rounded-lg text-ink-400 hover:bg-danger-100 hover:text-danger-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" /></svg></button>
                </template>
                <button v-else @click="$emit('open-modal', 'medical', patient)" title="Discharge" aria-label="Discharge" class="ms-auto grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-ink-400 hover:bg-success-100 hover:text-success-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75" /></svg></button>
            </template>
            <!-- delete: rare action — hidden on touch (kebab) -->
            <button v-if="me.is_admin" @click="destroyAdmission" title="Delete admission" aria-label="Delete admission" class="grid h-7 w-7 coarse:hidden place-items-center rounded-lg text-ink-400 hover:bg-danger-100 hover:text-danger-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg></button>
            <!-- kebab (touch only): groups the three rare actions above -->
            <div class="relative hidden coarse:block">
                <button type="button" @click="toggleKebab" :aria-expanded="kebabOpen" aria-haspopup="menu" title="More actions" aria-label="More actions" class="grid h-10 w-10 place-items-center rounded-lg text-ink-400 hover:bg-ink-50 hover:text-ink-700"><svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6.75a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 6.75a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 6.75a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" /></svg></button>
                <!-- transparent backdrop closes the menu on outside tap -->
                <div v-if="kebabOpen" class="fixed inset-0 z-0" @click="closeKebab"></div>
                <div v-if="kebabOpen" role="menu" class="absolute end-0 bottom-12 z-10 w-44 overflow-hidden rounded-xl bg-card py-1 shadow-lg ring-1 ring-line" @keydown.esc="closeKebab">
                    <button type="button" role="menuitem" @click="longterm(); closeKebab()" class="flex w-full items-center gap-2 px-3 py-2.5 text-start text-sm font-medium text-ink-700 hover:bg-ink-50">{{ patient.is_longterm ? 'Remove long-term' : 'Mark long-term' }}</button>
                    <button v-if="canManage && patient.medically_discharged && patient.location !== 'ICU'" type="button" role="menuitem" @click="undoMedical(); closeKebab()" class="flex w-full items-center gap-2 px-3 py-2.5 text-start text-sm font-medium text-on-danger hover:bg-tint-danger">Undo medical discharge</button>
                    <button v-if="me.is_admin" type="button" role="menuitem" @click="destroyAdmission(); closeKebab()" class="flex w-full items-center gap-2 px-3 py-2.5 text-start text-sm font-medium text-on-danger hover:bg-tint-danger">Delete admission</button>
                </div>
            </div>
        </div>
    </div>
</template>
