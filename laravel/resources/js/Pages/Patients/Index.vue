<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ admissions: Object, filters: Object, stats: Object, consultants: Array });

const page = usePage();
const me = computed(() => page.props.auth.user);
const canAssign = computed(() => me.value.is_admin || me.value.can.assign);
const canReassign = computed(() => me.value.is_admin || me.value.can.assign || me.value.can.manage);
const isConsultant = computed(() => me.value.is_admin || me.value.role === 3);
const isObserver = computed(() => me.value.role === 5);
const canManage = (row) => me.value.is_admin || me.value.can.manage || row.consultant_id === me.value.id;

const search = ref(props.filters.search || '');
const location = ref(props.filters.location || '');
const view = ref(props.filters.view || '');
let timer = null;

// action modal
const modal = ref(null); // { mode, row }
const today = new Date().toISOString().slice(0, 10);
const aForm = useForm({ consultant_id: '' });
const dForm = useForm({ outcome: 'Alive', discharge_date: today });
const tForm = useForm({ target: 'ICU' });
const openModal = (mode, row) => {
    modal.value = { mode, row };
    if (mode === 'assign') aForm.consultant_id = row.consultant_id || '';
    if (mode === 'transfer') tForm.target = row.location === 'ICU' ? 'Ward' : 'ICU';
};
const closeModal = () => (modal.value = null);
const opts = { preserveScroll: true, onSuccess: closeModal };
const submitAssign = () => aForm.post(`/admissions/${modal.value.row.id}/assign`, opts);
const submitDischarge = () => dForm.post(`/admissions/${modal.value.row.id}/discharge`, opts);
const submitTransfer = () => tForm.post(`/admissions/${modal.value.row.id}/transfer`, opts);

const apply = () => router.get('/patients',
    { search: search.value || undefined, location: location.value || undefined, view: view.value || undefined },
    { preserveState: true, replace: true, preserveScroll: true });

watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 300); });
const setLocation = (loc) => { location.value = location.value === loc ? '' : loc; apply(); };
const setView = (v) => { view.value = view.value === v ? '' : v; apply(); };

// quick row actions
const quick = (url) => router.post(url, {}, { preserveScroll: true });

// shuffle + bulk reassign
const shuffle = () => { if (confirm('Auto-assign all unassigned patients across on-service consultants?')) router.post('/admissions/shuffle', {}, { preserveScroll: true }); };
const reassign = ref(false);
const rForm = useForm({ from_consultant_id: '', to_consultant_id: '' });
const submitReassign = () => rForm.post('/admissions/reassign', { preserveScroll: true, onSuccess: () => { reassign.value = false; rForm.reset(); } });

const locTone = (l) => l === 'ICU' ? 'bg-danger-100 text-danger-600' : l === 'ER' ? 'bg-warning-100 text-warning-500' : 'bg-brand-100 text-brand-700';
const losTone = (b) => b === 'short' ? 'bg-success-100 text-success-600' : b === 'long' ? 'bg-danger-100 text-danger-600' : 'bg-warning-100 text-warning-500';
</script>

