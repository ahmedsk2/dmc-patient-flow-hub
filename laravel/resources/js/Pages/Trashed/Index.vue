<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from '@/composables/useConfirm';

/**
 * Phase 4 — Item 1: admin "Recently Deleted" view. Three sections (admissions / consultations /
 * users) of soft-deleted rows with a Restore action each. Restore re-points through the admin-only
 * trashed restore endpoints (an admission restore is rejected server-side if it would create a
 * duplicate active MRN).
 */
const props = defineProps({
    admissions: { type: Array, default: () => [] },
    consultations: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});

const { ask } = useConfirm();

const restore = async (kind, id, label) => {
    if (await ask('Restore record', `Restore ${label}? It will reappear in the relevant lists.`, 'neutral')) {
        router.post(`/trashed/${kind}/${id}/restore`, {}, { preserveScroll: true });
    }
};

const when = (iso) => (iso ? iso.slice(0, 16).replace('T', ' ') : '—');

const card = 'overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line';
const th = 'px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-400';
const td = 'px-5 py-3 text-sm text-ink-700';
const btn = 'rounded-lg bg-brand-solid px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-solid-hover';
</script>

<template>
    <AppLayout title="Recently Deleted" :breadcrumbs="[
        { label: 'Administration' },
        { label: 'Governance & Safety' },
        { label: 'Recently Deleted' },
    ]">
        <p class="mb-5 max-w-2xl text-sm text-ink-400">
            Soft-deleted records are hidden from the app but kept in the database. Restore a record to bring it
            back. There is no automatic purge — deleted data is retained until a retention policy is set.
        </p>

        <!-- Admissions -->
        <section class="mb-6">
            <h2 class="mb-2 font-bold text-ink-800">Admissions <span class="nums text-ink-400">({{ admissions.length }})</span></h2>
            <div :class="card">
                <table class="w-full">
                    <thead><tr class="border-b border-line">
                        <th :class="th" scope="col">MRN</th><th :class="th" scope="col">Patient</th>
                        <th :class="th" scope="col">Admitted</th><th :class="th" scope="col">Discharged</th>
                        <th :class="th" scope="col">Deleted</th><th :class="th" scope="col"><span class="sr-only">Action</span></th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="a in admissions" :key="a.id">
                            <td :class="[td, 'nums']">{{ a.mrn }}</td><td :class="td">{{ a.name }}</td>
                            <td :class="[td, 'nums']">{{ a.admit_date || '—' }}</td>
                            <td :class="[td, 'nums']">{{ a.discharge_date || 'Active' }}</td>
                            <td :class="[td, 'nums text-ink-400']">{{ when(a.deleted_at) }}</td>
                            <td :class="td"><button :class="btn" @click="restore('admissions', a.id, `admission for ${a.name}`)">Restore</button></td>
                        </tr>
                        <tr v-if="!admissions.length"><td :class="[td, 'text-ink-300']" colspan="6">No deleted admissions.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Consultations -->
        <section class="mb-6">
            <h2 class="mb-2 font-bold text-ink-800">Consultations <span class="nums text-ink-400">({{ consultations.length }})</span></h2>
            <div :class="card">
                <table class="w-full">
                    <thead><tr class="border-b border-line">
                        <th :class="th" scope="col">MRN</th><th :class="th" scope="col">Patient</th>
                        <th :class="th" scope="col">Date</th><th :class="th" scope="col">Deleted</th>
                        <th :class="th" scope="col"><span class="sr-only">Action</span></th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="c in consultations" :key="c.id">
                            <td :class="[td, 'nums']">{{ c.mrn }}</td><td :class="td">{{ c.name }}</td>
                            <td :class="[td, 'nums']">{{ c.date || '—' }}</td>
                            <td :class="[td, 'nums text-ink-400']">{{ when(c.deleted_at) }}</td>
                            <td :class="td"><button :class="btn" @click="restore('consultations', c.id, `consultation for ${c.name}`)">Restore</button></td>
                        </tr>
                        <tr v-if="!consultations.length"><td :class="[td, 'text-ink-300']" colspan="5">No deleted consultations.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Users -->
        <section>
            <h2 class="mb-2 font-bold text-ink-800">Users <span class="nums text-ink-400">({{ users.length }})</span></h2>
            <div :class="card">
                <table class="w-full">
                    <thead><tr class="border-b border-line">
                        <th :class="th" scope="col">Username</th><th :class="th" scope="col">Name</th>
                        <th :class="th" scope="col">Role</th><th :class="th" scope="col">Deleted</th>
                        <th :class="th" scope="col"><span class="sr-only">Action</span></th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="u in users" :key="u.id">
                            <td :class="td">{{ u.username }}</td><td :class="td">{{ u.name }}</td>
                            <td :class="td">{{ u.role_label }}</td>
                            <td :class="[td, 'nums text-ink-400']">{{ when(u.deleted_at) }}</td>
                            <td :class="td"><button :class="btn" @click="restore('users', u.id, u.username)">Restore</button></td>
                        </tr>
                        <tr v-if="!users.length"><td :class="[td, 'text-ink-300']" colspan="5">No deleted users.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
