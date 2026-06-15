<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useModalA11y } from '@/composables/useModalA11y';
import { localToday, vFocus } from '@/lib/ui.js';

const { ask } = useConfirm();

// one focus-trap instance per modal slot (new / edit)
const a11yAdd = useModalA11y();
const a11yEdit = useModalA11y();

const props = defineProps({ consultations: Object, filters: Object, stats: Object, reasons: Array, consultants: Array, specialties: Array });
const page = usePage();
const me = computed(() => page.props.auth.user);
const canSignoff = (row) => me.value.is_admin || me.value.can.manage || row.consultant_id === me.value.id;
const canAdd = computed(() => me.value.role !== 5);
const isConsultant = computed(() => me.value.is_admin || me.value.role === 3);

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'active');
const scope = ref(props.filters.scope || '');
let timer = null;

const apply = () => router.get('/consultations', { search: search.value || undefined, status: status.value, scope: scope.value || undefined },
    { preserveState: true, replace: true, preserveScroll: true });
watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 300); });
const setStatus = (s) => { status.value = s; apply(); };
const toggleMine = () => { scope.value = scope.value === 'mine' ? '' : 'mine'; apply(); };

// new consultation
const today = localToday();
const showAdd = ref(false);
const cForm = useForm({
    mrn: '', patient_name: '', age: '', bed: '', current_location: 'Ward', consultation_date: today,
    consultation_from: '', to_service: '', consultant_id: '', indication: [], other_indication: '',
});
const openAdd = () => { showAdd.value = true; a11yAdd.onOpen(undefined, { fieldFirst: true }); };
const closeAdd = () => { showAdd.value = false; a11yAdd.onClose(); };
const submitAdd = () => cForm.post('/consultations', { preserveScroll: true, onSuccess: () => { closeAdd(); cForm.reset(); } });

// edit + delete
const canEdit = (c) => me.value.is_admin || me.value.can.manage || c.consultant_id === me.value.id;
const editing = ref(null);
const eForm = useForm({ mrn: '', patient_name: '', age: '', bed: '', current_location: 'Ward', consultation_date: today, consultation_from: '', to_service: '', consultant_id: '', indication: [], other_indication: '' });
const closeEdit = () => { editing.value = null; a11yEdit.onClose(); };
const openEdit = (c) => {
    editing.value = c;
    a11yEdit.onOpen();
    eForm.mrn = c.mrn || ''; eForm.patient_name = c.name || ''; eForm.age = c.age ?? ''; eForm.bed = c.bed || '';
    eForm.current_location = c.location || 'Ward'; eForm.consultation_date = c.date || today; eForm.consultation_from = c.from || '';
    eForm.to_service = c.to || ''; eForm.consultant_id = c.consultant_id || ''; eForm.indication = [...(c.indication_ids || [])]; eForm.other_indication = c.other || '';
};
const submitEdit = () => eForm.put(`/consultations/${editing.value.id}`, { preserveScroll: true, onSuccess: closeEdit });

// when "to service" names an INTERNAL specialty, narrow the receiving-consultant list to its
// ON-SERVICE consultants (a previously chosen consultant stays selectable); if none match,
// fall back to the full list with a note. External / free-text services have NO receiving
// consultant (legacy stored none) — the select is disabled and cleared.
const isInternalService = (toService) => {
    const wanted = String(toService || '').trim().toLowerCase();
    return !!(wanted && props.specialties.find((s) => !s.is_external && s.name.trim().toLowerCase() === wanted));
};
const consultantPick = (toService, currentId) => {
    const wanted = String(toService || '').trim().toLowerCase();
    const spec = wanted && props.specialties.find((s) => !s.is_external && s.name.trim().toLowerCase() === wanted);
    if (!spec) return { list: props.consultants, fallback: false };
    const list = props.consultants.filter((c) => c.specialty_id === spec.id && c.on_service);
    if (!list.length) return { list: props.consultants, fallback: true };
    const current = currentId && props.consultants.find((c) => c.id === currentId);
    return { list: current && !list.some((c) => c.id === current.id) ? [...list, current] : list, fallback: false };
};
const cPick = computed(() => consultantPick(cForm.to_service, cForm.consultant_id));
const ePick = computed(() => consultantPick(eForm.to_service, eForm.consultant_id));
const cInternal = computed(() => isInternalService(cForm.to_service));
const eInternal = computed(() => isInternalService(eForm.to_service));
watch(cInternal, (v) => { if (!v) cForm.consultant_id = ''; });
watch(eInternal, (v) => { if (!v) eForm.consultant_id = ''; });
const deleteConsult = async (c) => { if (await ask('Delete consultation', `Permanently delete the consultation for ${c.name} (MRN ${c.mrn}). This cannot be undone.`, 'danger')) router.delete(`/consultations/${c.id}`, { preserveScroll: true }); };