<template>
    <Head title="Patients" />
    <AppLayout title="Active Patients">
        <!-- toolbar -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="flex flex-wrap gap-2">
                <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">Census <span class="nums ml-1 text-brand-700">{{ stats.total }}</span></span>
                <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">Ward <span class="nums ml-1 text-brand-700">{{ stats.ward }}</span></span>
                <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">ICU <span class="nums ml-1 text-danger-600">{{ stats.icu }}</span></span>
                <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">Long-term <span class="nums ml-1 text-accent-600">{{ stats.longterm }}</span></span>
                <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">TB <span class="nums ml-1 text-info-500">{{ stats.tb }}</span></span>
            </div>
            <div class="relative ml-auto">
                <svg class="pointer-events-none absolute left-3 top-2.5 h-5 w-5 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" /></svg>
                <input v-model="search" placeholder="Search name or MRN…" class="w-64 rounded-xl border border-ink-200 bg-white py-2 pl-10 pr-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
            </div>
            <div class="flex gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-ink-100">
                <button v-for="l in ['Ward','ICU','ER']" :key="l" @click="setLocation(l)"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                    :class="location === l ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ l }}</button>
            </div>
            <div class="flex gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-ink-100">
                <button v-for="v in [['longterm','Long-term'],['tb','TB']]" :key="v[0]" @click="setView(v[0])"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                    :class="view === v[0] ? 'bg-accent-500 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ v[1] }}</button>
            </div>
            <div v-if="canAssign" class="flex gap-2">
                <button @click="shuffle" title="Auto-assign unassigned patients" class="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                    Shuffle
                </button>
                <button v-if="canReassign" @click="reassign = true" title="Bulk reassign a consultant's patients" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-600 shadow ring-1 ring-ink-200 transition hover:bg-ink-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
                    Reassign
                </button>
            </div>
        </div>

        <!-- table -->
        <div class="overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th class="px-5 py-3">Patient</th>
                        <th class="px-3 py-3">Age / Sex</th>
                        <th class="px-3 py-3">Bed</th>
                        <th class="px-3 py-3">Location</th>
                        <th class="px-3 py-3">Consultant</th>
                        <th class="px-3 py-3">Admitted</th>
                        <th class="px-3 py-3">LOS</th>
                        <th class="px-3 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-50">
                    <tr v-for="a in admissions.data" :key="a.id" class="transition hover:bg-brand-50/40">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-3">
                                <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-ink-50 text-xs font-bold text-ink-500">{{ (a.name||'?').slice(0,2).toUpperCase() }}</div>
                                <div>
                                    <div class="font-semibold text-ink-800">{{ a.name }}</div>
                                    <div class="nums text-xs text-ink-400">MRN {{ a.mrn }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="nums px-3 py-3 text-ink-600">{{ a.age ?? '—' }} · {{ (a.gender||'—').slice(0,1) }}</td>
                        <td class="nums px-3 py-3 text-ink-600">{{ a.bed || '—' }}</td>
                        <td class="px-3 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="locTone(a.location)">{{ a.location || '—' }}</span></td>
                        <td class="px-3 py-3 text-ink-600">{{ a.consultant }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ a.admit_date || '—' }}</td>
                        <td class="px-3 py-3">
                            <span v-if="a.los !== null" class="nums rounded-full px-2.5 py-0.5 text-xs font-bold" :class="losTone(a.los_band)">{{ a.los }}d</span>
                            <span v-else class="text-ink-400">—</span>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-1">
                                <span v-if="a.is_new" class="rounded-full bg-info-100 px-2 py-0.5 text-[11px] font-semibold text-info-500">New</span>
                                <span v-if="a.is_longterm" class="rounded-full bg-accent-300/40 px-2 py-0.5 text-[11px] font-semibold text-accent-600">Long-term</span>
                                <span v-if="a.medically_discharged" class="rounded-full bg-warning-100 px-2 py-0.5 text-[11px] font-semibold text-warning-500">Med. discharged</span>
                                <span v-if="a.dx_count" class="rounded-full bg-ink-50 px-2 py-0.5 text-[11px] font-semibold text-ink-500">{{ a.dx_count }} dx</span>
                            </div>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <button v-if="canAssign" @click="openModal('assign', a)" title="Assign consultant" class="grid h-8 w-8 place-items-center rounded-lg text-ink-400 transition hover:bg-info-100 hover:text-info-500">
                                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" /></svg>
                                </button>
                                <button v-if="isConsultant && a.consultant_id !== me.id" @click="quick(`/admissions/${a.id}/assign-to-me`)" title="Assign to me" class="grid h-8 w-8 place-items-center rounded-lg text-ink-400 transition hover:bg-info-100 hover:text-info-500">
                                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                                </button>
                                <button v-if="!isObserver" @click="quick(`/admissions/${a.id}/longterm`)" :title="a.is_longterm ? 'Remove long-term' : 'Mark long-term'" class="grid h-8 w-8 place-items-center rounded-lg transition hover:bg-accent-300/40" :class="a.is_longterm ? 'text-accent-600' : 'text-ink-400 hover:text-accent-600'">
                                    <svg class="h-4.5 w-4.5" :fill="a.is_longterm ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" /></svg>
                                </button>
                                <button v-if="canManage(a)" @click="openModal('transfer', a)" title="Transfer ward/ICU" class="grid h-8 w-8 place-items-center rounded-lg text-ink-400 transition hover:bg-brand-100 hover:text-brand-700">
                                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
                                </button>
                                <button v-if="canManage(a)" @click="openModal('discharge', a)" title="Discharge" class="grid h-8 w-8 place-items-center rounded-lg text-ink-400 transition hover:bg-success-100 hover:text-success-600">
                                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                </button>
                                <span v-if="isObserver" class="text-xs text-ink-300">—</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!admissions.data.length"><td colspan="9" class="px-5 py-10 text-center text-ink-400">No patients match your filters.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- pagination -->
        <div v-if="admissions.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-ink-500">
            <span class="nums">Showing {{ admissions.from }}–{{ admissions.to }} of {{ admissions.total }}</span>
            <div class="flex gap-1">
                <component :is="l.url ? Link : 'span'" v-for="l in admissions.links" :key="l.label" :href="l.url || undefined" preserve-scroll
                    class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold transition"
                    :class="l.active ? 'bg-brand-600 text-white' : (l.url ? 'bg-white text-ink-600 ring-1 ring-ink-100 hover:bg-ink-50' : 'text-ink-300')"
                    v-html="l.label" />
            </div>
        </div>
        <!-- action modal -->
        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <div v-if="modal" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="closeModal">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="mb-4 flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-bold capitalize text-ink-900">{{ modal.mode }}</h3>
                            <p class="text-sm text-ink-400">{{ modal.row.name }} · MRN {{ modal.row.mrn }}</p>
                        </div>
                        <button @click="closeModal" class="text-ink-400 hover:text-ink-700">✕</button>
                    </div>

                    <!-- Assign -->
                    <form v-if="modal.mode === 'assign'" @submit.prevent="submitAssign" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink-700">Consultant</label>
                            <select v-model="aForm.consultant_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500">
                                <option value="">Select consultant…</option>
                                <option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <p v-if="aForm.errors.consultant_id" class="mt-1 text-xs text-danger-600">{{ aForm.errors.consultant_id }}</p>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                            <button type="submit" :disabled="aForm.processing || !aForm.consultant_id" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Assign</button>
                        </div>
                    </form>

                    <!-- Discharge -->
                    <form v-else-if="modal.mode === 'discharge'" @submit.prevent="submitDischarge" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink-700">Outcome</label>
                            <select v-model="dForm.outcome" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500">
                                <option>Alive</option><option>Dead</option><option>LAMA</option><option>DAMA</option><option>Transferred</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-ink-700">Discharge date</label>
                            <input v-model="dForm.discharge_date" type="date" :max="today" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" />
                            <p v-if="dForm.errors.discharge_date" class="mt-1 text-xs text-danger-600">{{ dForm.errors.discharge_date }}</p>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                            <button type="submit" :disabled="dForm.processing" class="rounded-xl bg-success-600 px-5 py-2 text-sm font-semibold text-white hover:bg-success-700 disabled:opacity-50">Discharge</button>
                        </div>
                    </form>

                    <!-- Transfer -->
                    <form v-else @submit.prevent="submitTransfer" class="space-y-4">
                        <p class="text-sm text-ink-600">Currently in <span class="font-semibold">{{ modal.row.location || '—' }}</span>. Transfer to:</p>
                        <div class="flex gap-2">
                            <label v-for="loc in ['Ward','ICU']" :key="loc" class="flex-1 cursor-pointer rounded-xl border-2 px-4 py-3 text-center text-sm font-semibold transition"
                                :class="tForm.target === loc ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'">
                                <input type="radio" v-model="tForm.target" :value="loc" class="hidden" /> {{ loc }}
                            </label>
                        </div>
                        <p class="text-xs text-ink-400">This closes the current episode as a transfer and opens a new one at the destination (diagnoses carried forward).</p>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                            <button type="submit" :disabled="tForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Transfer</button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>

        <!-- bulk reassign modal -->
        <div v-if="reassign" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="reassign = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-ink-900">Reassign a consultant's patients</h3>
                <p class="mb-4 text-sm text-ink-400">Moves every active patient from one consultant to another.</p>
                <form @submit.prevent="submitReassign" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink-700">From consultant</label>
                        <select v-model="rForm.from_consultant_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500">
                            <option value="">Select…</option><option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="rForm.errors.from_consultant_id" class="mt-1 text-xs text-danger-600">{{ rForm.errors.from_consultant_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink-700">To consultant</label>
                        <select v-model="rForm.to_consultant_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500">
                            <option value="">Select…</option><option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="rForm.errors.to_consultant_id" class="mt-1 text-xs text-danger-600">{{ rForm.errors.to_consultant_id }}</p>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="reassign = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                        <button type="submit" :disabled="rForm.processing || !rForm.from_consultant_id || !rForm.to_consultant_id" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Reassign all</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
