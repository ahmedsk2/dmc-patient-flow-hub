<script setup>
import { useId } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import EhcLogo from '@/Components/EhcLogo.vue';

defineProps({ status: String });
const form = useForm({ email: '' });
// Accessible names: pair each <label for> with its control id (UX-04). Same fid() idiom as PatientForm.
const uid = useId();
const fid = (name) => `forgot-username-${uid}-${name}`;
const submit = () => form.post('/forgot-username');
</script>

<template>
    <Head title="Forgot username" />
    <div class="flex min-h-screen items-center justify-center bg-app p-6">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex flex-col items-center text-center">
                <div class="grid h-12 w-12 place-items-center rounded-2xl bg-card p-1.5 shadow"><EhcLogo class="h-8 w-8" /></div>
                <h1 class="mt-4 text-xl font-bold text-ink-900">Forgot your username?</h1>
                <p class="mt-1 text-sm text-ink-500">Enter your email and we'll send you your username.</p>
            </div>
            <form @submit.prevent="submit" class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <p v-if="status" class="mb-3 rounded-lg bg-tint-success px-3 py-2 text-sm font-medium text-on-success">{{ status }}</p>
                <label :for="fid('email')" class="mb-1 block text-sm font-semibold text-ink-700">Email</label>
                <input :id="fid('email')" v-model="form.email" type="email" autocomplete="email" autofocus :aria-describedby="form.errors.email ? fid('email') + '-err' : undefined" class="w-full rounded-xl border border-ink-200 px-4 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" :class="{ 'border-danger-500': form.errors.email }" />
                <p v-if="form.errors.email" :id="fid('email') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.email }}</p>
                <button type="submit" :disabled="form.processing" class="mt-4 w-full rounded-xl bg-gradient-to-r from-brand-500 to-brand-700 px-4 py-3 font-semibold text-white shadow-lg shadow-brand-900/20 transition hover:from-brand-600 hover:to-brand-800 disabled:opacity-60">{{ form.processing ? 'Sending…' : 'Email me my username' }}</button>
            </form>
            <p class="mt-6 text-center text-xs text-ink-400"><Link href="/login" class="font-semibold text-brand-600 hover:text-brand-700">Back to sign in</Link></p>
        </div>
    </div>
</template>
