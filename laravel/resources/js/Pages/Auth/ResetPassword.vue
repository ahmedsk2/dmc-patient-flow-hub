<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import EhcLogo from '@/Components/EhcLogo.vue';
import PasswordMeter from '@/Components/PasswordMeter.vue';

const props = defineProps({ token: String, email: String });
const form = useForm({ token: props.token, email: props.email || '', password: '', password_confirmation: '' });
const submit = () => form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });

// legacy parity: the new password must be STRONG (zxcvbn score >= 3)
const pwScore = ref(0);
const pwTooWeak = computed(() => form.password.length > 0 && pwScore.value < 3);

const field = 'w-full rounded-xl border border-ink-200 px-4 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
</script>

<template>
    <Head title="Set a new password" />
    <div class="flex min-h-screen items-center justify-center bg-app p-6">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex flex-col items-center text-center">
                <div class="grid h-12 w-12 place-items-center rounded-2xl bg-card p-1.5 shadow"><EhcLogo class="h-8 w-8" /></div>
                <h1 class="mt-4 text-xl font-bold text-ink-900">Set a new password</h1>
            </div>
            <form @submit.prevent="submit" class="space-y-3 rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <div><label class="mb-1 block text-sm font-semibold text-ink-700">Email</label><input v-model="form.email" type="email" :class="[field, form.errors.email && 'border-danger-500']" /><p v-if="form.errors.email" class="mt-1 text-xs text-danger-600">{{ form.errors.email }}</p></div>
                <div><label class="mb-1 block text-sm font-semibold text-ink-700">New password</label><input v-model="form.password" type="password" :class="[field, form.errors.password && 'border-danger-500']" /><PasswordMeter :password="form.password" @score="pwScore = $event" /><p v-if="form.errors.password" class="mt-1 text-xs text-danger-600">{{ form.errors.password }}</p></div>
                <div><label class="mb-1 block text-sm font-semibold text-ink-700">Confirm password</label><input v-model="form.password_confirmation" type="password" :class="field" /></div>
                <button type="submit" :disabled="form.processing || pwTooWeak" class="w-full rounded-xl bg-gradient-to-r from-brand-500 to-brand-700 px-4 py-3 font-semibold text-white shadow-lg shadow-brand-900/20 transition hover:from-brand-600 hover:to-brand-800 disabled:opacity-60">{{ form.processing ? 'Saving…' : 'Reset password' }}</button>
            </form>
            <p class="mt-6 text-center text-xs text-ink-400"><Link href="/login" class="font-semibold text-brand-600 hover:text-brand-700">Back to sign in</Link></p>
        </div>
    </div>
</template>
