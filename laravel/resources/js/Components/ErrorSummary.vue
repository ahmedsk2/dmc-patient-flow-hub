<script setup>
import { computed } from 'vue';

/**
 * ErrorSummary — an NHS/GOV.UK-style error summary (Wave 3, Item 4). Sits at the TOP of a modal or
 * form and lists every field-level validation error as a focus-jump link, so a screen-reader or
 * keyboard user lands directly on the offending field instead of hunting through the form. This is
 * ADDITIVE — the existing per-field messages stay too.
 *
 * `errors` is a plain { fieldId: message } map keyed by the target input's DOM `id` (which is not
 * necessarily the same string as the form-data key — callers map their form.errors keys to input
 * ids before passing them in). Renders nothing when there are no errors.
 */
const props = defineProps({
    errors: { type: Object, default: () => ({}) },
    title: { type: String, default: 'There is a problem' },
});

const entries = computed(() => Object.entries(props.errors || {}));
const hasErrors = computed(() => entries.value.length > 0);

// @click.prevent stops the anchor's default navigation (no real target document); moving focus is
// the actual jump. scrollIntoView first so a field below the fold is visible before it gets focus.
function focusField(fieldId) {
    const el = document.getElementById(fieldId);
    if (!el) return;
    el.scrollIntoView?.({ block: 'center' });
    el.focus();
}
</script>

<template>
    <div v-if="hasErrors" role="alert" tabindex="-1" class="rounded-2xl border-2 border-danger-500 bg-tint-danger p-4">
        <h2 class="text-sm font-bold text-on-danger">{{ title }}</h2>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li v-for="[fieldId, message] in entries" :key="fieldId">
                <a :href="'#' + fieldId" class="text-sm font-semibold text-on-danger underline hover:no-underline" @click.prevent="focusField(fieldId)">{{ message }}</a>
            </li>
        </ul>
    </div>
</template>
