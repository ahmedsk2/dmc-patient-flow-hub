<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import Tabs from '@/Components/Tabs.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { guardSubmit } from '@/lib/ui.js';

const { ask } = useConfirm();

const props = defineProps({ settings: Object, users: { type: Array, default: () => [] }, roles: Object, counts: Object, specialties: Array, reasons: Array, settingHistory: Array, reportRecipients: { type: Array, default: () => [] }, system: { type: Object, default: () => ({}) }, timezones: { type: Array, default: () => [] } });

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
// Wave 3, Item 7: the Settings tab carries a dirty badge (a warning dot + "(unsaved changes)"
// aria-label, from the committed Tabs.vue) whenever the settings form has unsaved edits — Inertia's
// own useForm.isDirty, since sForm is never re-populated past its constructor defaults.
const controlTabs = computed(() => [
    { id: 'overview', label: 'Overview' },
    { id: 'settings', label: 'Settings', badge: !!sForm.isDirty },
    { id: 'users', label: 'Users' },
    { id: 'system', label: 'System' },
    { id: 'reference', label: 'Reference' },
]);

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
const saveSettings = guardSubmit(sForm, () => sForm.put('/control/settings', { preserveScroll: true }));

// Instant client-side user filtering over the FULL list (all users are shipped now, ~323 rows).
// Every control narrows in-memory — no server round-trip, no pages to click past. `search` matches
// name/username/email (case-insensitive); role/status/service/capability are exact.
const search = ref('');
const roleFilter = ref('');       // '' = any, else a role id (string, from the <select>)
const statusFilter = ref('');     // '' | 'active' | 'disabled'
const serviceFilter = ref('');    // '' | 'on' | 'off'
const capFilter = ref('');        // '' | 'assign' | 'add' | 'manage' | 'modify'
const anyUserFilter = computed(() => !!(search.value || roleFilter.value !== '' || statusFilter.value || serviceFilter.value || capFilter.value));
const clearUserFilters = () => { search.value = ''; roleFilter.value = ''; statusFilter.value = ''; serviceFilter.value = ''; capFilter.value = ''; };
const filteredUsers = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.users.filter((u) => {
        if (term && !`${u.full_name || ''} ${u.name || ''} ${u.username || ''} ${u.email || ''}`.toLowerCase().includes(term)) return false;
        if (roleFilter.value !== '' && u.role !== Number(roleFilter.value)) return false;
        if (statusFilter.value === 'active' && !u.active) return false;
        if (statusFilter.value === 'disabled' && u.active) return false;
        if (serviceFilter.value === 'on' && !u.on_service) return false;
        if (serviceFilter.value === 'off' && u.on_service) return false;
        if (capFilter.value && !u.can?.[capFilter.value]) return false;
        return true;
    });
});

// client-side sort on Full name / On service, applied over the filtered set — L1-15
const sort = ref({ key: null, dir: 1 });
const toggleSort = (key) => { sort.value = sort.value.key === key ? { key, dir: -sort.value.dir } : { key, dir: 1 }; };
const ariaSort = (key) => (sort.value.key === key ? (sort.value.dir === 1 ? 'ascending' : 'descending') : 'none');
const sortedUsers = computed(() => {
    const { key, dir } = sort.value;
    if (!key) return filteredUsers.value;
    return [...filteredUsers.value].sort((a, b) => dir * (key === 'full_name'
        ? String(a.full_name || a.name || '').localeCompare(String(b.full_name || b.name || ''), undefined, { sensitivity: 'base' })
        : (b.on_service ? 1 : 0) - (a.on_service ? 1 : 0)));   // ascending = on-service first
});

