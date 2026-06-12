<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ profile: Object });

// MFA self-disable was removed (owner decision, J2-14) — enrolled users can only have their
// two-factor reset by an admin from the Control panel.

const pForm = useForm({ full_name: props.profile.name, email: props.profile.email, username: props.profile.username });
const saveProfile = () => pForm.put('/profile', { preserveScroll: true });

const wForm = useForm({ current_password: '', password: '', password_confirmation: '' });
const savePassword = () => wForm.put('/profile/password', { preserveScroll: true, onSuccess: () => wForm.reset() });

const field = 'w-full rounded-xl border border-ink-200 px-3.5 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
</script>

<template>
    <Head title="My Profile" />
    <AppLayout title="My Profile">
        <div class="mx-auto grid max-w-3xl gap-6">
            <!-- identity -->
            <section class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <div class="flex items-center gap-4">
                    <div class="grid h-16 w-16 place-items-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 text-2xl font-bold text-white">{{ (profile.name||'?').slice(0,2).toUpperCase() }}</div>
                    <div>
                        <h2 class="text-xl font-bold text-ink-900">{{ profile.name }}</h2>
                        <p class="text-sm text-ink-500">@{{ profile.username }} · {{ profile.role }}</p>
                        <p class="text-xs text-ink-400">Password set {{ profile.pass_exp_date || '—' }} · MFA {{ profile.mfa_enabled ? 'enabled' : 'not enabled' }}</p>
                    </div>
                </div>
            </section>

            <!-- profile -->
            <section class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-4 font-bold text-ink-800">Profile details</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Full name</label><input v-model="pForm.full_name" :class="[field, pForm.errors.full_name && 'border-danger-500']" /><p v-if="pForm.errors.full_name" class="mt-1 text-xs text-danger-600">{{ pForm.errors.full_name }}</p></div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Email</label><input v-model="pForm.email" type="email" :class="[field, pForm.errors.email && 'border-danger-500']" /><p v-if="pForm.errors.email" class="mt-1 text-xs text-danger-600">{{ pForm.errors.email }}</p></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Username</label>
                        <input v-model="pForm.username" autocomplete="username" :class="[field, 'sm:max-w-xs', pForm.errors.username && 'border-danger-500']" />
                        <p v-if="pForm.errors.username" class="mt-1 text-xs text-danger-600">{{ pForm.errors.username }}</p>
                        <p class="mt-1 text-xs text-ink-400">This is your <strong>login name</strong> — changing it changes what you type to sign in. Letters, numbers, dashes and underscores only.</p>
                    </div>
                </div>
                <div class="mt-4 flex items-center gap-3">
                    <button @click="saveProfile" :disabled="pForm.processing" class="rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save profile</button>
                    <span v-if="pForm.recentlySuccessful" class="text-sm font-semibold text-success-600">Saved ✓</span>
                </div>
            </section>

            <!-- password -->
            <section class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-1 font-bold text-ink-800">Change password</h3>
                <p class="mb-4 text-sm text-ink-400">At least 8 characters, with letters and numbers.</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Current</label><input v-model="wForm.current_password" type="password" :class="[field, wForm.errors.current_password && 'border-danger-500']" /><p v-if="wForm.errors.current_password" class="mt-1 text-xs text-danger-600">{{ wForm.errors.current_password }}</p></div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">New</label><input v-model="wForm.password" type="password" :class="[field, wForm.errors.password && 'border-danger-500']" /><p v-if="wForm.errors.password" class="mt-1 text-xs text-danger-600">{{ wForm.errors.password }}</p></div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Confirm</label><input v-model="wForm.password_confirmation" type="password" :class="field" /></div>
                </div>
                <div class="mt-4 flex items-center gap-3">
                    <button @click="savePassword" :disabled="wForm.processing" class="rounded-xl bg-navy-800 px-5 py-2.5 text-sm font-semibold text-white hover:bg-navy-900 disabled:opacity-50">Update password</button>
                    <span v-if="wForm.recentlySuccessful" class="text-sm font-semibold text-success-600">Password changed ✓</span>
                </div>
            </section>

            <!-- two-factor -->
            <section class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="flex items-center gap-2 font-bold text-ink-800">
                            Two-factor authentication
                            <span v-if="profile.mfa_enabled" class="rounded-full bg-success-100 px-2 py-0.5 text-xs font-semibold text-success-600">Enabled</span>
                            <span v-else class="rounded-full bg-ink-100 px-2 py-0.5 text-xs font-semibold text-ink-500">Off</span>
                        </h3>
                        <p class="mt-1 max-w-md text-sm text-ink-400">Protect your account with a time-based code from an authenticator app, in addition to your password.</p>
                    </div>
                    <Link v-if="!profile.mfa_enabled" href="/mfa/setup" class="shrink-0 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-brand-700">Enable</Link>
                </div>
                <p v-if="profile.mfa_enabled" class="mt-4 border-t border-ink-100 pt-4 text-sm text-ink-400">
                    Two-factor can only be reset by an administrator — contact one if you lose your authenticator.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
