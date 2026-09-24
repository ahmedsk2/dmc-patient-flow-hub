<script setup>
import { ref, computed, useId } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import IcdTypeahead from '@/Components/IcdTypeahead.vue';
import InfoTip from '@/Components/InfoTip.vue';
import FlowAlert from '@/Components/FlowAlert.vue';
import { localToday, xsrf } from '@/lib/ui.js';

defineProps({ consultants: Array, countries: Array, locations: Array, admitFrom: Array });

const today = localToday();
// Accessible names: pair each <label for> with its control id (UX-04). Same fid() idiom as PatientForm.
const uid = useId();
const fid = (name) => `admit-${uid}-${name}`;
const form = useForm({
    mrn: '', name: '', age: '', gender: '', nationality: '',
    bed: '', admit_date: today, admitted_from: 'ER', current_location: 'Ward',
    consultant_id: '', diagnoses: [], confirm_identity_update: false,
});

// ICD-10 async picker
const selectedDx = ref([]);
const addDx = (d) => {
    if (!selectedDx.value.find((x) => x.code === d.code)) { selectedDx.value.push(d); form.diagnoses.push(d.code); }
};
const removeDx = (code) => {
    selectedDx.value = selectedDx.value.filter((x) => x.code !== code);
    form.diagnoses = form.diagnoses.filter((c) => c !== code);
};

// Role/UX review 2026-09-24, Problem #1: MRN lookup — before this readmits a known patient, tell
// the clinician who that MRN belongs to (prefilling the stored demographics) and whether they
// already have an active episode, instead of letting a mistyped detail silently rename them for
// every past and future visit (AdmissionsController::createAdmission()). POST /admissions/lookup-mrn,
// MRN in the body — never the URL (SPC-TM-011).
const mrnStatus = ref('idle');   // idle | loading | found | not_found | error
const lookedUpPatient = ref(null);
const hasActiveEpisode = ref(false);
let mrnSeq = 0;
let lastCheckedMrn = null;
// Review fix-up (2026-09-24): the focus-out handler and submit()'s own `await lookupMrn()` call can
// race — leaving the field kicks off a fetch, then a fast Enter/click submit used to see the MRN already
// claimed by `lastCheckedMrn` and return immediately, without waiting for that fetch to settle, so
// the confirm-identity checkbox could miss its first render. Track the in-flight promise so a
// concurrent caller awaits the SAME request instead of short-circuiting on it.
let inFlightMrn = null;
let inFlightPromise = null;
const lookupMrn = () => {
    const mrn = form.mrn.trim();
    if (mrn === lastCheckedMrn) {
        // already checked (or being checked) this exact MRN — join the in-flight request if one
        // is still running, otherwise there's nothing new to wait for
        return mrn === inFlightMrn ? inFlightPromise : Promise.resolve();
    }
    lastCheckedMrn = mrn;
    lookedUpPatient.value = null;
    hasActiveEpisode.value = false;
    if (!/^\d{1,11}$/.test(mrn)) { mrnStatus.value = 'idle'; return Promise.resolve(); }
    const mine = ++mrnSeq;
    mrnStatus.value = 'loading';
    inFlightMrn = mrn;
    inFlightPromise = (async () => {
        try {
            const r = await fetch('/admissions/lookup-mrn', {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify({ mrn }),
            });
            if (mine !== mrnSeq) return;
            if (!r.ok) { mrnStatus.value = 'error'; return; }
            const body = await r.json();
            if (body.found) {
                lookedUpPatient.value = body.patient;
                hasActiveEpisode.value = !!body.has_active_episode;
                mrnStatus.value = 'found';
                // prefill from the stored record — the confirm-identity checkbox below only appears
                // if the clinician then edits one of these away from what was just filled in
                form.name = body.patient.name ?? form.name;
                form.age = body.patient.age ?? form.age;
                form.gender = body.patient.gender ?? form.gender;
                form.nationality = body.patient.nationality ?? form.nationality;
            } else {
                mrnStatus.value = 'not_found';
            }
        } catch {
            if (mine === mrnSeq) mrnStatus.value = 'error';
        } finally {
            if (inFlightMrn === mrn) { inFlightMrn = null; inFlightPromise = null; }
        }
    })();
    return inFlightPromise;
};

