<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Phase 4 — Item 3: read-only Security panel. Three tables built from the audit log + users —
 * failed-login clusters, first-seen IPs, and MFA-noncompliant users (while enforcement is on).
 * Account lockdown is done from the Control Panel (set the user inactive).
 */
defineProps({
    failedClusters: { type: Array, default: () => [] },
    firstSeenIps: { type: Array, default: () => [] },
    mfaNonCompliant: { type: Array, default: () => [] },
    mfaEnforcement: { type: Number, default: 0 },
    notifyThreshold: { type: Number, default: 0 },
});

const when = (iso) => (iso ? String(iso).slice(0, 16).replace('T', ' ') : '—');
const enforcementLabel = (l) => ({ 0: 'Optional', 1: 'Required for admins', 2: 'Required for everyone' }[l] || 'Optional');

const card = 'overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line';
const th = 'px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-400';
const td = 'px-5 py-3 text-sm text-ink-700';
</script>

<template>
    <AppLayout title="Security" :breadcrumbs="[
        { label: 'Administration' },
        { label: 'Governance & Safety' },
        { label: 'Security' },
    ]">
        <p class="mb-5 max-w-2xl text-sm text-ink-400">
            Login-anomaly surfacing from the audit log. This panel is read-only — to lock an account, set it
            inactive in the Control Panel. Failed-login alerts notify admins at
            <span class="nums font-semibold text-ink-700">{{ notifyThreshold || 'off' }}</span>
            consecutive failures in 10 minutes.
        </p>

        <!-- Failed-login clusters -->
        <section class="mb-6">
            <h2 class="mb-2 font-bold text-ink-800">Failed logins (last 24h) <span class="nums text-ink-400">({{ failedClusters.length }})</span></h2>
            <div :class="card">
                <table class="w-full">
                    <thead><tr class="border-b border-line">
                        <th :class="th" scope="col">Account</th><th :class="th" scope="col">IP</th>
                        <th :class="th" scope="col">Attempts</th><th :class="th" scope="col">Last seen</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="(c, i) in failedClusters" :key="i">
                            <td :class="td">{{ c.actor_name || '—' }}</td>
                            <td :class="[td, 'nums']">{{ c.ip || '—' }}</td>
                            <td :class="td"><span class="nums rounded-full px-2 py-0.5 text-xs font-bold" :class="c.attempts >= 5 ? 'bg-danger-100 text-danger-600' : 'bg-warning-100 text-warning-500'">{{ c.attempts }}</span></td>
                            <td :class="[td, 'nums text-ink-400']">{{ when(c.last_at) }}</td>
                        </tr>
                        <tr v-if="!failedClusters.length"><td :class="[td, 'text-ink-300']" colspan="4">No failed logins in the last 24 hours.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- First-seen IPs -->
        <section class="mb-6">
            <h2 class="mb-2 font-bold text-ink-800">First-seen IPs <span class="nums text-ink-400">({{ firstSeenIps.length }})</span></h2>
            <div :class="card">
                <table class="w-full">
                    <thead><tr class="border-b border-line">
                        <th :class="th" scope="col">Account</th><th :class="th" scope="col">IP</th>
                        <th :class="th" scope="col">Event</th><th :class="th" scope="col">First seen</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="(r, i) in firstSeenIps" :key="i">
                            <td :class="td">{{ r.actor_name || '—' }}</td>
                            <td :class="[td, 'nums']">{{ r.ip || '—' }}</td>
                            <td :class="td"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="r.action === 'login.success' ? 'bg-success-100 text-success-600' : 'bg-warning-100 text-warning-500'">{{ r.action }}</span></td>
                            <td :class="[td, 'nums text-ink-400']">{{ when(r.first_at) }}</td>
                        </tr>
                        <tr v-if="!firstSeenIps.length"><td :class="[td, 'text-ink-300']" colspan="4">No first-seen IPs recorded yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- MFA non-compliant -->
        <section>
            <h2 class="mb-2 font-bold text-ink-800">
                MFA non-compliant <span class="nums text-ink-400">({{ mfaNonCompliant.length }})</span>
                <span class="ml-2 text-xs font-normal text-ink-400">enforcement: {{ enforcementLabel(mfaEnforcement) }}</span>
            </h2>
            <div :class="card">
                <table class="w-full">
                    <thead><tr class="border-b border-line">
                        <th :class="th" scope="col">Username</th><th :class="th" scope="col">Name</th><th :class="th" scope="col">Role</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="u in mfaNonCompliant" :key="u.id">
                            <td :class="td">{{ u.username }}</td><td :class="td">{{ u.name }}</td><td :class="td">{{ u.role_label }}</td>
                        </tr>
                        <tr v-if="!mfaNonCompliant.length"><td :class="[td, 'text-ink-300']" colspan="3">{{ mfaEnforcement > 0 ? 'All in-scope users are enrolled.' : 'MFA enforcement is off — nothing to flag.' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
