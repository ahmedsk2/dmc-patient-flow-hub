<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { xsrf } from '@/lib/ui.js';

// Existing-user email-verify gate (2026-07-11 design, spec §E). Reached only when the user has an
// on-file, unverified email (EnsureEmailVerified middleware). "Send" returns plain 200/422 JSON
// (no page nav) so it goes through fetch+xsrf, same as Register.vue's steps; "Confirm" redirects
// the user onward on success, so it goes through Inertia's router.post — the same mechanism every
// other redirect-producing action in this app already uses (AppLayout's logout(), Patients/Index
// assign/discharge, etc.) — never a raw fetch, which cannot follow a real Inertia redirect.

defineProps({ email: String });

const sent = ref(false);
const sending = ref(false);
const sendError = ref('');
const code = ref('');
const codeError = ref('');
const verifying = ref(false);
const resendSeconds = ref(0);
let cooldownTimer = null;

function startCooldown() {
    resendSeconds.value = 60;
    clearInterval(cooldownTimer);
    cooldownTimer = setInterval(() => {
        resendSeconds.value -= 1;
        if (resendSeconds.value <= 0) clearInterval(cooldownTimer);
    }, 1000);
}
onBeforeUnmount(() => clearInterval(cooldownTimer));

function firstError(e) {
    return Array.isArray(e) ? e[0] : (e || '');
}

async function sendCode() {
    if (sending.value) return;
    sending.value = true;
    sendError.value = '';
    try {
        const res = await fetch('/email/verify/send', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        });
        if (res.ok) {
            sent.value = true;
            startCooldown();
        } else {
            sendError.value = 'Could not send the code.';
        }
    } catch {
        sendError.value = 'Could not send the code.';
    } finally {
        sending.value = false;
    }
}

function resend() {
    if (resendSeconds.value > 0) return;
    sendCode();
}

function verify() {
    if (verifying.value) return;
    verifying.value = true;
    codeError.value = '';
    router.post('/email/verify/confirm', { code: code.value }, {
        onError: (errors) => { codeError.value = firstError(errors?.code) || 'Invalid or expired code.'; },
        onFinish: () => { verifying.value = false; },
    });
}

const signOut = () => router.post('/logout');
</script>

<template>
    <Head title="Verify your email" />
    <div class="flex min-h-screen items-center justify-center bg-app p-6">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex flex-col items-center text-center">
                <div class="grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0-.621.504-1.125 1.125-1.125h17.25c.621 0 1.125.504 1.125 1.125v10.5c0 .621-.504 1.125-1.125 1.125H3.375A1.125 1.125 0 0 1 2.25 17.25V6.75Zm1.5.3 8.25 5.625L20.25 7.05" /></svg>
                </div>
                <h1 class="mt-4 text-xl font-bold text-ink-900">Verify your email</h1>
                <p class="mt-1 text-sm text-ink-500">We need to confirm <span class="font-semibold text-ink-700">{{ email }}</span> before you can continue.</p>
            </div>

            <div class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <button v-if="!sent" type="button" :disabled="sending" @click="sendCode"
                    class="w-full rounded-xl bg-brand-solid px-4 py-3 font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-60">
                    {{ sending ? 'Sending…' : 'Send code' }}
                </button>
                <p v-if="sendError" class="mt-2 text-center text-xs text-on-danger">{{ sendError }}</p>

                <template v-if="sent">
                    <label for="verify-code" class="mb-1 block text-sm font-semibold text-ink-700">Verification code</label>
                    <input id="verify-code" v-model="code" inputmode="numeric" autocomplete="off" maxlength="6" autofocus
                        class="w-full rounded-xl border border-ink-200 px-4 py-3 text-center text-2xl font-bold tracking-[0.3em] text-ink-800 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        :class="{ 'border-danger-500': codeError }" />
                    <p v-if="codeError" class="mt-2 text-center text-xs text-on-danger">{{ codeError }}</p>
                    <button type="button" :disabled="verifying || code.length !== 6" @click="verify"
                        class="mt-4 w-full rounded-xl bg-brand-solid px-4 py-3 font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-60">
                        {{ verifying ? 'Verifying…' : 'Verify' }}
                    </button>
                    <button type="button" :disabled="resendSeconds > 0" @click="resend"
                        class="mt-3 w-full text-center text-xs font-semibold text-brand-600 hover:text-brand-700 disabled:cursor-not-allowed disabled:text-ink-400">
                        {{ resendSeconds > 0 ? `Resend (${resendSeconds}s)` : 'Resend' }}
                    </button>
                </template>
            </div>
            <p class="mt-6 text-center text-xs text-ink-400">
                <button type="button" @click="signOut" class="font-semibold text-ink-500 hover:text-ink-700">Sign out</button>
            </p>
        </div>
    </div>
</template>
