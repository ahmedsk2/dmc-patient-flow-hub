<script setup>
import { reactive, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Phase 2 — Item 1: admin audit-log viewer. Paginated + filterable; details JSON shown in a
 * collapsible mono panel. Item 5 surfaces the hash-chain "integrity verified through {date}" badge.
 */
const props = defineProps({
    logs: Object,
    filters: Object,
    actors: { type: Array, default: () => [] },
    entityTypes: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    integrityThrough: { type: String, default: null },
});

const f = reactive({
    actor_id: '', action: '', entity_type: '', entity_id: '', category: '', from: '', to: '', ip: '',
    ...props.filters,
});

const apply = () => router.get('/audit', { ...f }, { preserveState: true, preserveScroll: true });
const reset = () => router.get('/audit', {}, { preserveState: false });

// querystring for the export links — same applied filters, drop empties
const qs = computed(() => new URLSearchParams(
    Object.entries(f).flatMap(([k, v]) => (v === '' || v === null ? [] : [[k, v]]))
).toString());

const fld = 'w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';

// action badge colour by category
const catTone = (cat) => ({
    phi_read: 'bg-tint-warning text-on-warning',
    admission: 'bg-brand-100 text-brand-700',
    consultation: 'bg-tint-info text-on-info',
    patient: 'bg-tint-accent text-on-accent',
    user: 'bg-tint-danger text-on-danger',
    settings: 'bg-ink-100 text-ink-500',
}[cat] || 'bg-ink-100 text-ink-500');

const catLabel = (cat) => cat === 'phi_read' ? 'PHI read' : cat.charAt(0).toUpperCase() + cat.slice(1);

const when = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
};
const integrityDate = computed(() => props.integrityThrough
    ? new Date(props.integrityThrough).toLocaleString(undefined, { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' })
    : null);

// entity link when it points at a navigable record
const entityHref = (row) => {
    if (row.entity_type === 'admission' && row.entity_id) return `/registry?mode=admissions&entity=${row.entity_id}`;
    if (row.entity_type === 'consultation' && row.entity_id) return `/registry?mode=consultations`;
    return null;
};

const expanded = reactive(new Set());
const toggle = (id) => { expanded.has(id) ? expanded.delete(id) : expanded.add(id); };
const pretty = (details) => JSON.stringify(details ?? {}, null, 2);
</script>

<template>
    <AppLayout title="Audit Log" :breadcrumbs="[
        { label: 'Administration' },
        { label: 'Governance & Safety' },
        { label: 'Audit Log' },
    ]">
        <!-- integrity badge (Item 5) -->
        <div class="mb-4 flex items-center gap-2 text-sm">
            <svg class="h-4 w-4" :class="integrityThrough ? 'text-brand-600' : 'text-ink-400'" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
            <span v-if="integrityThrough" class="font-semibold text-brand-600">Hash chain intact through {{ integrityDate }}</span>
            <span v-else class="text-ink-400">No integrity data yet</span>
        </div>

        <!-- filters -->
        <div class="mb-4 rounded-2xl bg-card p-4 shadow-card ring-1 ring-line">
            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <select v-model="f.actor_id" :class="fld" aria-label="Actor">
                    <option value="">Any actor</option>
                    <option v-for="a in actors" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
                <select v-model="f.category" :class="fld" aria-label="Category">
                    <option value="">Any category</option>
                    <option v-for="c in categories" :key="c" :value="c">{{ catLabel(c) }}</option>
                </select>
                <input v-model="f.action" @keyup.enter="apply" :class="fld" placeholder="Action (e.g. user.update)" />
                <select v-model="f.entity_type" :class="fld" aria-label="Entity type">
                    <option value="">Any entity type</option>
                    <option v-for="t in entityTypes" :key="t" :value="t">{{ t }}</option>
                </select>
                <input v-model="f.entity_id" @keyup.enter="apply" :class="fld" placeholder="Entity ID" inputmode="numeric" />
                <input v-model="f.ip" @keyup.enter="apply" :class="fld" placeholder="IP address" />
                <div><label class="text-xs text-ink-400">From</label><input v-model="f.from" type="date" :class="fld" /></div>
                <div><label class="text-xs text-ink-400">To</label><input v-model="f.to" type="date" :class="fld" /></div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button @click="apply" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover">Apply</button>
                <button @click="reset" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-500 hover:text-ink-700">Reset</button>
                <a :href="`/audit/export-xlsx?${qs}`" class="ml-auto rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">Excel</a>
                <a :href="`/audit/export?${qs}`" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">CSV</a>
            </div>
        </div>

        <div class="mb-2 text-sm text-ink-400"><span class="nums font-semibold text-ink-600">{{ logs.total }}</span> event(s)</div>

        <!-- table -->
        <div class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                    <th scope="col" class="px-5 py-3">Date/Time</th>
                    <th scope="col" class="px-3 py-3">Actor</th>
                    <th scope="col" class="px-3 py-3">Action</th>
                    <th scope="col" class="px-3 py-3">Entity</th>
                    <th scope="col" class="px-3 py-3">Details</th>
                    <th scope="col" class="px-5 py-3">IP</th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    <template v-for="row in logs.data" :key="row.id">
                        <tr class="hover:bg-brand-50/40">
                            <td class="nums px-5 py-3 text-ink-600">{{ when(row.created_at) }}</td>
                            <td class="px-3 py-3 text-ink-700">{{ row.actor_name || '—' }}</td>
                            <td class="px-3 py-3">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="catTone(row.category)">{{ row.action }}</span>
                            </td>
                            <td class="px-3 py-3 text-ink-600">
                                <template v-if="row.entity_type">
                                    <component :is="entityHref(row) ? Link : 'span'" :href="entityHref(row) || undefined" :class="entityHref(row) ? 'text-brand-700 hover:underline' : ''">
                                        {{ row.entity_type }}<span v-if="row.entity_id" class="nums"> #{{ row.entity_id }}</span>
                                    </component>
                                </template>
                                <span v-else class="text-ink-300">—</span>
                            </td>
                            <td class="px-3 py-3">
                                <button v-if="row.details && Object.keys(row.details).length"
                                    @click="toggle(row.id)" :aria-expanded="expanded.has(row.id) ? 'true' : 'false'"
                                    class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-700 ring-1 ring-line hover:bg-brand-50">
                                    {{ expanded.has(row.id) ? 'Hide' : 'View' }}
                                </button>
                                <span v-else class="text-ink-300">—</span>
                            </td>
                            <td class="nums px-5 py-3 text-ink-500">{{ row.ip || '—' }}</td>
                        </tr>
                        <tr v-if="expanded.has(row.id)" class="bg-app/60">
                            <td colspan="6" class="px-5 py-3">
                                <pre class="overflow-auto rounded-lg bg-ink-50 p-3 font-mono text-xs text-ink-700 dark:bg-ink-900 dark:text-ink-200">{{ pretty(row.details) }}</pre>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!logs.data.length"><td colspan="6" class="px-5 py-10 text-center text-ink-400">No audit events match.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="logs.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-ink-500">
            <span class="nums">Showing {{ logs.from }}–{{ logs.to }} of {{ logs.total }}</span>
            <div class="flex gap-1"><component :is="l.url ? Link : 'span'" v-for="l in logs.links" :key="l.label" :href="l.url || undefined" preserve-scroll class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold transition" :class="l.active ? 'bg-brand-solid text-white' : (l.url ? 'bg-card text-ink-600 ring-1 ring-line hover:bg-ink-50' : 'text-ink-300')" v-html="l.label" /></div>
        </div>
    </AppLayout>
</template>
