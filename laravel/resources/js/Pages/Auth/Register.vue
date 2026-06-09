<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import EhcLogo from '@/Components/EhcLogo.vue';

defineProps({ roles: Object });
const form = useForm({ username: '', full_name: '', email: '', role: '', password: '', password_confirmation: '' });
const submit = () => form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
const field = 'w-full rounded-xl border border-ink-200 px-4 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
</script>

<template>
    <Head title="Register" />
    <div class="flex min-h-screen items-center justify-center bg-surface p-6">
        <div class="w-full max-w-md">
            <div class="mb-6 flex flex-col items-center text-center">
                <div class="grid h-12 w-12 place-items-center rounded-2xl bg-white p-1.5 shadow"><EhcLogo class="h-8 w-8" /></div>
                <h1 class="mt-4 text-xl font-bold text-ink-900">Create an account</h1>
                <p class="mt-1 text-sm text-ink-500">An administrator will activate it before you can sign in.</p>
            </div>
            <form @submit.prevent="submit" class="space-y-3 rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Username</label><input v-model="form.username" :class="[field, form.errors.username && 'border-danger-500']" /><p v-if="form.errors.username" class="mt-1 text-xs text-danger-600">{{ form.errors.username }}</p></div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Role</label><select v-model="form.role" :class="[field, form.errors.role && 'border-danger-500']"><option value="">Select…</option><option v-for="(label, id) in roles" :key="id" :value="Number(id)">{{ label }}</option></select><p v-if="form.errors.role" class="mt-1 text-xs text-danger-600">{{ form.errors.role }}</p></div>
                </div>
                <div><label class="mb-1 block text-sm font-semibold text-ink-700">Full name</label><input v-model="form.full_name" :class="[field, form.errors.full_name && 'border-danger-500']" /><p v-if="form.errors.full_name" class="mt-1 text-xs text-danger-600">{{ form.errors.full_name }}</p></div>
                <div><label class="mb-1 block text-sm font-semibold text-ink-700">Email</label><input v-model="form.email" type="email" :class="[field, form.errors.email && 'border-danger-500']" /><p v-if="form.errors.email" class="mt-1 text-xs text-danger-600">{{ form.errors.email }}</p></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Password</label><input v-model="form.password" type="password" :class="[field, form.errors.password && 'border-danger-500']" /><p v-if="form.errors.password" class="mt-1 text-xs text-danger-600">{{ form.errors.password }}</p></div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Confirm</label><input v-model="form.password_confirmation" type="password" :class="field" /></div>
                </div>
                <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-gradient-to-r from-brand-500 to-brand-700 px-4 py-3 font-semibold text-white shadow-lg shadow-brand-900/20 transition hover:from-brand-600 hover:to-brand-800 disabled:opacity-60">{{ form.processing ? 'Registering…' : 'Register' }}</button>
            </form>
            <p class="mt-6 text-center text-xs text-ink-400">Already have an account? <Link href="/login" class="font-semibold text-brand-600 hover:text-brand-700">Sign in</Link></p>
        </div>
    </div>
</template>
