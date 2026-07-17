<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from '@/composables/useConfirm';

const { ask } = useConfirm();

const props = defineProps({ awaiting: Array, outgoing: Array });

const tab = ref('awaiting');

// relative time for required_at / signed_at (ISO strings from the server)
const relTime = (iso) => {
    if (!iso) return '—';
    const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    if (mins < 60 * 24) return `${Math.round(mins / 60)}h ago`;
    return `${Math.round(mins / (60 * 24))}d ago`;
};
const fmt = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');

// expandable rows (awaiting tab) — click reveals the full handover body
const open = ref(null);
const toggle = (id) => (open.value = open.value === id ? null : id);

const sign = (s) => router.post(`/handovers/${s.id}/sign`, {}, { preserveScroll: true });
const signAll = async () => {
    if (!props.awaiting.length) return;
    if (await ask('Sign all handovers', `Sign all ${props.awaiting.length} pending handover(s). Make sure you have reviewed each one.`, 'neutral'))
        router.post('/handovers/sign-many', { ids: props.awaiting.map((s) => s.id) }, { preserveScroll: true });
};

// outgoing tab — inline editor per row; the receiver sees the latest text at sign time
const editing = ref(null);
const eForm = useForm({ body: '' });
const startEdit = (s) => { editing.value = s.id; eForm.body = s.body || ''; eForm.clearErrors(); };
const saveEdit = (s) => eForm.post(`/admissions/${s.admission_id}/handover`, {
    preserveScroll: true, preserveState: true, onSuccess: () => (editing.value = null),
});

const stateOf = (s) => (s.signed_at ? 'signed' : s.voided_at ? 'voided' : 'pending');
const stateTone = { signed: 'bg-tint-success text-on-success', voided: 'bg-ink-100 text-ink-500', pending: 'bg-tint-warning text-on-warning' };
const stateLabel = computed(() => ({ signed: 'Signed', voided: 'Voided', pending: 'Awaiting signature' }));
</script>