const editing = ref(null);
const uForm = useForm({ username: '', full_name: '', email: '', role: 5, active: true, on_service: false, specialty_id: '', can_assign: false, can_add: false, can_manage: false, can_modify: false });
// Wave 3, Item 1/2: unsaved-changes guard on the edit-user modal. defaults() re-anchors Inertia's
// own isDirty baseline to the JUST-LOADED user, same idea as Consultations' edit form.
const { guardedClose: guardedCloseEditUser } = useUnsavedGuard(() => uForm.isDirty, ask);
const doCloseEditUser = () => { editing.value = null; };
const closeEditUser = () => guardedCloseEditUser(doCloseEditUser);
const editUser = (u) => {
    editing.value = u;
    uForm.clearErrors();
    uForm.username = u.username || ''; uForm.full_name = u.full_name || ''; uForm.email = u.email || '';
    uForm.role = u.role; uForm.active = u.active; uForm.on_service = u.on_service; uForm.specialty_id = u.specialty_id || '';
    uForm.can_assign = u.can.assign; uForm.can_add = u.can.add; uForm.can_manage = u.can.manage; uForm.can_modify = u.can.modify;
    uForm.defaults?.();
};
const saveUser = guardSubmit(uForm, () => uForm.put(`/control/users/${editing.value.id}`, { preserveScroll: true, onSuccess: doCloseEditUser }));
const resetMfa = async (u) => { if (await ask('Reset two-factor', `Reset two-factor for ${u.username}. They'll re-enrol on next login.`, 'neutral')) router.post(`/control/users/${u.id}/reset-mfa`, {}, { preserveScroll: true }); };
const sendReset = (u) => router.post(`/control/users/${u.id}/send-reset`, {}, { preserveScroll: true });
const deleteUser = async (u) => {
    if (await ask('Delete user', `Permanently delete ${u.username}. Their historical admissions/consultations are kept (attribution cleared). This cannot be undone.`, 'danger'))
        router.delete(`/control/users/${u.id}`, { preserveScroll: true, onSuccess: doCloseEditUser });
};

// reference data
const specForm = useForm({ name: '', is_subspecialty: true, is_external: false });
const submitSpec = guardSubmit(specForm, () => specForm.post('/control/specialties', { preserveScroll: true, onSuccess: () => specForm.reset() }));
const reasonForm = useForm({ name: '' });
const submitReason = guardSubmit(reasonForm, () => reasonForm.post('/control/reasons', { preserveScroll: true, onSuccess: () => reasonForm.reset() }));

// §3.3: monthly-report email recipients
const recipientForm = useForm({ email: '' });
const submitRecipient = guardSubmit(recipientForm, () => recipientForm.post('/control/report-recipients', { preserveScroll: true, onSuccess: () => recipientForm.reset() }));
const removeRecipient = async (r) => { if (await ask('Remove recipient', `Stop sending the monthly report to ${r.email}?`, 'neutral')) router.delete(`/control/report-recipients/${r.id}`, { preserveScroll: true }); };

// System (runtime config). Password is write-only: '' means "keep the current one".
const sysForm = useForm({
    mail_mailer: props.system.mail_mailer ?? 'log',
    mail_host: props.system.mail_host ?? '', mail_port: props.system.mail_port ?? '',
    mail_encryption: props.system.mail_encryption ?? 'tls', mail_username: props.system.mail_username ?? '',
    mail_password: '',
    mail_from_address: props.system.mail_from_address ?? '', mail_from_name: props.system.mail_from_name ?? '',
    app_timezone: props.system.app_timezone ?? '', app_name: props.system.app_name ?? '', app_url: props.system.app_url ?? '',
});
const saveSystem = guardSubmit(sysForm, () => sysForm.put('/control/system', { preserveScroll: true }));
const sendTestEmail = () => router.post('/control/system/test-email', {}, { preserveScroll: true });

const countCards = [
    ['Users', 'users'], ['Active users', 'active_users'], ['Patients', 'patients'],
    ['Admissions', 'admissions'], ['Consultations', 'consultations'], ['ICD-10 codes', 'icd10'], ['Specialties', 'specialties'],
];
const field = 'w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
const roleTone = (r) => r === 0 ? 'bg-tint-danger text-on-danger' : r === 3 ? 'bg-brand-100 text-brand-700' : 'bg-ink-100 text-ink-500';
</script>

