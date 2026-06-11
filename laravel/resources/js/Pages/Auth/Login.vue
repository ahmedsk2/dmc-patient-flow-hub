<script setup>
import { computed } from 'vue';
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import EhcLogo from '@/Components/EhcLogo.vue';

const form = useForm({ username: '', password: '', remember: false });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });

// guest-side flash (e.g. "sign-in expired" / "too many codes" from a rejected MFA challenge)
const flash = computed(() => usePage().props.flash);
</script>

<template>
    <Head title="Sign in" />
    <div class="flex min-h-screen bg-surface">
        <!-- Brand panel -->
        <div class="relative hidden w-1/2 overflow-hidden bg-gradient-to-br from-navy-800 via-navy-900 to-navy-950 lg:flex lg:flex-col lg:justify-between p-12 text-white">
            <div class="flex items-center gap-3">
                <div class="grid h-11 w-11 place-items-center rounded-2xl bg-white p-1.5 shadow-lg"><EhcLogo class="h-8 w-8" /></div>
                <div>
                    <div class="text-lg font-bold tracking-wide">DMC <span class="text-brand-300">Internal Medicine</span></div>
                    <div class="text-[11px] uppercase tracking-[0.18em] text-navy-300">Patient-Flow Hub</div>
                </div>
            </div>
            <div>
                <h1 class="text-4xl font-extrabold leading-tight">Care, coordinated.</h1>
                <p class="mt-4 max-w-md text-navy-200">Admissions, consultations, and live ward intelligence — one calm, modern command center for the Internal Medicine unit.</p>
                <div class="mt-8 flex gap-6 text-sm text-navy-300">
                    <div><span class="block text-2xl font-bold text-brand-300">22</span>hospitals</div>
                    <div><span class="block text-2xl font-bold text-brand-300">3,400</span>beds</div>
                    <div><span class="block text-2xl font-bold text-accent-400">24/7</span>live</div>
                </div>
            </div>
            <div class="text-sm text-navy-300">
                Eastern Health Cluster · <span class="text-brand-300">تجمع الشرقية الصحي</span>
            </div>
            <div class="pointer-events-none absolute -bottom-24 -right-24 h-80 w-80 rounded-full bg-brand-500/20 blur-3xl"></div>
            <div class="pointer-events-none absolute -top-20 -left-10 h-60 w-60 rounded-full bg-accent-500/10 blur-3xl"></div>
        </div>

        <!-- Form -->
        <div class="flex w-full items-center justify-center px-6 lg:w-1/2">
            <div class="w-full max-w-sm">
                <div class="mb-8 lg:hidden">
                    <div class="grid h-11 w-11 place-items-center rounded-2xl bg-brand-50 p-1.5 ring-1 ring-brand-100"><EhcLogo class="h-8 w-8" /></div>
                </div>
                <h2 class="text-2xl font-bold text-ink-900">Welcome back</h2>
                <p class="mt-1 text-sm text-ink-500">Sign in to the patient-flow hub.</p>

                <p v-if="flash?.message" class="mt-4 rounded-xl px-3.5 py-2.5 text-sm font-semibold"
                    :class="flash.type === 'error' ? 'bg-danger-100 text-danger-600' : 'bg-success-100 text-success-600'">
                    {{ flash.message }}
                </p>

                <form @submit.prevent="submit" class="mt-8 space-y-5">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink-700">Username</label>
                        <input v-model="form.username" type="text" autofocus autocomplete="username"
                            class="w-full rounded-xl border border-ink-200 bg-white px-4 py-2.5 text-ink-800 outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                            :class="{ 'border-danger-500': form.errors.username }" placeholder="e.g. e2e_admin" />
                        <p v-if="form.errors.username" class="mt-1 text-xs text-danger-600">{{ form.errors.username }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink-700">Password</label>
                        <input v-model="form.password" type="password" autocomplete="current-password"
                            class="w-full rounded-xl border border-ink-200 bg-white px-4 py-2.5 text-ink-800 outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                            :class="{ 'border-danger-500': form.errors.password }" placeholder="••••••••" />
                        <p v-if="form.errors.password" class="mt-1 text-xs text-danger-600">{{ form.errors.password }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-ink-600">
                        <input v-model="form.remember" type="checkbox" class="rounded border-ink-300 text-brand-600 focus:ring-brand-500/30" />
                        Keep me signed in
                    </label>
                    <button type="submit" :disabled="form.processing"
                        class="w-full rounded-xl bg-gradient-to-r from-brand-500 to-brand-700 px-4 py-3 font-semibold text-white shadow-lg shadow-brand-900/20 transition hover:from-brand-600 hover:to-brand-800 disabled:opacity-60">
                        <span v-if="!form.processing">Sign in</span>
                        <span v-else>Signing in…</span>
                    </button>
                </form>

                <div class="mt-6 flex items-center justify-between text-sm">
                    <Link href="/register" class="font-semibold text-brand-600 hover:text-brand-700">Create account</Link>
                    <Link href="/forgot-password" class="font-semibold text-ink-500 hover:text-ink-700">Forgot password?</Link>
                </div>
                <p class="mt-6 text-center text-xs text-ink-400">DMC Internal Medicine · secured access</p>
            </div>
        </div>
    </div>
</template>
