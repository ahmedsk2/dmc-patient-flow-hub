<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ settings: Object, users: Object, filters: Object, roles: Object, counts: Object, specialties: Array, reasons: Array, settingHistory: Array });

const fieldLabels = {
    min_hospitalist: 'Min hospitalist census', max_hospitalist: 'Max hospitalist census',
    min_subs: 'Min subspecialty', max_subs: 'Max subspecialty',
    short_los: 'Short LOS', long_los: 'Long LOS',
    ward_beds: 'Licensed ward beds', icu_beds: 'Licensed ICU beds', mfa_enforcement: 'MFA enforcement',
};

const tab = ref('overview');

const sForm = useForm({
    min_hospitalist: props.settings.min_hospitalist, max_hospitalist: props.settings.max_hospitalist,
    min_subs: props.settings.min_subs, max_subs: props.settings.max_subs,
    short_los: props.settings.short_los, long_los: props.settings.long_los,
    ward_beds: props.settings.ward_beds ?? 50, icu_beds: props.settings.icu_beds ?? 10,
    mfa_enforcement: props.settings.mfa_enforcement ?? 0,
});
const saveSettings = () => sForm.put('/control/settings', { preserveScroll: true });

const q = ref(props.filters.q || '');
let timer = null;
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => router.get('/control', { q: q.value || undefined }, { preserveState: true, replace: true, preserveScroll: true }), 300); });

