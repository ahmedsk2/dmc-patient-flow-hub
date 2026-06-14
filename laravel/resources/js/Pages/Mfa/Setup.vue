<script setup>
import { ref, onMounted } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ secret: String, otpauthUri: String, recoveryCodes: Array });

const qr = ref('');
onMounted(async () => { qr.value = await QRCode.toDataURL(props.otpauthUri, { width: 220, margin: 1 }); });

const form = useForm({ code: '' });
const confirm = () => form.post('/mfa/confirm', { onFinish: () => form.reset('code') });

const copyCodes = () => navigator.clipboard?.writeText(props.recoveryCodes.join('\n'));
</script>

<template>
    <Head title="Enable two-factor" />
    <AppLayout title="Enable two-factor authentication">
        <div class="mx-auto max-w-3xl space-y-6">
            <div class="grid gap-6 md:grid-cols-2">
                <!-- step 1: scan -->
                <section class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                    <h2 class="mb-1 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-brand-700"><span class="grid h-6 w-6 place-items-center rounded-lg bg-brand-100">1</span> Scan</h2>
                    <p class="mb-4 text-sm text-ink-500">Scan with Google Authenticator, Microsoft Authenticator, or Authy.</p>
                    <div class="flex justify-center">
                        <img v-if="qr" :src="qr" alt="Two-factor QR code" class="rounded-xl ring-1 ring-line" />
                    </div>
                    <p class="mt-4 text-xs text-ink-400">Can't scan? Enter this key manually:</p>
                    <code class="mt-1 block break-all rounded-lg bg-app px-3 py-2 text-sm font-semibold tracking-wider text-ink-700">{{ secret }}</code>
                </section>

                <!-- step 2: confirm -->
                <section class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                    <h2 class="mb-1 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-brand-700"><span class="grid h-6 w-6 place-items-center rounded-lg bg-brand-100">2</span> Confirm</h2>
                    <p class="mb-4 text-sm text-ink-500">Enter the 6-digit code your app shows to finish enabling.</p>
                    <form @submit.prevent="confirm">
                        <input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456"
                            class="w-full rounded-xl border border-ink-200 px-4 py-3 text-center text-2xl font-bold tracking-[0.3em] outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                            :class="{ 'border-danger-500': form.errors.code }" />
                        <p v-if="form.errors.code" class="mt-2 text-xs text-danger-600">{{ form.errors.code }}</p>
                        <button type="submit" :disabled="form.processing" class="mt-4 w-full rounded-xl bg-brand-600 px-4 py-3 font-semibold text-white shadow transition hover:bg-brand-700 disabled:opacity-60">
                            {{ form.processing ? 'Verifying…' : 'Enable two-factor' }}
                        </button>
                    </form>
                </section>
            </div>

            <!-- recovery codes -->
            <section class="rounded-2xl border-2 border-dashed border-warning-300 bg-warning-50/50 p-6">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-warning-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                        Save your recovery codes
                    </h2>
                    <button @click="copyCodes" class="rounded-lg bg-card px-3 py-1.5 text-xs font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">Copy all</button>
                </div>
                <p class="mb-3 text-sm text-ink-600">Each code works once if you lose your device. Store them somewhere safe — they won't be shown again.</p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <code v-for="c in recoveryCodes" :key="c" class="rounded-lg bg-card px-3 py-2 text-center text-sm font-semibold tracking-wider text-ink-700 ring-1 ring-line">{{ c }}</code>
                </div>
            </section>

            <div class="text-center"><a href="/profile" class="text-sm font-semibold text-ink-500 hover:text-ink-700">Cancel</a></div>
        </div>
    </AppLayout>
</template>