<template>
    <AppLayout title="Handovers">
        <!-- live region: announces the pending-signature count to screen readers as it changes -->
        <span class="sr-only" aria-live="polite" aria-atomic="true">
            {{ awaiting.length ? `${awaiting.length} handover(s) awaiting your signature` : 'No handovers awaiting your signature' }}
        </span>
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line w-fit">
                <button @click="tab = 'awaiting'" class="rounded-lg px-4 py-2 text-sm font-semibold transition" :class="tab === 'awaiting' ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">Awaiting my signature ({{ awaiting.length }})</button>
                <button @click="tab = 'outgoing'" class="rounded-lg px-4 py-2 text-sm font-semibold transition" :class="tab === 'outgoing' ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">My outgoing ({{ outgoing.length }})</button>
            </div>
            <span class="text-sm text-ink-400">{{ tab === 'awaiting' ? 'patients handed over to you — review and sign' : 'patients you handed over in the last 7 days' }}</span>
            <button v-if="tab === 'awaiting' && awaiting.length > 1" @click="signAll" class="ml-auto rounded-xl bg-brand-solid px-4 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover">Sign all ({{ awaiting.length }})</button>
        </div>

        <!-- awaiting my signature -->
        <div v-show="tab === 'awaiting'" class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                    <th scope="col" class="px-5 py-3">Patient</th><th scope="col" class="px-3 py-3">Bed</th><th scope="col" class="px-3 py-3">From</th><th scope="col" class="px-3 py-3">Required</th><th scope="col" class="px-5 py-3 text-right">Sign</th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    <template v-for="s in awaiting" :key="s.id">
                        <tr class="cursor-pointer transition hover:bg-brand-50/40" @click="toggle(s.id)" :aria-expanded="open === s.id">
                            <td class="px-5 py-3">
                                <svg class="mr-1.5 inline h-4 w-4 text-ink-300" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="open === s.id ? 'm4.5 15.75 7.5-7.5 7.5 7.5' : 'm19.5 8.25-7.5 7.5-7.5-7.5'" /></svg>
                                <span class="font-semibold text-ink-800">{{ s.patient }}</span>
                                <span class="nums ml-2 text-xs text-ink-400">MRN {{ s.mrn }}</span>
                            </td>
                            <td class="nums px-3 py-3 text-ink-600">{{ s.bed || '—' }}</td>
                            <td class="px-3 py-3 text-ink-600">Dr. {{ s.from }}</td>
                            <td class="nums px-3 py-3 text-ink-500" :title="fmt(s.required_at)">{{ relTime(s.required_at) }}</td>
                            <!-- W0-T3e. `hover:bg-success-200` was dead (undeclared) → no hover state.
                                 It cannot be revived under a success-600-as-text label: that pair is
                                 ALREADY 4.32:1 on success-100, so every darker mint is worse (Tailwind's
                                 own green-200 gives 4.14:1). Moved to the theme-aware AA pair —
                                 on-success on tint-success = 6.02:1 light / 8.98:1 dark — and
                                 `--color-success-200` is likewise theme-aware, so the hover DARKENS on
                                 light and BRIGHTENS on dark: 5.52:1 / 6.74:1. In light mode the rest
                                 fill is byte-identical (tint-success == success-100). -->
                            <td class="px-5 py-3 text-right"><button @click.stop="sign(s)" class="rounded-lg bg-tint-success px-3 py-1.5 text-sm font-semibold text-on-success hover:bg-success-200">Sign</button></td>
                        </tr>
                        <tr v-if="open === s.id" class="bg-app/60">
                            <td colspan="5" class="px-6 py-4">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-400">Handover<span v-if="s.body_updated_at" class="ml-2 normal-case font-normal">last updated {{ fmt(s.body_updated_at) }}</span></p>
                                <p class="whitespace-pre-wrap text-sm leading-relaxed text-ink-700">{{ s.body || 'No handover text recorded.' }}</p>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!awaiting.length"><td colspan="5" class="px-5 py-10 text-center text-ink-400">No handovers awaiting your signature.</td></tr>
                </tbody>
            </table>
            </div>
        </div>

        <!-- my outgoing -->
        <div v-show="tab === 'outgoing'" class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                    <th scope="col" class="px-5 py-3">Patient</th><th scope="col" class="px-3 py-3">To</th><th scope="col" class="px-3 py-3">Handed over</th><th scope="col" class="px-3 py-3">Status</th><th scope="col" class="px-5 py-3">Handover text</th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="s in outgoing" :key="s.id" class="align-top hover:bg-brand-50/40">
                        <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ s.patient }}</div><div class="nums text-xs text-ink-400">MRN {{ s.mrn }}</div></td>
                        <td class="px-3 py-3 text-ink-600">Dr. {{ s.to }}</td>
                        <td class="nums px-3 py-3 text-ink-500" :title="fmt(s.required_at)">{{ relTime(s.required_at) }}</td>
                        <td class="px-3 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="stateTone[stateOf(s)]" :title="s.signed_at ? fmt(s.signed_at) : ''">{{ stateLabel[stateOf(s)] }}</span></td>
                        <td class="px-5 py-3">
                            <template v-if="editing === s.id">
                                <textarea v-model="eForm.body" rows="4" maxlength="5000" aria-label="Handover text" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>
                                <p v-if="eForm.errors.body" class="mt-1 text-xs text-on-danger">{{ eForm.errors.body }}</p>
                                <div class="mt-1.5 flex justify-end gap-2">
                                    <button @click="editing = null" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-ink-500">Cancel</button>
                                    <button @click="saveEdit(s)" :disabled="eForm.processing || !eForm.body.trim()" class="rounded-lg bg-brand-solid px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Save</button>
                                </div>
                            </template>
                            <template v-else>
                                <p class="whitespace-pre-wrap text-sm text-ink-600">{{ s.body || '—' }}</p>
                                <button v-if="!s.signed_at && !s.voided_at" @click="startEdit(s)" class="mt-1 text-xs font-semibold text-brand-600 hover:underline">Update text</button>
                                <p v-else-if="s.signed_at" class="mt-1 text-xs text-ink-400">Signed — text locked for this handover.</p>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!outgoing.length"><td colspan="5" class="px-5 py-10 text-center text-ink-400">No outgoing handovers in the last 7 days.</td></tr>
                </tbody>
            </table>
            </div>
        </div>
    </AppLayout>
</template>
