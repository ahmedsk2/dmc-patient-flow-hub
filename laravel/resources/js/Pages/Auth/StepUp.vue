<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

/**
 * Phase 4 — Item 4: step-up re-authentication. A minimal centered card — password (+ a current
 * TOTP code if the user is MFA-enrolled). On success the server refreshes the 5-minute step-up
 * window and returns the user to where they were (or the Control panel for a non-replayable action).
 */
const page = usePage();
const mfaEnrolled = page.props.auth?.user?.mfa_enrolled ?? false;

const form = useForm({ password: '', code: '' });
const submit = () => form.post('/stepup', { preserveScroll: true, onFinish: () => form.reset('password', 'code') });

const field = 'w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
</script>

<template>
    <Head title="Confirm your identity" />
    <div class="grid min-h-screen place-items-center bg-ink-50 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-card p-7 shadow-2xl ring-1 ring-line">
            <div class="mb-5 text-center">
                <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-brand-100 text-brand-700">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                </div>
                <h1 class="font-display text-xl font-bold text-ink-900">Confirm your identity</h1>
                <p class="mt-1 text-sm text-ink-500">This is a high-risk action. Re-enter your password to continue.</p>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <label class="block">
                    <span class="mb-1 block text-sm font-semibold text-ink-700">Password</span>
                    <input v-model="form.password" type="password" autocomplete="current-password" autofocus :class="field" />
                    <p v-if="form.errors.password" class="mt-1 text-sm text-on-danger">{{ form.errors.password }}</p>
                </label>

                <label v-if="mfaEnrolled" class="block">
                    <span class="mb-1 block text-sm font-semibold text-ink-700">Authentication code</span>
                    <input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" placeholder="6-digit code" :class="[field, 'nums tracking-widest']" />
                    <p v-if="form.errors.code" class="mt-1 text-sm text-on-danger">{{ form.errors.code }}</p>
                </label>

                <button type="submit" :disabled="form.processing"
                    class="w-full rounded-xl bg-brand-solid px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">
                    Confirm
                </button>
                <Link href="/" class="block text-center text-sm text-ink-400 hover:text-ink-600">Cancel</Link>
            </form>
        </div>
    </div>
</template>
