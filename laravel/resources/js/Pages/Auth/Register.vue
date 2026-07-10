<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import EhcLogo from '@/Components/EhcLogo.vue';
import PasswordMeter from '@/Components/PasswordMeter.vue';
import { xsrf } from '@/lib/ui.js';

// Mandatory MFA + email verification (2026-07-11 design, spec §E). Registration is now a
// reactive multi-phase form: the email must be verified mid-form, and an authenticator app must
// be provisioned + confirmed, BEFORE the account exists server-side (see PendingRegistration in
// the design doc). Only the FINAL /register submit goes through Inertia's useForm — every step
// endpoint returns a plain 200/422 JSON body (never an Inertia page), so those go through fetch +
// the cookie-based XSRF token, matching the Registry/QuickJump precedent in lib/ui.js.

defineProps({ roles: Object });

const form = useForm({ username: '', full_name: '', email: '', role: '', password: '', password_confirmation: '' });

function firstError(e) {
    return Array.isArray(e) ? e[0] : (e || '');
}

async function postJson(url, body) {
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify(body || {}),
        });
        let data = {};
        try { data = await res.json(); } catch { /* empty body */ }
        return { ok: res.ok, data };
    } catch {
        return { ok: false, data: {} };
    }
}

// --- phase 1/2: email send + confirm ---
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const validEmail = computed(() => EMAIL_RE.test(form.email));

const emailSent = ref(false);
const emailVerified = ref(false);
const sendingEmail = ref(false);
const verifyingEmail = ref(false);
const emailStepError = ref('');
const code = ref('');
const codeError = ref('');
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

async function sendCode() {
    if (!validEmail.value || sendingEmail.value) return;
    sendingEmail.value = true;
    emailStepError.value = '';
    const { ok, data } = await postJson('/register/email/send', { email: form.email });
    sendingEmail.value = false;
    if (ok) {
        emailSent.value = true;
        startCooldown();
    } else {
        emailStepError.value = firstError(data?.errors?.email) || 'Could not send the code.';
    }
}

function resendCode() {
    if (resendSeconds.value > 0) return;
    sendCode();
}

function changeEmail() {
    emailSent.value = false;
    emailVerified.value = false;
    code.value = '';
    codeError.value = '';
    emailStepError.value = '';
    clearInterval(cooldownTimer);
    resendSeconds.value = 0;
    // A different email means a different pending-registration row server-side — the
    // authenticator that was provisioned against the OLD row can no longer be honoured.
    mfaProvisioned.value = false;
    mfaConfirmed.value = false;
    mfaSecret.value = '';
    mfaOtpauthUri.value = '';
    mfaRecoveryCodes.value = [];
    qr.value = '';
    authCode.value = '';
    mfaError.value = '';
}

async function confirmCode() {
    if (verifyingEmail.value) return;
    verifyingEmail.value = true;
    codeError.value = '';
    const { ok, data } = await postJson('/register/email/verify', { code: code.value });
    verifyingEmail.value = false;
    if (ok) {
        emailVerified.value = true;
    } else {
        codeError.value = firstError(data?.errors?.code) || 'Invalid or expired code.';
    }
}

// --- phase 3: authenticator (TOTP) ---
const mfaProvisioned = ref(false);
const mfaConfirmed = ref(false);
const provisioning = ref(false);
const confirmingMfa = ref(false);
const mfaSecret = ref('');
const mfaOtpauthUri = ref('');
const mfaRecoveryCodes = ref([]);
const qr = ref('');
const authCode = ref('');
const mfaError = ref('');

async function provisionMfa() {
    if (provisioning.value || mfaProvisioned.value) return;
    provisioning.value = true;
    mfaError.value = '';
    const { ok, data } = await postJson('/register/mfa/provision', {});
    provisioning.value = false;
    if (ok) {
        mfaSecret.value = data.secret;
        mfaOtpauthUri.value = data.otpauthUri;
        mfaRecoveryCodes.value = data.recoveryCodes || [];
        mfaProvisioned.value = true;
        qr.value = await QRCode.toDataURL(mfaOtpauthUri.value, { width: 220, margin: 1 });
    } else {
        mfaError.value = 'Could not start authenticator setup.';
    }
}

async function confirmMfaCode() {
    if (confirmingMfa.value) return;
    confirmingMfa.value = true;
    mfaError.value = '';
    const { ok, data } = await postJson('/register/mfa/confirm', { code: authCode.value });
    confirmingMfa.value = false;
    if (ok) {
        mfaConfirmed.value = true;
    } else {
        mfaError.value = firstError(data?.errors?.code) || 'Invalid code.';
    }
}

const copyCodes = () => navigator.clipboard?.writeText(mfaRecoveryCodes.value.join('\n'));

