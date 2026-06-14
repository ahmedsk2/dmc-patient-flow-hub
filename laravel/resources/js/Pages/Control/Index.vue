<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from '@/composables/useConfirm';

const { ask } = useConfirm();

const props = defineProps({ settings: Object, users: Object, filters: Object, roles: Object, counts: Object, specialties: Array, reasons: Array, settingHistory: Array, reportRecipients: { type: Array, default: () => [] } });

const fieldLabels = {
    min_hospitalist: 'Min hospitalist census', max_hospitalist: 'Max hospitalist census',
    min_subs: 'Min subspecialty', max_subs: 'Max subspecialty',
    short_los: 'Short LOS', long_los: 'Long LOS',
    ward_beds: 'Licensed ward beds', icu_beds: 'Licensed ICU beds',
    readmission_window_days: 'Readmission window (days)', mfa_enforcement: 'MFA enforcement',
    alert_overcensus_pct: 'Over-census alert (%)', alert_boarding_max: 'Boarding alert (max)',
    alert_readmit_rate_pct: 'Readmission-rate alert (%)', alert_deaths_delta_pct: 'Mortality-rise alert (%)',
    idle_timeout_minutes: 'Idle session timeout (min)', abs_timeout_minutes: 'Absolute session cap (min)',
    failed_login_notify_threshold: 'Failed-login alert threshold', dq_los_multiplier: 'Data-quality LOS multiplier',
};

const tab = ref('overview');

const sForm = useForm({
    min_hospitalist: props.settings.min_hospitalist, max_hospitalist: props.settings.max_hospitalist,
    min_subs: props.settings.min_subs, max_subs: props.settings.max_subs,
    short_los: props.settings.short_los, long_los: props.settings.long_los,
    ward_beds: props.settings.ward_beds ?? 50, icu_beds: props.settings.icu_beds ?? 10,
    readmission_window_days: props.settings.readmission_window_days ?? 3,
    mfa_enforcement: props.settings.mfa_enforcement ?? 0,
    alert_overcensus_pct: props.settings.alert_overcensus_pct ?? 100,
    alert_boarding_max: props.settings.alert_boarding_max ?? 5,
    alert_readmit_rate_pct: props.settings.alert_readmit_rate_pct ?? 10,
    alert_deaths_delta_pct: props.settings.alert_deaths_delta_pct ?? 50,
    // Phase 4 — Items 2/3/6
    idle_timeout_minutes: props.settings.idle_timeout_minutes ?? 30,
    abs_timeout_minutes: props.settings.abs_timeout_minutes ?? 0,
    failed_login_notify_threshold: props.settings.failed_login_notify_threshold ?? 5,
    dq_los_multiplier: props.settings.dq_los_multiplier ?? 2,
});
const saveSettings = () => sForm.put('/control/settings', { preserveScroll: true });

const q = ref(props.filters.q || '');
let timer = null;
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => router.get('/control', { q: q.value || undefined }, { preserveState: true, replace: true, preserveScroll: true }), 300); });

// client-side sort (current page) on Full name / On service — L1-15
const sort = ref({ key: null, dir: 1 });
const toggleSort = (key) => { sort.value = sort.value.key === key ? { key, dir: -sort.value.dir } : { key, dir: 1 }; };
const ariaSort = (key) => (sort.value.key === key ? (sort.value.dir === 1 ? 'ascending' : 'descending') : 'none');
const sortedUsers = computed(() => {
    const { key, dir } = sort.value;
    if (!key) return props.users.data;
    return [...props.users.data].sort((a, b) => dir * (key === 'full_name'
        ? String(a.full_name || a.name || '').localeCompare(String(b.full_name || b.name || ''), undefined, { sensitivity: 'base' })
        : (b.on_service ? 1 : 0) - (a.on_service ? 1 : 0)));   // ascending = on-service first
});