// Esc closes whichever modal is open (via helpers so focus returns to the opener)
const onEsc = (e) => {
    if (e.key !== 'Escape') return;
    if (showAdd.value) closeAdd();
    if (editing.value) closeEdit();
};
onMounted(() => window.addEventListener('keydown', onEsc));
onUnmounted(() => window.removeEventListener('keydown', onEsc));

// sign off — Wave 2, Item 4: no confirm. A single sign-off is reversible (the reverse-signoff
// button, already instant) and low-stakes; the server flash is the feedback. deleteConsult keeps
// its danger confirm (irreversible). Sign-all stays confirmed on the Handovers page (bulk).
const signoff = (row) => router.post(`/consultations/${row.id}/signoff`, {}, { preserveScroll: true });
const field = 'w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
</script>

<template>
    <AppLayout title="Consultations">
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="flex gap-2">
                <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Active <span class="nums ml-1 text-accent-600">{{ stats.active }}</span></span>
                <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Total <span class="nums ml-1 text-ink-600">{{ stats.total }}</span></span>
                <!-- personal counter for consultant viewers (K1-13): own active out of total active -->
                <span v-if="me.role === 3" class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Mine <span class="nums ml-1 text-brand-700">{{ stats.mine_active }} of {{ stats.active }} active</span></span>
            </div>
            <div class="relative ml-auto">
                <svg class="pointer-events-none absolute left-3 top-2.5 h-5 w-5 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" /></svg>
                <input v-model="search" v-focus aria-label="Search consultations by name or MRN" placeholder="Search name or MRN…" class="w-64 rounded-xl border border-ink-200 bg-card py-2 pl-10 pr-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
            </div>
            <div class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line">
                <button v-for="s in [['active','Active'],['signed','Signed off'],['all','All']]" :key="s[0]" @click="setStatus(s[0])"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition" :class="status === s[0] ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ s[1] }}</button>
            </div>
            <button v-if="isConsultant" @click="toggleMine" class="rounded-xl px-3 py-2 text-sm font-semibold shadow-sm ring-1 transition" :class="scope === 'mine' ? 'bg-accent-500 text-white ring-accent-500' : 'bg-card text-ink-500 ring-line hover:bg-ink-50'">My consultations</button>
            <button v-if="canAdd" @click="openAdd" class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                New Consultation
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
          <div class="overflow-x-auto">
            <table class="min-w-[640px] w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-5 py-3">Patient</th><th scope="col" class="px-3 py-3">Location</th>
                        <th scope="col" class="px-3 py-3">From → To</th><th scope="col" class="px-3 py-3">Indication</th>
                        <th scope="col" class="px-3 py-3">Consultant</th><th scope="col" class="px-3 py-3">Date</th><th scope="col" class="px-5 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="c in consultations.data" :key="c.id" class="transition hover:bg-brand-50/40">
                        <td class="px-5 py-3">
                            <div class="font-semibold text-ink-800">{{ c.name }}</div>
                            <div class="nums text-xs text-ink-400">MRN {{ c.mrn }} · {{ c.age ?? '—' }}y · Bed {{ c.bed || '—' }}</div>
                        </td>
                        <td class="px-3 py-3 text-ink-600">{{ c.location || '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ c.from || '—' }} <span class="text-ink-300">→</span> {{ c.to || '—' }}</td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-1">
                                <span v-for="r in c.reasons" :key="r" class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700">{{ r }}</span>
                                <span v-if="c.other" class="rounded-full bg-ink-50 px-2 py-0.5 text-[11px] text-ink-500">{{ c.other }}</span>
                                <span v-if="!c.reasons.length && !c.other" class="text-ink-300">—</span>
                            </div>
                        </td>
                        <td class="px-3 py-3 text-ink-600">{{ c.consultant }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ c.date || '—' }}</td>
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2">
                                <span v-if="c.signoff" class="rounded-full bg-success-100 px-2.5 py-0.5 text-xs font-semibold text-success-600">Signed {{ c.signoff }}</span>
                                <span v-else class="rounded-full bg-accent-300/40 px-2.5 py-0.5 text-xs font-semibold text-accent-600">Active</span>
                                <button v-if="!c.signoff && canSignoff(c)" @click="signoff(c)" title="Sign off" class="rounded-lg px-2 py-1 text-xs font-semibold text-success-600 hover:bg-success-100">Sign off</button>
                                <button v-if="canEdit(c)" @click="openEdit(c)" title="Edit" class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-700 hover:bg-brand-50">Edit</button>
                                <button v-if="me.is_admin" @click="deleteConsult(c)" title="Delete" class="rounded-lg px-2 py-1 text-xs font-semibold text-danger-600 hover:bg-danger-100">Delete</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!consultations.data.length"><td colspan="7" class="px-5 py-10 text-center text-ink-400">No consultations match your filters.</td></tr>
                </tbody>
            </table>
          </div>
        </div>

        <!-- result-count announcement for screen readers (filters change the visible rows) -->
        <span class="sr-only" aria-live="polite" aria-atomic="true">
            {{ consultations.total ? `${consultations.total} consultation(s) found` : 'No results' }}
        </span>

        <div v-if="consultations.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-ink-500">
            <span class="nums">Showing {{ consultations.from }}–{{ consultations.to }} of {{ consultations.total }}</span>
            <div class="flex gap-1">
                <component :is="l.url ? Link : 'span'" v-for="l in consultations.links" :key="l.label" :href="l.url || undefined" preserve-scroll
                    class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold transition"
                    :class="l.active ? 'bg-brand-600 text-white' : (l.url ? 'bg-card text-ink-600 ring-1 ring-line hover:bg-ink-50' : 'text-ink-300')" v-html="l.label" />
            </div>
        </div>
        <!-- edit consultation modal -->
        <div v-if="editing" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="closeEdit">
            <div :ref="(el) => (a11yEdit.trapRef.value = el)" role="dialog" aria-modal="true" aria-labelledby="modal-title-cons-edit" @keydown="a11yEdit.onKeydown" class="max-h-[90vh] w-full max-w-2xl overflow-auto rounded-2xl bg-card p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between"><h3 id="modal-title-cons-edit" class="text-lg font-bold text-ink-900">Edit consultation</h3><button @click="closeEdit" aria-label="Close" class="text-ink-400 hover:text-ink-700">✕</button></div>
                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">MRN</label><input v-model="eForm.mrn" :class="[field, eForm.errors.mrn && 'border-danger-500']" /></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Patient name</label><input v-model="eForm.patient_name" :class="field" /></div>
                        <div class="grid grid-cols-2 gap-3"><div><label class="mb-1 block text-sm font-semibold text-ink-700">Age <span class="text-danger-500">*</span></label><input v-model="eForm.age" inputmode="numeric" :class="[field, eForm.errors.age && 'border-danger-500']" /><p v-if="eForm.errors.age" class="mt-1 text-xs text-danger-600">{{ eForm.errors.age }}</p></div><div><label class="mb-1 block text-sm font-semibold text-ink-700">Bed <span class="text-danger-500">*</span></label><input v-model="eForm.bed" :class="[field, eForm.errors.bed && 'border-danger-500']" /><p v-if="eForm.errors.bed" class="mt-1 text-xs text-danger-600">{{ eForm.errors.bed }}</p></div></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Location</label><select v-model="eForm.current_location" :class="field"><option>Ward</option><option>ICU</option><option>ER</option></select></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Date</label><input v-model="eForm.consultation_date" type="date" :max="today" :class="field" /></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">From service <span class="text-danger-500">*</span></label><input v-model="eForm.consultation_from" list="svc-list" :class="[field, eForm.errors.consultation_from && 'border-danger-500']" /><p v-if="eForm.errors.consultation_from" class="mt-1 text-xs text-danger-600">{{ eForm.errors.consultation_from }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">To service <span class="text-danger-500">*</span></label><input v-model="eForm.to_service" list="svc-list" :class="[field, eForm.errors.to_service && 'border-danger-500']" /><p v-if="eForm.errors.to_service" class="mt-1 text-xs text-danger-600">{{ eForm.errors.to_service }}</p></div>
                        <div class="sm:col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Receiving consultant <span v-if="eInternal" class="text-danger-500">*</span></label><select v-model="eForm.consultant_id" :disabled="!eInternal" :class="[field, 'disabled:bg-ink-50', eForm.errors.consultant_id && 'border-danger-500']"><option value="">Select consultant…</option><option v-for="c in ePick.list" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                            <p v-if="!eInternal" class="mt-1 text-xs text-ink-400">External / free-text service — no internal consultant is recorded.</p>
                            <p v-else-if="ePick.fallback" class="mt-1 text-xs text-warning-500">No on-service consultants for this specialty — showing all.</p>
                            <p v-if="eForm.errors.consultant_id" class="mt-1 text-xs text-danger-600">{{ eForm.errors.consultant_id }}</p></div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink-700">Indication <span class="text-danger-500">*</span></label>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="r in reasons" :key="r.id" class="cursor-pointer rounded-full border px-3 py-1 text-xs font-semibold transition" :class="eForm.indication.includes(r.id) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'"><input type="checkbox" :value="r.id" v-model="eForm.indication" class="hidden" /> {{ r.name }}</label>
                        </div>
                        <p v-if="eForm.errors.indication" class="mt-1 text-xs text-danger-600">{{ eForm.errors.indication }}</p>
                        <input v-model="eForm.other_indication" :class="[field, 'mt-2']" placeholder="Other indication (required when 'Other' is selected)" />
                    </div>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeEdit" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="eForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Update consultation</button></div>
                </form>
            </div>
        </div>

        <!-- new consultation modal -->
        <div v-if="showAdd" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="closeAdd">
            <div :ref="(el) => (a11yAdd.trapRef.value = el)" role="dialog" aria-modal="true" aria-labelledby="modal-title-cons-new" @keydown="a11yAdd.onKeydown" class="max-h-[90vh] w-full max-w-2xl overflow-auto rounded-2xl bg-card p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 id="modal-title-cons-new" class="text-lg font-bold text-ink-900">New consultation</h3>
                    <button @click="closeAdd" aria-label="Close" class="text-ink-400 hover:text-ink-700">✕</button>
                </div>
                <form @submit.prevent="submitAdd" class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">MRN <span class="text-danger-500">*</span></label><input v-model="cForm.mrn" :class="[field, cForm.errors.mrn && 'border-danger-500']" /><p v-if="cForm.errors.mrn" class="mt-1 text-xs text-danger-600">{{ cForm.errors.mrn }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Patient name <span class="text-danger-500">*</span></label><input v-model="cForm.patient_name" :class="[field, cForm.errors.patient_name && 'border-danger-500']" /></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="mb-1 block text-sm font-semibold text-ink-700">Age <span class="text-danger-500">*</span></label><input v-model="cForm.age" inputmode="numeric" :class="[field, cForm.errors.age && 'border-danger-500']" /><p v-if="cForm.errors.age" class="mt-1 text-xs text-danger-600">{{ cForm.errors.age }}</p></div>
                            <div><label class="mb-1 block text-sm font-semibold text-ink-700">Bed <span class="text-danger-500">*</span></label><input v-model="cForm.bed" :class="[field, cForm.errors.bed && 'border-danger-500']" /><p v-if="cForm.errors.bed" class="mt-1 text-xs text-danger-600">{{ cForm.errors.bed }}</p></div>
                        </div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Location</label><select v-model="cForm.current_location" :class="field"><option>Ward</option><option>ICU</option><option>ER</option></select></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Date <span class="text-danger-500">*</span></label><input v-model="cForm.consultation_date" type="date" :max="today" :class="field" /></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">From service <span class="text-danger-500">*</span></label><input v-model="cForm.consultation_from" list="svc-list" :class="[field, cForm.errors.consultation_from && 'border-danger-500']" placeholder="Referring service" /><p v-if="cForm.errors.consultation_from" class="mt-1 text-xs text-danger-600">{{ cForm.errors.consultation_from }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">To service <span class="text-danger-500">*</span></label><input v-model="cForm.to_service" list="svc-list" :class="[field, cForm.errors.to_service && 'border-danger-500']" placeholder="Consulted service" /><p v-if="cForm.errors.to_service" class="mt-1 text-xs text-danger-600">{{ cForm.errors.to_service }}</p></div>
                        <datalist id="svc-list"><option v-for="s in specialties" :key="s.id" :value="s.name" /></datalist>
                        <div class="sm:col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Receiving consultant <span v-if="cInternal" class="text-danger-500">*</span></label><select v-model="cForm.consultant_id" :disabled="!cInternal" :class="[field, 'disabled:bg-ink-50', cForm.errors.consultant_id && 'border-danger-500']"><option value="">Select consultant…</option><option v-for="c in cPick.list" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                            <p v-if="!cInternal" class="mt-1 text-xs text-ink-400">External / free-text service — no internal consultant is recorded.</p>
                            <p v-else-if="cPick.fallback" class="mt-1 text-xs text-warning-500">No on-service consultants for this specialty — showing all.</p>
                            <p v-if="cForm.errors.consultant_id" class="mt-1 text-xs text-danger-600">{{ cForm.errors.consultant_id }}</p></div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink-700">Indication <span class="text-danger-500">*</span></label>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="r in reasons" :key="r.id" class="cursor-pointer rounded-full border px-3 py-1 text-xs font-semibold transition"
                                :class="cForm.indication.includes(r.id) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'">
                                <input type="checkbox" :value="r.id" v-model="cForm.indication" class="hidden" /> {{ r.name }}
                            </label>
                        </div>
                        <p v-if="cForm.errors.indication" class="mt-1 text-xs text-danger-600">{{ cForm.errors.indication }}</p>
                        <input v-model="cForm.other_indication" :class="[field, 'mt-2']" placeholder="Other indication (required when 'Other' is selected)" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeAdd" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                        <button type="submit" :disabled="cForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Create consultation</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
