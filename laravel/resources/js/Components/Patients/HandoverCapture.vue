<script setup>
import { computed } from 'vue';
import { CHECKPOINT_FIELDS, CODE_STATUS_OPTIONS, withCheckpointDefaults } from '@/lib/handover.js';

/**
 * HandoverCapture — the ONE handover editor used at every point of care transfer.
 *
 *   density="compact" → bulk Reassign rows: tappable chips + inline code status + note.
 *                       Stays scannable when several patients are stale at once.
 *   density="full"    → single-patient Assign / specialty-transfer modal: labelled checkboxes,
 *                       code-status select, full note, collapsible revision history.
 *
 * Presentational + fully controlled: the host owns the state and passes body/checkpoints down,
 * receiving update:body / update:checkpoints back. It never saves — the host does.
 */
const props = defineProps({
    body: { type: String, default: '' },
    checkpoints: { type: Object, default: null },
    density: { type: String, default: 'full', validator: (v) => ['compact', 'full'].includes(v) },
    updatedAt: { type: String, default: null },
    today: { type: Boolean, default: false },
    revisions: { type: Array, default: () => [] },
    label: { type: String, default: '' },   // patient name — disambiguates aria-labels in stacked rows
    hideStatus: { type: Boolean, default: false },   // caller already shows this (e.g. a row pill + batch banner) — skip the redundant/stale-implying line
});
const emit = defineEmits(['update:body', 'update:checkpoints']);

const cp = computed(() => withCheckpointDefaults(props.checkpoints));
const fmtAt = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
const toggle = (key) => emit('update:checkpoints', { ...cp.value, [key]: !cp.value[key] });
const setCode = (v) => emit('update:checkpoints', { ...cp.value, code_status: v === '' ? null : v });
const aria = (suffix) => (props.label ? `${suffix} for ${props.label}` : suffix);
</script>

<template>
    <div>
        <p v-if="!hideStatus" class="mb-1 flex flex-wrap items-center gap-2 text-xs text-ink-400">
            <span v-if="updatedAt">Handover · last updated {{ fmtAt(updatedAt) }}</span>
            <span v-else>No handover recorded yet</span>
            <span v-if="!today" class="rounded-full bg-tint-warning px-2 py-0.5 text-[10px] font-semibold text-on-warning">stale</span>
        </p>

        <!-- compact: the chips ARE the control -->
        <div v-if="density === 'compact'" class="mb-2 flex flex-wrap items-center gap-1.5">
            <button v-for="f in CHECKPOINT_FIELDS" :key="f.key" type="button" data-cp-toggle
                    :aria-pressed="cp[f.key]" :aria-label="aria(f.label)" @click="toggle(f.key)"
                    class="rounded-full px-2.5 py-1 text-[11px] font-semibold transition"
                    :class="cp[f.key] ? 'bg-brand-100 text-brand-700' : 'border border-ink-200 text-ink-500 hover:bg-ink-50'">
                {{ cp[f.key] ? '✓ ' : '' }}{{ f.short }}
            </button>
            <label class="ml-1 flex items-center gap-1 text-[11px] text-ink-500">
                <span class="sr-only">{{ aria('Code status') }}</span>Code
                <select :value="cp.code_status ?? ''" @change="setCode($event.target.value)"
                        class="rounded-lg border border-ink-200 px-2 py-1 text-[11px] outline-none focus:border-brand-500">
                    <option v-for="o in CODE_STATUS_OPTIONS" :key="String(o.value)" :value="o.value ?? ''">{{ o.label }}</option>
                </select>
            </label>
        </div>

        <!-- full: labelled controls with room to breathe -->
        <div v-else class="mb-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm text-ink-600 sm:grid-cols-3">
            <label v-for="f in CHECKPOINT_FIELDS" :key="f.key" class="flex items-center gap-2">
                <input type="checkbox" class="rounded text-brand-600" :checked="cp[f.key]" @change="toggle(f.key)" />
                {{ f.label }}
            </label>
            <label class="flex items-center gap-2">Code status
                <select :value="cp.code_status ?? ''" @change="setCode($event.target.value)" :aria-label="aria('Code status')"
                        class="rounded-lg border border-ink-200 px-2 py-1 text-xs outline-none focus:border-brand-500">
                    <option v-for="o in CODE_STATUS_OPTIONS" :key="String(o.value)" :value="o.value ?? ''">{{ o.label }}</option>
                </select>
            </label>
        </div>

        <textarea :value="body" @input="emit('update:body', $event.target.value)"
                  :rows="density === 'compact' ? 2 : 6" maxlength="5000" :aria-label="aria('Handover text')"
                  placeholder="Write today's handover…"
                  class="w-full rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>

        <details v-if="density === 'full' && revisions.length" class="mt-2">
            <summary class="cursor-pointer text-xs font-semibold text-brand-600">History ({{ revisions.length }})</summary>
            <ul class="mt-1 space-y-1">
                <li v-for="(r, i) in revisions" :key="i" class="rounded-lg bg-app/70 px-2 py-1 text-xs text-ink-600">
                    <span class="font-semibold">{{ r.author || '—' }}</span> · {{ fmtAt(r.at) }}
                    <p class="whitespace-pre-wrap">{{ r.body }}</p>
                </li>
            </ul>
        </details>
    </div>
</template>