const editing = ref(null);
const uForm = useForm({ username: '', full_name: '', email: '', role: 5, active: true, on_service: false, specialty_id: '', can_assign: false, can_add: false, can_manage: false, can_modify: false });
const editUser = (u) => {
    editing.value = u;
    uForm.clearErrors();
    uForm.username = u.username || ''; uForm.full_name = u.full_name || ''; uForm.email = u.email || '';
    uForm.role = u.role; uForm.active = u.active; uForm.on_service = u.on_service; uForm.specialty_id = u.specialty_id || '';
    uForm.can_assign = u.can.assign; uForm.can_add = u.can.add; uForm.can_manage = u.can.manage; uForm.can_modify = u.can.modify;
};
const saveUser = () => uForm.put(`/control/users/${editing.value.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) });
const resetMfa = async (u) => { if (await ask('Reset two-factor', `Reset two-factor for ${u.username}. They'll re-enrol on next login.`, 'neutral')) router.post(`/control/users/${u.id}/reset-mfa`, {}, { preserveScroll: true }); };
const sendReset = (u) => router.post(`/control/users/${u.id}/send-reset`, {}, { preserveScroll: true });
const deleteUser = async (u) => {
    if (await ask('Delete user', `Permanently delete ${u.username}. Their historical admissions/consultations are kept (attribution cleared). This cannot be undone.`, 'danger'))
        router.delete(`/control/users/${u.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) });
};

// Esc closes the user-edit modal
const onEsc = (e) => { if (e.key === 'Escape') editing.value = null; };
onMounted(() => window.addEventListener('keydown', onEsc));
onUnmounted(() => window.removeEventListener('keydown', onEsc));

// reference data
const specForm = useForm({ name: '', is_subspecialty: true, is_external: false });
const submitSpec = () => specForm.post('/control/specialties', { preserveScroll: true, onSuccess: () => specForm.reset() });
const reasonForm = useForm({ name: '' });
const submitReason = () => reasonForm.post('/control/reasons', { preserveScroll: true, onSuccess: () => reasonForm.reset() });

// §3.3: monthly-report email recipients
const recipientForm = useForm({ email: '' });
const submitRecipient = () => recipientForm.post('/control/report-recipients', { preserveScroll: true, onSuccess: () => recipientForm.reset() });
const removeRecipient = async (r) => { if (await ask('Remove recipient', `Stop sending the monthly report to ${r.email}?`, 'neutral')) router.delete(`/control/report-recipients/${r.id}`, { preserveScroll: true }); };

const countCards = [
    ['Users', 'users'], ['Active users', 'active_users'], ['Patients', 'patients'],
    ['Admissions', 'admissions'], ['Consultations', 'consultations'], ['ICD-10 codes', 'icd10'], ['Specialties', 'specialties'],
];
const field = 'w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
const roleTone = (r) => r === 0 ? 'bg-danger-100 text-danger-600' : r === 3 ? 'bg-brand-100 text-brand-700' : 'bg-ink-100 text-ink-500';
</script>

<template>
    <Head title="Control Panel" />
    <AppLayout title="Control Panel">
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line w-fit">
                <button v-for="t in ['overview','settings','users','reference']" :key="t" @click="tab = t"
                    class="rounded-lg px-4 py-2 text-sm font-semibold capitalize transition" :class="tab === t ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ t }}</button>
            </div>
            <!-- Phase 4 — Item 1: opens the soft-delete "Recently Deleted" view -->
            <button @click="router.visit('/trashed')"
                class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500 ring-1 ring-line transition hover:bg-ink-50">
                Recently Deleted
            </button>
        </div>

        <!-- Overview -->
        <div v-show="tab === 'overview'" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <div v-for="[label, key] in countCards" :key="key" class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-400">{{ label }}</div>
                <div class="nums mt-1 text-3xl font-bold text-brand-700">{{ counts[key].toLocaleString() }}</div>
            </div>
        </div>

        <!-- Settings -->
        <div v-show="tab === 'settings'" class="max-w-2xl">
        <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
            <h3 class="mb-1 font-bold text-ink-800">Operational thresholds</h3>
            <p class="mb-5 text-sm text-ink-400">Drive the shuffle/assignment balance and LOS bands across the app.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Min hospitalist census</span><input v-model="sForm.min_hospitalist" type="number" :class="field" /></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Max hospitalist census</span><input v-model="sForm.max_hospitalist" type="number" :class="field" /></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Min subspecialty</span><input v-model="sForm.min_subs" type="number" :class="field" /></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Max subspecialty</span><input v-model="sForm.max_subs" type="number" :class="field" /></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Short LOS (days)</span><input v-model="sForm.short_los" type="number" :class="field" /></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Long LOS (days)</span><input v-model="sForm.long_los" type="number" :class="field" /></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Licensed ward beds</span><input v-model="sForm.ward_beds" type="number" min="1" :class="field" /><span class="mt-1 block text-xs text-ink-400">Denominator for dashboard Bed Occupancy (non-ICU). Set to your real count.</span></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Licensed ICU beds</span><input v-model="sForm.icu_beds" type="number" min="0" :class="field" /></label>
                <label class="block sm:col-span-2"><span class="mb-1 block text-sm font-semibold text-ink-700">Readmission window (days)</span><input v-model="sForm.readmission_window_days" type="number" min="0" max="30" :class="field" /><span class="mt-1 block text-xs text-ink-400">A new admission within this many days of a prior real discharge counts as a readmission (default 3 = the 72-hour rule). Drives the stats KPI, the board badge, and the registry filter.</span></label>
                <label class="block sm:col-span-2"><span class="mb-1 block text-sm font-semibold text-ink-700">Two-factor enforcement</span>
                    <select v-model.number="sForm.mfa_enforcement" :class="field">
                        <option :value="0">Optional (users opt in)</option>
                        <option :value="1">Required for administrators</option>
                        <option :value="2">Required for everyone</option>
                    </select>
                </label>
            </div>

            <!-- Phase 1 — dashboard alert thresholds (clinician-tunable) -->
            <h4 class="mb-1 mt-6 font-bold text-ink-800">Alert thresholds</h4>
            <p class="mb-4 text-sm text-ink-400">Fire the dashboard alert strip when these operational limits are crossed. Defaults are conservative placeholders — review for your unit.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Over-census alert (%)</span><input v-model="sForm.alert_overcensus_pct" type="number" min="50" max="200" :class="field" /><span class="mt-1 block text-xs text-ink-400">Occupancy at/above this fires an over-census alert (100 = full ward).</span></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Boarding alert (max)</span><input v-model="sForm.alert_boarding_max" type="number" min="0" max="100" :class="field" /><span class="mt-1 block text-xs text-ink-400">More boarding (medically-cleared) patients than this fires an alert.</span></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Readmission-rate alert (%)</span><input v-model="sForm.alert_readmit_rate_pct" type="number" min="1" max="100" :class="field" /><span class="mt-1 block text-xs text-ink-400">YTD non-ICU readmission rate at/above this fires an alert.</span></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Mortality-rise alert (%)</span><input v-model="sForm.alert_deaths_delta_pct" type="number" min="10" max="500" :class="field" /><span class="mt-1 block text-xs text-ink-400">Month-over-month rise in deaths at/above this fires an alert.</span></label>
            </div>

            <!-- Phase 4 — security & data-quality thresholds -->
            <h4 class="mb-1 mt-6 font-bold text-ink-800">Security &amp; data quality</h4>
            <p class="mb-4 text-sm text-ink-400">Session timeouts, failed-login alerting, and the data-quality stale-episode threshold.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Idle session timeout (min)</span><input v-model="sForm.idle_timeout_minutes" type="number" min="5" max="480" :class="field" /><span class="mt-1 block text-xs text-ink-400">Sign a user out after this many minutes of inactivity (default 30).</span></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Absolute session cap (min)</span><input v-model="sForm.abs_timeout_minutes" type="number" min="0" max="1440" :class="field" /><span class="mt-1 block text-xs text-ink-400">Maximum session length regardless of activity; 0 disables it (default off).</span></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Failed-login alert threshold</span><input v-model="sForm.failed_login_notify_threshold" type="number" min="0" max="50" :class="field" /><span class="mt-1 block text-xs text-ink-400">Notify admins after this many failed logins for one account in 10 minutes; 0 disables it.</span></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Data-quality LOS multiplier</span><input v-model="sForm.dq_los_multiplier" type="number" min="1" max="10" :class="field" /><span class="mt-1 block text-xs text-ink-400">Flag active non-long-term episodes with LOS &gt; Long&nbsp;LOS × this (default 2).</span></label>
            </div>

            <div class="mt-5 flex items-center gap-3">
                <button @click="saveSettings" :disabled="sForm.processing" class="rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save settings</button>
                <span v-if="sForm.recentlySuccessful" class="text-sm font-semibold text-success-600">Saved ✓</span>
            </div>
        </div>

        <!-- change history (append-only; tracks e.g. ward capacity over time) -->
        <div class="mt-5 rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
            <h3 class="mb-1 font-bold text-ink-800">Change history</h3>
            <p class="mb-4 text-sm text-ink-400">Every settings change is recorded — who changed what, from what, to what, and when.</p>
            <div v-if="settingHistory.length" class="divide-y divide-line">
                <div v-for="(h, i) in settingHistory" :key="i" class="flex flex-wrap items-baseline gap-x-2 py-2 text-sm">
                    <span class="font-semibold text-ink-700">{{ fieldLabels[h.field] || h.field }}</span>
                    <span class="nums text-ink-400 line-through">{{ h.old_value ?? '—' }}</span>
                    <span class="text-ink-300">→</span>
                    <span class="nums font-bold text-brand-700">{{ h.new_value }}</span>
                    <span class="ml-auto text-xs text-ink-400">{{ h.changed_by || 'system' }} · {{ h.created_at?.slice(0, 16).replace('T', ' ') }}</span>
                </div>
            </div>
            <p v-else class="text-sm text-ink-300">No changes recorded yet.</p>
        </div>
        </div>

        <!-- Users -->
        <div v-show="tab === 'users'">
            <div class="mb-3"><input v-model="q" :class="[field, 'max-w-sm']" placeholder="Search users…" /></div>
            <div class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-5 py-3" :aria-sort="ariaSort('full_name')">
                            <button @click="toggleSort('full_name')" class="inline-flex items-center gap-1 uppercase tracking-wide hover:text-ink-600">Full name<span aria-hidden="true" class="text-[10px]">{{ sort.key === 'full_name' ? (sort.dir === 1 ? '▲' : '▼') : '↕' }}</span></button>
                        </th>
                        <th scope="col" class="px-3 py-3">Role</th><th scope="col" class="px-3 py-3">Capabilities</th>
                        <th scope="col" class="px-3 py-3" :aria-sort="ariaSort('on_service')">
                            <button @click="toggleSort('on_service')" class="inline-flex items-center gap-1 uppercase tracking-wide hover:text-ink-600">On service<span aria-hidden="true" class="text-[10px]">{{ sort.key === 'on_service' ? (sort.dir === 1 ? '▲' : '▼') : '↕' }}</span></button>
                        </th>
                        <th scope="col" class="px-3 py-3">Status</th><th scope="col" class="px-5 py-3 text-right">Edit</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="u in sortedUsers" :key="u.id" class="hover:bg-brand-50/40">
                            <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ u.name }}</div><div class="text-xs text-ink-400">{{ u.username }} · {{ u.email || '—' }}</div></td>
                            <td class="px-3 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="roleTone(u.role)">{{ u.role_label }}</span></td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="(v, k) in u.can" :key="k" v-show="v" class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold capitalize text-brand-700">{{ k }}</span>
                                    <span v-if="!Object.values(u.can).some(Boolean)" class="text-xs text-ink-300">—</span>
                                </div>
                            </td>
                            <td class="px-3 py-3"><span v-if="u.on_service" class="rounded-full bg-brand-100 px-2.5 py-0.5 text-xs font-semibold text-brand-700">On</span><span v-else class="text-xs text-ink-300">—</span></td>
                            <td class="px-3 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="u.active ? 'bg-success-100 text-success-600' : 'bg-ink-100 text-ink-400'">{{ u.active ? 'Active' : 'Disabled' }}</span></td>
                            <td class="px-5 py-3 text-right"><button @click="editUser(u)" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-brand-700 hover:bg-brand-50">Edit</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="users.last_page > 1" class="mt-4 flex justify-end gap-1">
                <component :is="l.url ? Link : 'span'" v-for="l in users.links" :key="l.label" :href="l.url || undefined" preserve-scroll
                    class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold" :class="l.active ? 'bg-brand-600 text-white' : (l.url ? 'bg-card text-ink-600 ring-1 ring-line hover:bg-ink-50' : 'text-ink-300')" v-html="l.label" />
            </div>
        </div>

        <!-- Reference data -->
        <div v-show="tab === 'reference'" class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h3 class="mb-3 font-bold text-ink-800">Specialties</h3>
                <div class="mb-4 flex max-h-48 flex-wrap gap-2 overflow-auto"><span v-for="s in specialties" :key="s.id" class="rounded-full px-3 py-1 text-sm" :class="s.is_external ? 'bg-accent-300/30 text-accent-600' : 'bg-app text-ink-600'">{{ s.name }}<span v-if="s.is_external" class="ml-1 text-[10px] font-semibold uppercase">ext</span></span></div>
                <form @submit.prevent="submitSpec" class="flex gap-2"><input v-model="specForm.name" :class="field" placeholder="New specialty" /><button :disabled="specForm.processing || !specForm.name" class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Add</button></form>
                <label class="mt-2 flex items-center gap-2 text-xs text-ink-500"><input type="checkbox" v-model="specForm.is_subspecialty" class="rounded text-brand-600" /> Subspecialty (uncheck for hospitalist)</label>
                <label class="mt-1 flex items-center gap-2 text-xs text-ink-500"><input type="checkbox" v-model="specForm.is_external" class="rounded text-brand-600" /> External / allied service (transfer-out target only — not an internal specialty)</label>
            </div>
            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h3 class="mb-3 font-bold text-ink-800">Consultation indications</h3>
                <div class="mb-4 flex max-h-48 flex-wrap gap-2 overflow-auto"><span v-for="r in reasons" :key="r.id" class="rounded-full bg-app px-3 py-1 text-sm text-ink-600">{{ r.name }}</span></div>
                <form @submit.prevent="submitReason" class="flex gap-2"><input v-model="reasonForm.name" :class="field" placeholder="New indication" /><button :disabled="reasonForm.processing || !reasonForm.name" class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Add</button></form>
            </div>

            <!-- §3.3: monthly-report email recipients -->
            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line lg:col-span-2">
                <h3 class="mb-1 font-bold text-ink-800">Monthly report recipients</h3>
                <p class="mb-3 text-sm text-ink-400">Each address receives the prior month's activity booklet (PDF) automatically on the 1st of the month.</p>
                <ul v-if="reportRecipients.length" class="mb-4 divide-y divide-line rounded-xl ring-1 ring-line">
                    <li v-for="r in reportRecipients" :key="r.id" class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm">
                        <span class="text-ink-700">{{ r.email }}<span v-if="!r.active" class="ml-2 rounded-full bg-ink-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-ink-500">inactive</span></span>
                        <button @click="removeRecipient(r)" class="rounded-lg px-3 py-1 text-xs font-semibold text-danger-600 hover:bg-danger-50">Remove</button>
                    </li>
                </ul>
                <p v-else class="mb-4 text-sm text-ink-300">No recipients yet — the scheduled email will send to no one.</p>
                <form @submit.prevent="submitRecipient" class="flex gap-2">
                    <input v-model="recipientForm.email" type="email" :class="field" placeholder="name@dmc-im.com" />
                    <button :disabled="recipientForm.processing || !recipientForm.email" class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Add</button>
                </form>
                <p v-if="recipientForm.errors.email" class="mt-1 text-xs text-danger-600">{{ recipientForm.errors.email }}</p>
            </div>
        </div>

        <!-- edit user modal -->
        <div v-if="editing" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="editing = null">
            <div class="w-full max-w-md rounded-2xl bg-card p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-ink-900">{{ editing.name }}</h3>
                <p class="mb-4 text-sm text-ink-400">{{ editing.username }}</p>
                <div class="space-y-4">
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Username</span>
                        <input v-model="uForm.username" :class="field" placeholder="login name" />
                        <span v-if="uForm.errors.username" class="mt-1 block text-xs text-danger-600">{{ uForm.errors.username }}</span>
                        <span class="mt-1 block text-xs text-ink-400">The login name — changing it changes what they type to sign in.</span>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Full name</span>
                            <input v-model="uForm.full_name" :class="field" placeholder="Dr …" />
                            <span v-if="uForm.errors.full_name" class="mt-1 block text-xs text-danger-600">{{ uForm.errors.full_name }}</span>
                        </label>
                        <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Email</span>
                            <input v-model="uForm.email" type="email" :class="field" placeholder="name@dmc-im.com" />
                            <span v-if="uForm.errors.email" class="mt-1 block text-xs text-danger-600">{{ uForm.errors.email }}</span>
                        </label>
                    </div>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Role</span>
                        <select v-model.number="uForm.role" :class="field"><option v-for="(label, id) in roles" :key="id" :value="Number(id)">{{ label }}</option></select>
                    </label>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm font-medium text-ink-700"><input type="checkbox" v-model="uForm.active" class="rounded text-brand-600" /> Active</label>
                        <label class="flex items-center gap-2 text-sm font-medium text-ink-700"><input type="checkbox" v-model="uForm.on_service" class="rounded text-brand-600" /> On service</label>
                    </div>
                    <label v-if="uForm.role === 3" class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Specialty</span>
                        <select v-model="uForm.specialty_id" :class="field"><option value="">—</option><option v-for="s in specialties.filter((x) => !x.is_external)" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="uForm.can_assign" class="rounded text-brand-600" /> Can assign</label>
                        <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="uForm.can_add" class="rounded text-brand-600" /> Can add</label>
                        <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="uForm.can_manage" class="rounded text-brand-600" /> Can manage</label>
                        <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="uForm.can_modify" class="rounded text-brand-600" /> Can modify</label>
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-end gap-2">
                    <button v-if="editing.email" @click="sendReset(editing)" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 hover:bg-ink-50">Send reset email</button>
                    <button v-if="editing.mfa" @click="resetMfa(editing)" class="rounded-xl px-3 py-2 text-sm font-semibold text-danger-600 hover:bg-danger-100">Reset MFA</button>
                    <button @click="deleteUser(editing)" class="mr-auto rounded-xl px-3 py-2 text-sm font-semibold text-danger-600 hover:bg-danger-100">Delete</button>
                    <button @click="editing = null" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                    <button @click="saveUser" :disabled="uForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