<template>
    <AppLayout title="Control Panel" :breadcrumbs="[
        { label: 'Administration' },
        { label: 'Settings' },
        { label: 'Control Panel' },
    ]">
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <!-- Phase 4 — Item 1: opens the soft-delete "Recently Deleted" view -->
            <button @click="router.visit('/trashed')"
                class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500 ring-1 ring-line transition hover:bg-ink-50">
                Recently Deleted
            </button>
        </div>

        <Tabs :tabs="controlTabs" v-model="tab">
        <template #default="{ active }">

        <!-- Overview -->
        <div v-show="active === 'overview'" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <div v-for="[label, key] in countCards" :key="key" class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-400">{{ label }}</div>
                <div class="nums mt-1 text-3xl font-bold text-brand-700">{{ counts[key].toLocaleString() }}</div>
            </div>
        </div>

        <!-- Settings -->
        <div v-show="active === 'settings'" class="max-w-2xl">
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
                <button @click="saveSettings" :disabled="sForm.processing" class="rounded-xl bg-brand-solid px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Save settings</button>
                <span v-if="sForm.recentlySuccessful" class="text-sm font-semibold text-on-success">Saved ✓</span>
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
        <div v-show="active === 'users'">
            <!-- instant filters — all client-side over every user (search + role/status/service/capability) -->
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <div class="relative">
                    <svg class="pointer-events-none absolute start-3 top-2.5 h-4.5 w-4.5 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" /></svg>
                    <input v-model="search" aria-label="Search users by name, username, or email" placeholder="Search name, username, email…" class="w-64 rounded-xl border border-ink-200 bg-card py-2 ps-9 pe-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
                </div>
                <select v-model="roleFilter" aria-label="Filter by role" class="rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
                    <option value="">All roles</option>
                    <option v-for="(label, id) in roles" :key="id" :value="id">{{ label }}</option>
                </select>
                <select v-model="statusFilter" aria-label="Filter by status" class="rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
                    <option value="">Any status</option><option value="active">Active</option><option value="disabled">Disabled</option>
                </select>
                <select v-model="serviceFilter" aria-label="Filter by on-service" class="rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
                    <option value="">Any service</option><option value="on">On service</option><option value="off">Off service</option>
                </select>
                <select v-model="capFilter" aria-label="Filter by capability" class="rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
                    <option value="">Any capability</option><option value="assign">Can assign</option><option value="add">Can add</option><option value="manage">Can manage</option><option value="modify">Can modify</option>
                </select>
                <button v-if="anyUserFilter" @click="clearUserFilters" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-500 ring-1 ring-line transition hover:bg-ink-50">Clear</button>
                <span class="ms-auto text-sm text-ink-400" aria-live="polite"><span class="nums font-semibold text-ink-600">{{ sortedUsers.length }}</span> of {{ users.length }} user(s)</span>
            </div>
            <div class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
                <!-- all users on one scrollable pane (no pagination) — the header stays put while you scroll -->
                <div class="max-h-[65vh] overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 z-10 bg-card"><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
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
                                <td class="px-3 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="u.active ? 'bg-tint-success text-on-success' : 'bg-ink-100 text-ink-400'">{{ u.active ? 'Active' : 'Disabled' }}</span></td>
                                <td class="px-5 py-3 text-right"><button @click="editUser(u)" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-brand-700 hover:bg-brand-50">Edit</button></td>
                            </tr>
                            <tr v-if="!sortedUsers.length"><td colspan="6" class="px-5 py-10 text-center text-ink-400">No users match these filters.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- System (runtime config) -->
        <div v-show="active === 'system'" class="grid gap-5 lg:grid-cols-2">
            <!-- Email -->
            <form @submit.prevent="saveSystem" class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line lg:col-span-2">
                <h3 class="mb-1 font-bold text-ink-800">Email</h3>
                <p class="mb-4 text-sm text-ink-400">SMTP delivery. Set <strong>Log</strong> to capture mail without sending. Blank the password to keep the current one.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Mailer</span>
                        <select v-model="sysForm.mail_mailer" aria-label="Mailer" :class="field"><option value="smtp">SMTP (send)</option><option value="log">Log (don't send)</option></select></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Host</span><input v-model="sysForm.mail_host" aria-label="SMTP host" :class="field" placeholder="smtp.example.com" /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Port</span><input v-model="sysForm.mail_port" type="number" min="1" max="65535" aria-label="SMTP port" :class="field" placeholder="587" /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Encryption</span>
                        <select v-model="sysForm.mail_encryption" aria-label="SMTP encryption" :class="field"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">None</option></select></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Username</span><input v-model="sysForm.mail_username" aria-label="SMTP username" :class="field" /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Password <span v-if="system.mail_password_set" class="ml-1 rounded-full bg-tint-success px-1.5 py-0.5 text-[10px] font-semibold text-on-success">Set</span></span>
                        <input v-model="sysForm.mail_password" type="password" autocomplete="new-password" aria-label="SMTP password" :class="field" :placeholder="system.mail_password_set ? 'Leave blank to keep current' : 'Not set'" /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">From address</span><input v-model="sysForm.mail_from_address" type="email" aria-label="From address" :class="field" placeholder="noreply@dmc-im.com" /></label>
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">From name</span><input v-model="sysForm.mail_from_name" aria-label="From name" :class="field" placeholder="DMC Internal Medicine" /></label>
                </div>
                <p v-if="sysForm.errors.mail_host || sysForm.errors.mail_from_address" class="mt-2 text-xs text-on-danger">{{ sysForm.errors.mail_host || sysForm.errors.mail_from_address }}</p>
                <div class="mt-4 flex items-center gap-2">
                    <button type="submit" :disabled="sysForm.processing" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Save system config</button>
                    <button type="button" @click="sendTestEmail" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-600 ring-1 ring-line transition hover:bg-ink-50">Send test email</button>
                </div>
            </form>

            <!-- Localization -->
            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h3 class="mb-1 font-bold text-ink-800">Localization</h3>
                <p class="mb-4 text-sm text-ink-400">The application timezone (dates + times across the app).</p>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Timezone</span>
                    <select v-model="sysForm.app_timezone" aria-label="Timezone" :class="field"><option value="">Use server default (.env)</option><option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option></select></label>
                <p v-if="sysForm.errors.app_timezone" class="mt-1 text-xs text-on-danger">{{ sysForm.errors.app_timezone }}</p>
            </div>

            <!-- Application -->
            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h3 class="mb-1 font-bold text-ink-800">Application</h3>
                <p class="mb-4 text-sm text-ink-400">Display name + the URL used in emails.</p>
                <label class="mb-3 block"><span class="mb-1 block text-sm font-semibold text-ink-700">Display name</span><input v-model="sysForm.app_name" aria-label="Display name" :class="field" placeholder="DMC Internal Medicine" /></label>
                <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">App URL</span><input v-model="sysForm.app_url" aria-label="App URL" :class="field" placeholder="https://dmc-im.com" /></label>
                <p v-if="sysForm.errors.app_url" class="mt-1 text-xs text-on-danger">{{ sysForm.errors.app_url }}</p>
            </div>
        </div>

        <!-- Reference data -->
        <div v-show="active === 'reference'" class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h3 class="mb-3 font-bold text-ink-800">Specialties</h3>
                <div class="mb-4 flex max-h-48 flex-wrap gap-2 overflow-auto"><span v-for="s in specialties" :key="s.id" class="rounded-full px-3 py-1 text-sm" :class="s.is_external ? 'bg-tint-accent text-on-accent' : 'bg-app text-ink-600'">{{ s.name }}<span v-if="s.is_external" class="ml-1 text-[10px] font-semibold uppercase">ext</span></span></div>
                <form @submit.prevent="submitSpec" class="flex gap-2"><input v-model="specForm.name" :class="field" placeholder="New specialty" /><button :disabled="specForm.processing || !specForm.name" class="rounded-xl bg-brand-solid px-4 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Add</button></form>
                <label class="mt-2 flex items-center gap-2 text-xs text-ink-500"><input type="checkbox" v-model="specForm.is_subspecialty" class="rounded text-brand-600" /> Subspecialty (uncheck for hospitalist)</label>
                <label class="mt-1 flex items-center gap-2 text-xs text-ink-500"><input type="checkbox" v-model="specForm.is_external" class="rounded text-brand-600" /> External / allied service (transfer-out target only — not an internal specialty)</label>
            </div>
            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h3 class="mb-3 font-bold text-ink-800">Consultation indications</h3>
                <div class="mb-4 flex max-h-48 flex-wrap gap-2 overflow-auto"><span v-for="r in reasons" :key="r.id" class="rounded-full bg-app px-3 py-1 text-sm text-ink-600">{{ r.name }}</span></div>
                <form @submit.prevent="submitReason" class="flex gap-2"><input v-model="reasonForm.name" :class="field" placeholder="New indication" /><button :disabled="reasonForm.processing || !reasonForm.name" class="rounded-xl bg-brand-solid px-4 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Add</button></form>
            </div>

            <!-- §3.3: monthly-report email recipients -->
            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line lg:col-span-2">
                <h3 class="mb-1 font-bold text-ink-800">Monthly report recipients</h3>
                <p class="mb-3 text-sm text-ink-400">Each address receives the prior month's activity booklet (PDF) automatically on the 1st of the month.</p>
                <ul v-if="reportRecipients.length" class="mb-4 divide-y divide-line rounded-xl ring-1 ring-line">
                    <li v-for="r in reportRecipients" :key="r.id" class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm">
                        <span class="min-w-0 flex-1 truncate text-ink-700">{{ r.email }}<span v-if="!r.active" class="ml-2 rounded-full bg-ink-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-ink-500">inactive</span></span>
                        <button @click="removeRecipient(r)" class="shrink-0 rounded-lg px-3 py-1 text-xs font-semibold text-on-danger hover:bg-tint-danger">Remove</button>
                    </li>
                </ul>
                <p v-else class="mb-4 text-sm text-ink-300">No recipients yet — the scheduled email will send to no one.</p>
                <form @submit.prevent="submitRecipient" class="flex gap-2">
                    <input v-model="recipientForm.email" type="email" :class="field" placeholder="name@dmc-im.com" />
                    <button :disabled="recipientForm.processing || !recipientForm.email" class="rounded-xl bg-brand-solid px-4 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Add</button>
                </form>
                <p v-if="recipientForm.errors.email" class="mt-1 text-xs text-on-danger">{{ recipientForm.errors.email }}</p>
            </div>
        </div>

        </template>
        </Tabs>

        <!-- edit user modal -->
        <BaseModal :open="!!editing" :title="editing ? editing.name : ''" :subtitle="editing ? editing.username : ''" size="md" :closable="false" :dirty="!!uForm.isDirty" @close="closeEditUser">
                <div class="space-y-4">
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Username</span>
                        <input v-model="uForm.username" :class="field" placeholder="login name" />
                        <span v-if="uForm.errors.username" class="mt-1 block text-xs text-on-danger">{{ uForm.errors.username }}</span>
                        <span class="mt-1 block text-xs text-ink-400">The login name — changing it changes what they type to sign in.</span>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Full name</span>
                            <input v-model="uForm.full_name" :class="field" placeholder="Dr …" />
                            <span v-if="uForm.errors.full_name" class="mt-1 block text-xs text-on-danger">{{ uForm.errors.full_name }}</span>
                        </label>
                        <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Email</span>
                            <input v-model="uForm.email" type="email" :class="field" placeholder="name@dmc-im.com" />
                            <span v-if="uForm.errors.email" class="mt-1 block text-xs text-on-danger">{{ uForm.errors.email }}</span>
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
                    <button v-if="editing.mfa" @click="resetMfa(editing)" class="rounded-xl px-3 py-2 text-sm font-semibold text-on-danger hover:bg-tint-danger">Reset MFA</button>
                    <button @click="deleteUser(editing)" class="mr-auto rounded-xl px-3 py-2 text-sm font-semibold text-on-danger hover:bg-tint-danger">Delete</button>
                    <button @click="closeEditUser" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                    <button @click="saveUser" :disabled="uForm.processing" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Save</button>
                </div>
        </BaseModal>
    </AppLayout>
</template>
