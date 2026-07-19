<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

// trustedDeviceHours is the admin-configured window; 0 means the feature is off and the opt-in
// checkbox is not rendered at all. It is NEVER pre-ticked — on a shared ward workstation an
// automatic trust would silently waive the second factor for whoever sits down next.
const props = defineProps({ trustedDeviceHours: { type: Number, default: 0 } });

const form = useForm({ code: '', trust_device: false });
const recovery = ref(false);
const submit = () => form.post('/mfa/challenge', { onFinish: () => form.reset('code') });
</script>

<template>
    <Head title="Two-factor verification" />
    <div class="flex min-h-screen items-center justify-center bg-app p-6">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex flex-col items-center text-center">
                <div class="grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                </div>
                <h1 class="mt-4 text-xl font-bold text-ink-900">Two-factor verification</h1>
                <p class="mt-1 text-sm text-ink-500">{{ recovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.' }}</p>
            </div>

            <form @submit.prevent="submit" class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <input v-model="form.code" :inputmode="recovery ? 'text' : 'numeric'" autofocus autocomplete="one-time-code"
                    :placeholder="recovery ? 'XXXX-XXXX' : '123456'"
                    class="w-full rounded-xl border border-ink-200 px-4 py-3 text-center text-2xl font-bold tracking-[0.3em] text-ink-800 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                    :class="{ 'border-danger-500': form.errors.code }" />
                <p v-if="form.errors.code" class="mt-2 text-center text-xs text-on-danger">{{ form.errors.code }}</p>

                <div v-if="props.trustedDeviceHours > 0" class="mt-4">
                    <div class="flex items-start gap-2.5">
                        <input id="trust_device" v-model="form.trust_device" type="checkbox"
                            class="mt-0.5 h-4 w-4 shrink-0 rounded border-ink-200 text-brand-600 focus:ring-2 focus:ring-brand-500/20" />
                        <label for="trust_device" class="text-sm text-ink-700">Don't ask for a code on this device for the next {{ props.trustedDeviceHours }} hours.</label>
                    </div>
                    <p class="mt-1 pl-6 text-xs text-ink-400">Leave this unticked on a shared or ward computer.</p>
                </div>

                <button type="submit" :disabled="form.processing"
                    class="mt-4 w-full rounded-xl bg-gradient-to-r from-brand-500 to-brand-700 px-4 py-3 font-semibold text-white shadow-lg shadow-brand-900/20 transition hover:from-brand-600 hover:to-brand-800 disabled:opacity-60">
                    {{ form.processing ? 'Verifying…' : 'Verify' }}
                </button>
                <button type="button" @click="recovery = !recovery; form.reset('code')" class="mt-3 w-full text-center text-xs font-semibold text-brand-600 hover:text-brand-700">
                    {{ recovery ? 'Use an authenticator code instead' : 'Use a recovery code instead' }}
                </button>
            </form>
            <p class="mt-6 text-center text-xs text-ink-400"><a href="/login" class="font-semibold text-ink-500 hover:text-ink-700">Back to sign in</a></p>
        </div>
    </div>
</template>