const editing = ref(null);
const uForm = useForm({ role: 5, active: true, on_service: false, specialty_id: '', can_assign: false, can_add: false, can_manage: false, can_modify: false });
const editUser = (u) => {
    editing.value = u;
    uForm.role = u.role; uForm.active = u.active; uForm.on_service = u.on_service; uForm.specialty_id = u.specialty_id || '';
    uForm.can_assign = u.can.assign; uForm.can_add = u.can.add; uForm.can_manage = u.can.manage; uForm.can_modify = u.can.modify;
};
const saveUser = () => uForm.put(`/control/users/${editing.value.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) });
const resetMfa = (u) => { if (confirm(`Reset two-factor for ${u.username}? They'll re-enrol on next login.`)) router.post(`/control/users/${u.id}/reset-mfa`, {}, { preserveScroll: true }); };
const sendReset = (u) => router.post(`/control/users/${u.id}/send-reset`, {}, { preserveScroll: true });

// reference data
const specForm = useForm({ name: '', is_subspecialty: true });
const submitSpec = () => specForm.post('/control/specialties', { preserveScroll: true, onSuccess: () => specForm.reset() });
const reasonForm = useForm({ name: '' });
const submitReason = () => reasonForm.post('/control/reasons', { preserveScroll: true, onSuccess: () => reasonForm.reset() });

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
        <div class="mb-5 flex gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-ink-100 w-fit">
            <button v-for="t in ['overview','settings','users','reference']" :key="t" @click="tab = t"
                class="rounded-lg px-4 py-2 text-sm font-semibold capitalize transition" :class="tab === t ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ t }}</button>
        </div>

        <!-- Overview -->
        <div v-show="tab === 'overview'" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <div v-for="[label, key] in countCards" :key="key" class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-400">{{ label }}</div>
                <div class="nums mt-1 text-3xl font-bold text-brand-700">{{ counts[key].toLocaleString() }}</div>
            </div>
        </div>

        <!-- Settings -->
        <div v-show="tab === 'settings'" class="max-w-2xl">
        <div class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
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
                <label class="block sm:col-span-2"><span class="mb-1 block text-sm font-semibold text-ink-700">Two-factor enforcement</span>
                    <select v-model.number="sForm.mfa_enforcement" :class="field">
                        <option :value="0">Optional (users opt in)</option>
                        <option :value="1">Required for administrators</option>
                        <option :value="2">Required for everyone</option>
                    </select>
                </label>
            </div>
            <div class="mt-5 flex items-center gap-3">
                <button @click="saveSettings" :disabled="sForm.processing" class="rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save settings</button>
                <span v-if="sForm.recentlySuccessful" class="text-sm font-semibold text-success-600">Saved ✓</span>
            </div>
        </div>

        <!-- change history (append-only; tracks e.g. ward capacity over time) -->
        <div class="mt-5 rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
            <h3 class="mb-1 font-bold text-ink-800">Change history</h3>
            <p class="mb-4 text-sm text-ink-400">Every settings change is recorded — who changed what, from what, to what, and when.</p>
            <div v-if="settingHistory.length" class="divide-y divide-ink-50">
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
            <div class="overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th class="px-5 py-3">User</th><th class="px-3 py-3">Role</th><th class="px-3 py-3">Capabilities</th><th class="px-3 py-3">Status</th><th class="px-5 py-3 text-right">Edit</th>
                    </tr></thead>
                    <tbody class="divide-y divide-ink-50">
                        <tr v-for="u in users.data" :key="u.id" class="hover:bg-brand-50/40">
                            <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ u.name }}</div><div class="text-xs text-ink-400">{{ u.username }} · {{ u.email || '—' }}</div></td>
                            <td class="px-3 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="roleTone(u.role)">{{ u.role_label }}</span></td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="(v, k) in u.can" :key="k" v-show="v" class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold capitalize text-brand-700">{{ k }}</span>
                                    <span v-if="!Object.values(u.can).some(Boolean)" class="text-xs text-ink-300">—</span>
                                </div>
                            </td>
                            <td class="px-3 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="u.active ? 'bg-success-100 text-success-600' : 'bg-ink-100 text-ink-400'">{{ u.active ? 'Active' : 'Disabled' }}</span></td>
                            <td class="px-5 py-3 text-right"><button @click="editUser(u)" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-brand-700 hover:bg-brand-50">Edit</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="users.last_page > 1" class="mt-4 flex justify-end gap-1">
                <component :is="l.url ? Link : 'span'" v-for="l in users.links" :key="l.label" :href="l.url || undefined" preserve-scroll
                    class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold" :class="l.active ? 'bg-brand-600 text-white' : (l.url ? 'bg-white text-ink-600 ring-1 ring-ink-100 hover:bg-ink-50' : 'text-ink-300')" v-html="l.label" />
            </div>
        </div>

        <!-- Reference data -->
        <div v-show="tab === 'reference'" class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-3 font-bold text-ink-800">Specialties</h3>
                <div class="mb-4 flex max-h-48 flex-wrap gap-2 overflow-auto"><span v-for="s in specialties" :key="s.id" class="rounded-full bg-surface px-3 py-1 text-sm text-ink-600">{{ s.name }}</span></div>
                <form @submit.prevent="submitSpec" class="flex gap-2"><input v-model="specForm.name" :class="field" placeholder="New specialty" /><button :disabled="specForm.processing || !specForm.name" class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Add</button></form>
                <label class="mt-2 flex items-center gap-2 text-xs text-ink-500"><input type="checkbox" v-model="specForm.is_subspecialty" class="rounded text-brand-600" /> Subspecialty (uncheck for hospitalist)</label>
            </div>
            <div class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-3 font-bold text-ink-800">Consultation indications</h3>
                <div class="mb-4 flex max-h-48 flex-wrap gap-2 overflow-auto"><span v-for="r in reasons" :key="r.id" class="rounded-full bg-surface px-3 py-1 text-sm text-ink-600">{{ r.name }}</span></div>
                <form @submit.prevent="submitReason" class="flex gap-2"><input v-model="reasonForm.name" :class="field" placeholder="New indication" /><button :disabled="reasonForm.processing || !reasonForm.name" class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Add</button></form>
            </div>
        </div>

        <!-- edit user modal -->
        <div v-if="editing" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="editing = null">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-ink-900">{{ editing.name }}</h3>
                <p class="mb-4 text-sm text-ink-400">{{ editing.username }}</p>
                <div class="space-y-4">
                    <label class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Role</span>
                        <select v-model.number="uForm.role" :class="field"><option v-for="(label, id) in roles" :key="id" :value="Number(id)">{{ label }}</option></select>
                    </label>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm font-medium text-ink-700"><input type="checkbox" v-model="uForm.active" class="rounded text-brand-600" /> Active</label>
                        <label class="flex items-center gap-2 text-sm font-medium text-ink-700"><input type="checkbox" v-model="uForm.on_service" class="rounded text-brand-600" /> On service</label>
                    </div>
                    <label v-if="uForm.role === 3" class="block"><span class="mb-1 block text-sm font-semibold text-ink-700">Specialty</span>
                        <select v-model="uForm.specialty_id" :class="field"><option value="">—</option><option v-for="s in specialties" :key="s.id" :value="s.id">{{ s.name }}</option></select>
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
                    <button v-if="editing.mfa" @click="resetMfa(editing)" class="mr-auto rounded-xl px-3 py-2 text-sm font-semibold text-danger-600 hover:bg-danger-100">Reset MFA</button>
                    <span v-if="!editing.mfa" class="mr-auto"></span>
                    <button @click="editing = null" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                    <button @click="saveUser" :disabled="uForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
