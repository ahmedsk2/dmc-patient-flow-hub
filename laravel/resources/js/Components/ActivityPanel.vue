<script setup>
import { computed } from 'vue';

/**
 * Phase 2 — Item 2: per-admission activity timeline (the audit trail for one patient). Pure
 * presentational — fed the `activity` array from /admissions/{id}/edit. Item 4 renders field-level
 * diffs ({from,to}) and diagnosis added/removed sets readably.
 */
const props = defineProps({ items: { type: Array, default: () => [] } });

// short human labels for every action emitted by the action controllers (fallback = raw action)
const ACTION_LABELS = {
    'admission.create': 'Admitted',
    'admission.assign': 'Consultant assigned',
    'admission.assign_to_me': 'Self-assigned',
    'admission.bulk_reassign': 'Bulk reassigned',
    'admission.shuffle': 'Shuffle assigned',
    'admission.bed': 'Bed changed',
    'admission.longterm': 'Long-term toggled',
    'admission.medical_discharge': 'Medically discharged',
    'admission.discharge_both': 'Discharged (file closed)',
    'admission.complete_discharge': 'Discharge completed',
    'admission.icu_discharge': 'ICU discharge',
    'admission.transfer': 'Transferred (ward↔ICU)',
    'admission.transfer_specialty': 'Transferred to specialty',
    'admission.transfer_external': 'Transferred out',
    'admission.icu_pull': 'Admitted from ICU',
    'admission.reverse_discharge': 'Discharge reversed',
    'admission.undo_medical_discharge': 'Medical discharge undone',
    'admission.delete': 'Admission deleted',
    'patient.modify': 'Details modified',
    'patient.repoint': 'Re-pointed to patient',
    'registry.open': 'Record opened (PHI read)',
    'consultation.create': 'Consultation created',
    'consultation.signoff': 'Consultation signed off',
    'consultation.reverse_signoff': 'Sign-off reversed',
    'consultation.modify': 'Consultation modified',
    'consultation.delete': 'Consultation deleted',
    'user.update': 'User updated',
};

const label = (action) => ACTION_LABELS[action] || action;

// destructive (red) vs PHI-read (amber) vs default action tone on the timeline dot/label
const tone = (action) => {
    if (/\.(delete|reverse|reverse_|undo_)/.test(action)) return 'text-on-danger';
    if (action.startsWith('registry.')) return 'text-on-warning';
    return 'text-ink-700';
};
const dotTone = (action) => {
    if (/\.(delete|reverse|reverse_|undo_)/.test(action)) return 'bg-danger-500';
    if (action.startsWith('registry.')) return 'bg-warning-500';
    return 'bg-brand-500';
};

// "DD Mon HH:MM" local
const when = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
};

// a details object → list of {key, kind, ...} entries for rendering
// kind: 'diff' ({from,to}) | 'diagnoses' ({added,removed}) | 'plain' (scalar)
const entries = (details) => {
    if (!details || typeof details !== 'object') return [];
    return Object.entries(details).map(([key, value]) => {
        if (value && typeof value === 'object' && 'from' in value && 'to' in value) {
            return { key, kind: 'diff', from: fmt(value.from), to: fmt(value.to) };
        }
        if (key === 'diagnoses' && value && typeof value === 'object' && ('added' in value || 'removed' in value)) {
            return { key, kind: 'diagnoses', added: value.added || [], removed: value.removed || [] };
        }
        return { key, kind: 'plain', value: fmt(value) };
    });
};
const fmt = (v) => {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'object') return JSON.stringify(v);
    if (typeof v === 'boolean') return v ? 'yes' : 'no';
    return String(v);
};
const prettyKey = (k) => k.replace(/_/g, ' ');

const hasItems = computed(() => props.items && props.items.length > 0);
</script>

<template>
    <div class="rounded-xl bg-card p-4 ring-1 ring-line">
        <div v-if="!hasItems" class="text-sm text-ink-400">No recorded activity for this admission.</div>
        <ol v-else class="border-l-2 border-brand-200 dark:border-brand-800 pl-4 space-y-3">
            <li v-for="it in items" :key="it.id" class="relative">
                <span class="absolute -left-[1.30rem] top-1.5 h-2 w-2 rounded-full" :class="dotTone(it.action)" aria-hidden="true"></span>
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <span class="text-sm font-semibold" :class="tone(it.action)">{{ label(it.action) }}</span>
                    <span class="text-xs text-ink-500">{{ it.actor || 'System' }}</span>
                    <span class="nums ml-auto text-xs text-ink-400">{{ when(it.at) }}</span>
                </div>
                <details v-if="entries(it.details).length" class="mt-1">
                    <summary class="cursor-pointer select-none text-xs text-brand-600 hover:underline">details</summary>
                    <dl class="mt-1 grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 text-xs">
                        <template v-for="e in entries(it.details)" :key="e.key">
                            <dt class="font-semibold capitalize text-ink-500">{{ prettyKey(e.key) }}</dt>
                            <dd v-if="e.kind === 'diff'" class="text-ink-700">
                                <span class="text-on-danger line-through">{{ e.from }}</span>
                                <span class="mx-1 text-ink-400">→</span>
                                <span class="font-semibold text-brand-600">{{ e.to }}</span>
                            </dd>
                            <dd v-else-if="e.kind === 'diagnoses'" class="space-x-1">
                                <span v-for="c in e.added" :key="'a' + c" class="nums inline-block rounded bg-tint-success px-1.5 py-0.5 font-semibold text-on-success">+{{ c }}</span>
                                <span v-for="c in e.removed" :key="'r' + c" class="nums inline-block rounded bg-tint-danger px-1.5 py-0.5 font-semibold text-on-danger line-through">{{ c }}</span>
                            </dd>
                            <dd v-else class="text-ink-700">{{ e.value }}</dd>
                        </template>
                    </dl>
                </details>
            </li>
        </ol>
    </div>
</template>