const identityChanged = computed(() => {
    const p = lookedUpPatient.value;
    if (!p) return false;
    return form.name.trim() !== (p.name || '').trim()
        || String(form.age ?? '').trim() !== String(p.age ?? '')
        || (form.gender || '') !== (p.gender || '')
        || (form.nationality || '') !== (p.nationality || '');
});

const submit = async () => {
    // Catches Enter-key submits that skip the MRN field's focus-out event, AND joins an
    // already-in-flight focus-out lookup instead of racing past it (see lookupMrn() above).
    await lookupMrn();
    form.post('/admissions');
};
const field = 'w-full rounded-xl border border-ink-200 bg-card px-3.5 py-2.5 text-sm text-ink-800 outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
</script>

<template>
    <AppLayout title="New Admission">
        <form @submit.prevent="submit" class="mx-auto max-w-4xl space-y-6">
            <!-- Patient -->
            <section class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-brand-700">
                    <span class="grid h-6 w-6 place-items-center rounded-lg bg-brand-100 text-brand-700">1</span> Patient
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :for="fid('mrn')" class="mb-1 flex items-center gap-1.5 text-sm font-semibold text-ink-700">MRN <span class="text-danger-500">*</span>
                            <InfoTip label="MRN" text="If this MRN belongs to a known patient, their stored details fill in below — confirm before any change to them is saved." />
                        </label>
                        <input :id="fid('mrn')" v-model="form.mrn" @blur="lookupMrn" :aria-describedby="form.errors.mrn ? fid('mrn') + '-err' : undefined" :class="[field, form.errors.mrn && 'border-danger-500']" placeholder="Medical record number" inputmode="numeric" />
                        <p v-if="form.errors.mrn" :id="fid('mrn') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.mrn }}</p>
                        <p v-if="mrnStatus === 'loading'" class="mt-1 text-xs text-ink-400">Checking MRN…</p>
                        <p v-else-if="mrnStatus === 'found' && !hasActiveEpisode" class="mt-1 text-xs font-semibold text-brand-700">Existing patient — details filled from their record. Changing them updates this patient for all visits.</p>
                        <p v-else-if="mrnStatus === 'found' && hasActiveEpisode" class="mt-1 text-xs font-semibold text-on-danger">This patient already has an active admission — submitting will be rejected until that episode is discharged.</p>
                    </div>
                    <div>
                        <label :for="fid('name')" class="mb-1 block text-sm font-semibold text-ink-700">Full name <span class="text-danger-500">*</span></label>
                        <input :id="fid('name')" v-model="form.name" :aria-describedby="form.errors.name ? fid('name') + '-err' : undefined" :class="[field, form.errors.name && 'border-danger-500']" placeholder="Patient name" />
                        <p v-if="form.errors.name" :id="fid('name') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.name }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label :for="fid('age')" class="mb-1 block text-sm font-semibold text-ink-700">Age <span class="text-danger-500">*</span></label>
                            <input :id="fid('age')" v-model="form.age" :aria-describedby="form.errors.age ? fid('age') + '-err' : undefined" :class="[field, form.errors.age && 'border-danger-500']" inputmode="numeric" placeholder="Years" />
                            <p v-if="form.errors.age" :id="fid('age') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.age }}</p>
                        </div>
                        <div>
                            <label :for="fid('gender')" class="mb-1 block text-sm font-semibold text-ink-700">Gender <span class="text-danger-500">*</span></label>
                            <select :id="fid('gender')" v-model="form.gender" :aria-describedby="form.errors.gender ? fid('gender') + '-err' : undefined" :class="[field, form.errors.gender && 'border-danger-500']"><option value="">—</option><option>Male</option><option>Female</option></select>
                            <p v-if="form.errors.gender" :id="fid('gender') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.gender }}</p>
                        </div>
                    </div>
                    <div>
                        <label :for="fid('nationality')" class="mb-1 block text-sm font-semibold text-ink-700">Nationality <span class="text-danger-500">*</span></label>
                        <select :id="fid('nationality')" v-model="form.nationality" :aria-describedby="form.errors.nationality ? fid('nationality') + '-err' : undefined" :class="[field, form.errors.nationality && 'border-danger-500']">
                            <option value="">Select country…</option>
                            <option v-for="c in countries" :key="c">{{ c }}</option>
                        </select>
                        <p v-if="form.errors.nationality" :id="fid('nationality') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.nationality }}</p>
                    </div>
                </div>
                <FlowAlert v-if="identityChanged || form.errors.confirm_identity_update" tone="warning" title="This changes an existing patient's record" class="mt-4">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" v-model="form.confirm_identity_update" class="mt-0.5 rounded text-brand-700" />
                        <span>MRN {{ form.mrn }} already belongs to {{ lookedUpPatient?.name || 'a known patient' }}. Confirm you want to update their stored details — this changes what's shown for every one of their visits, past and future.</span>
                    </label>
                    <p v-if="form.errors.confirm_identity_update" class="mt-1.5 font-semibold">{{ form.errors.confirm_identity_update }}</p>
                </FlowAlert>
            </section>

            <!-- Admission -->
            <section class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-brand-700">
                    <span class="grid h-6 w-6 place-items-center rounded-lg bg-brand-100 text-brand-700">2</span> Admission
                </h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label :for="fid('admit_date')" class="mb-1 block text-sm font-semibold text-ink-700">Admit date <span class="text-danger-500">*</span></label>
                        <input :id="fid('admit_date')" v-model="form.admit_date" type="date" :max="today" :aria-describedby="form.errors.admit_date ? fid('admit_date') + '-err' : undefined" :class="[field, form.errors.admit_date && 'border-danger-500']" />
                        <p v-if="form.errors.admit_date" :id="fid('admit_date') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.admit_date }}</p>
                    </div>
                    <div>
                        <label :for="fid('admitted_from')" class="mb-1 block text-sm font-semibold text-ink-700">Admitted from</label>
                        <select :id="fid('admitted_from')" v-model="form.admitted_from" :class="field"><option v-for="a in admitFrom" :key="a">{{ a }}</option></select>
                    </div>
                    <div>
                        <label :for="fid('current_location')" class="mb-1 block text-sm font-semibold text-ink-700">Location <span class="text-danger-500">*</span></label>
                        <select :id="fid('current_location')" v-model="form.current_location" :class="field"><option v-for="l in locations" :key="l">{{ l }}</option></select>
                    </div>
                    <div>
                        <label :for="fid('bed')" class="mb-1 block text-sm font-semibold text-ink-700">Bed <span class="text-danger-500">*</span></label>
                        <input :id="fid('bed')" v-model="form.bed" :aria-describedby="form.errors.bed ? fid('bed') + '-err' : undefined" :class="[field, form.errors.bed && 'border-danger-500']" placeholder="Bed / room" />
                        <p v-if="form.errors.bed" :id="fid('bed') + '-err'" class="mt-1 text-xs text-on-danger">{{ form.errors.bed }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label :for="fid('consultant_id')" class="mb-1 block text-sm font-semibold text-ink-700">Consultant</label>
                        <select :id="fid('consultant_id')" v-model="form.consultant_id" :class="field">
                            <option value="">Leave unassigned (assignment queue)</option>
                            <option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- Diagnoses -->
            <section class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-brand-700">
                    <span class="grid h-6 w-6 place-items-center rounded-lg bg-brand-100 text-brand-700">3</span> Admission diagnosis (ICD-10) <span class="text-danger-500">*</span>
                </h2>
                <IcdTypeahead :input-class="field" placeholder="Type a code or description (≥2 chars)…" @select="addDx" />
                <div v-if="selectedDx.length" class="mt-3 flex flex-wrap gap-2">
                    <span v-for="d in selectedDx" :key="d.code" class="inline-flex items-center gap-1.5 rounded-full bg-brand-100 px-3 py-1 text-xs font-semibold text-brand-700">
                        <span class="nums">{{ d.code }}</span> {{ d.name }}
                        <button type="button" @click="removeDx(d.code)" class="text-brand-700 hover:text-on-danger">✕</button>
                    </span>
                </div>
                <p v-else class="mt-3 text-sm text-ink-400">No diagnoses added yet — at least one is required.</p>
                <p v-if="form.errors.diagnoses" class="mt-2 text-xs text-on-danger">{{ form.errors.diagnoses }}</p>
            </section>

            <div class="flex items-center justify-end gap-3">
                <a href="/patients" class="rounded-xl px-5 py-2.5 text-sm font-semibold text-ink-500 hover:text-ink-700">Cancel</a>
                <button type="submit" :disabled="form.processing"
                    class="rounded-xl bg-gradient-to-r from-brand-500 to-brand-700 px-6 py-2.5 font-semibold text-white shadow-lg shadow-brand-900/20 transition hover:from-brand-600 hover:to-brand-800 disabled:opacity-60">
                    {{ form.processing ? 'Admitting…' : 'Admit patient' }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