// --- final create (Inertia) ---
const canCreate = computed(() => emailVerified.value && mfaConfirmed.value && !form.processing);
const submit = () => {
    if (!canCreate.value) return;
    form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
};

const field = 'w-full rounded-xl border border-ink-200 px-4 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
const codeField = 'w-32 rounded-xl border border-ink-200 px-3 py-2 text-center text-lg font-bold tracking-[0.3em] outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
</script>

<template>
    <Head title="Register" />
    <div class="flex min-h-screen items-center justify-center bg-app p-6">
        <div class="w-full max-w-md">
            <div class="mb-6 flex flex-col items-center text-center">
                <div class="grid h-12 w-12 place-items-center rounded-2xl bg-card p-1.5 shadow"><EhcLogo class="h-8 w-8" /></div>
                <h1 class="mt-4 text-xl font-bold text-ink-900">Create an account</h1>
                <p class="mt-1 text-sm text-ink-500">An administrator will activate it before you can sign in.</p>
            </div>

            <p class="mb-4 text-center text-xs font-semibold uppercase tracking-wide text-ink-400" aria-hidden="true">
                <span :class="{ 'text-brand-600': !emailVerified }">1 Verify email</span>
                <span> · </span>
                <span :class="{ 'text-brand-600': emailVerified && !mfaConfirmed }">2 Details</span>
                <span> · </span>
                <span :class="{ 'text-brand-600': mfaConfirmed }">3 Authenticator</span>
            </p>

            <form @submit.prevent="submit" class="space-y-3 rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="reg-username" class="mb-1 block text-sm font-semibold text-ink-700">Username</label>
                        <input id="reg-username" v-model="form.username" :class="[field, form.errors.username && 'border-danger-500']" />
                        <p v-if="form.errors.username" class="mt-1 text-xs text-on-danger">{{ form.errors.username }}</p>
                    </div>
                    <div>
                        <label for="reg-role" class="mb-1 block text-sm font-semibold text-ink-700">Role</label>
                        <select id="reg-role" v-model="form.role" :class="[field, form.errors.role && 'border-danger-500']">
                            <option value="">Select…</option>
                            <option v-for="(label, id) in roles" :key="id" :value="Number(id)">{{ label }}</option>
                        </select>
                        <p v-if="form.errors.role" class="mt-1 text-xs text-on-danger">{{ form.errors.role }}</p>
                    </div>
                </div>

                <div>
                    <label for="reg-fullname" class="mb-1 block text-sm font-semibold text-ink-700">Full name</label>
                    <input id="reg-fullname" v-model="form.full_name" :class="[field, form.errors.full_name && 'border-danger-500']" />
                    <p v-if="form.errors.full_name" class="mt-1 text-xs text-on-danger">{{ form.errors.full_name }}</p>
                </div>

                <!-- phase 1: email + send code -->
                <div>
                    <label for="reg-email" class="mb-1 block text-sm font-semibold text-ink-700">Email <span class="text-on-danger" aria-hidden="true">*</span></label>
                    <div class="flex gap-2">
                        <input id="reg-email" v-model="form.email" type="email" required autocomplete="email" :readonly="emailSent"
                            :class="[field, (form.errors.email || emailStepError) && 'border-danger-500', emailSent && 'bg-ink-50 text-ink-500']" />
                        <button v-if="!emailSent" type="button" :disabled="!validEmail || sendingEmail" @click="sendCode"
                            class="shrink-0 rounded-xl bg-brand-solid px-3 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">
                            {{ sendingEmail ? 'Sending…' : 'Send code' }}
                        </button>
                    </div>
                    <p v-if="form.errors.email" class="mt-1 text-xs text-on-danger">{{ form.errors.email }}</p>
                    <p v-if="emailStepError" class="mt-1 text-xs text-on-danger">{{ emailStepError }}</p>
                    <p v-if="emailVerified" class="mt-1 text-xs font-semibold text-on-success">Email verified</p>
                    <button v-else-if="emailSent" type="button" @click="changeEmail" class="mt-1 text-xs font-semibold text-brand-600 hover:text-brand-700">Change email</button>
                </div>

                <!-- phase 2: confirm the emailed code -->
                <div v-if="emailSent && !emailVerified">
                    <label for="reg-email-code" class="mb-1 block text-sm font-semibold text-ink-700">Verification code</label>
                    <div class="flex items-start gap-2">
                        <input id="reg-email-code" v-model="code" inputmode="numeric" autocomplete="off" maxlength="6"
                            :class="[codeField, codeError && 'border-danger-500']" />
                        <button type="button" :disabled="verifyingEmail || code.length !== 6" @click="confirmCode"
                            class="shrink-0 rounded-xl bg-brand-solid px-3 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">
                            {{ verifyingEmail ? 'Confirming…' : 'Confirm code' }}
                        </button>
                    </div>
                    <p v-if="codeError" class="mt-1 text-xs text-on-danger">{{ codeError }}</p>
                    <button type="button" :disabled="resendSeconds > 0" @click="resendCode"
                        class="mt-1 text-xs font-semibold text-brand-600 hover:text-brand-700 disabled:cursor-not-allowed disabled:text-ink-400">
                        {{ resendSeconds > 0 ? `Resend (${resendSeconds}s)` : 'Resend' }}
                    </button>
                </div>

                <!-- phase 3: password + authenticator, then create — hidden until the email is verified -->
                <template v-if="emailVerified">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="reg-password" class="mb-1 block text-sm font-semibold text-ink-700">Password</label>
                            <input id="reg-password" v-model="form.password" type="password" autocomplete="new-password" :class="[field, form.errors.password && 'border-danger-500']" />
                            <p v-if="form.errors.password" class="mt-1 text-xs text-on-danger">{{ form.errors.password }}</p>
                        </div>
                        <div>
                            <label for="reg-password-confirmation" class="mb-1 block text-sm font-semibold text-ink-700">Confirm</label>
                            <input id="reg-password-confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" :class="field" />
                        </div>
                    </div>
                    <PasswordMeter :password="form.password" />

                    <section class="rounded-xl border border-ink-200 p-4">
                        <h2 class="text-sm font-bold text-ink-800">Set up authenticator</h2>
                        <p class="mt-1 text-xs text-ink-500">Required before your account can be created.</p>

                        <button v-if="!mfaProvisioned" type="button" :disabled="provisioning" @click="provisionMfa"
                            class="mt-3 w-full rounded-xl bg-brand-solid px-4 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">
                            {{ provisioning ? 'Starting…' : 'Set up authenticator' }}
                        </button>
                        <p v-if="mfaError && !mfaProvisioned" class="mt-2 text-xs text-on-danger">{{ mfaError }}</p>

                        <div v-if="mfaProvisioned" class="mt-3 space-y-3">
                            <div class="flex justify-center">
                                <img v-if="qr" :src="qr" alt="Two-factor QR code" class="rounded-xl ring-1 ring-line" />
                            </div>
                            <p class="text-xs text-ink-400">Can't scan? Enter this key manually:</p>
                            <code class="block break-all rounded-lg bg-app px-3 py-2 text-sm font-semibold tracking-wider text-ink-700">{{ mfaSecret }}</code>

                            <div class="rounded-xl border-2 border-dashed border-warning-300 bg-tint-warning/50 p-3">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-bold uppercase tracking-wide text-on-warning">Save your recovery codes</p>
                                    <button type="button" @click="copyCodes" class="rounded-lg bg-card px-2 py-1 text-xs font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">Copy all</button>
                                </div>
                                <p class="mt-1 text-xs text-ink-600">Each code works once if you lose your device — they won't be shown again.</p>
                                <div class="mt-2 grid grid-cols-2 gap-2">
                                    <code v-for="c in mfaRecoveryCodes" :key="c" class="rounded-lg bg-card px-2 py-1 text-center text-xs font-semibold tracking-wider text-ink-700 ring-1 ring-line">{{ c }}</code>
                                </div>
                            </div>

                            <div v-if="!mfaConfirmed">
                                <label for="reg-auth-code" class="mb-1 block text-sm font-semibold text-ink-700">Authenticator code</label>
                                <div class="flex items-start gap-2">
                                    <input id="reg-auth-code" v-model="authCode" inputmode="numeric" autocomplete="off" maxlength="6"
                                        :class="[codeField, mfaError && 'border-danger-500']" />
                                    <button type="button" :disabled="confirmingMfa || authCode.length !== 6" @click="confirmMfaCode"
                                        class="shrink-0 rounded-xl bg-brand-solid px-3 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">
                                        {{ confirmingMfa ? 'Confirming…' : 'Confirm authenticator' }}
                                    </button>
                                </div>
                                <p v-if="mfaError" class="mt-1 text-xs text-on-danger">{{ mfaError }}</p>
                            </div>
                            <p v-else class="text-xs font-semibold text-on-success">Authenticator confirmed</p>
                        </div>
                    </section>

                    <button type="submit" :disabled="!canCreate"
                        class="w-full rounded-xl bg-brand-solid px-4 py-3 font-semibold text-white shadow-lg transition hover:bg-brand-solid-hover disabled:opacity-60">
                        {{ form.processing ? 'Creating account…' : 'Create account' }}
                    </button>
                </template>
            </form>
            <p class="mt-6 text-center text-xs text-ink-400">Already have an account? <Link href="/login" class="font-semibold text-brand-600 hover:text-brand-700">Sign in</Link></p>
        </div>
    </div>
</template>
