<script setup>
/**
 * Tabs — the ARIA tablist/tab/tabpanel scaffold (Wave 3). Selection and the keyboard model live
 * in useTabs (automatic activation, wrapping arrows, Home/End); this file owns the markup and
 * the DOM-focus half of the roving-tabindex pattern.
 *
 * DUMB BY DESIGN — the slot contract: exactly ONE tabpanel is rendered, and the default scoped
 * slot receives `{ active }`. The CALLER switches its own content on that id (v-if, component
 * maps, whatever suits the page). Tabs never owns panel content, so pages keep their own state
 * and their own lazy-mount decisions.
 *
 * ARIA shape: real <button role="tab"> elements inside role="tablist". The active tab is the one
 * tab-stop (tabindex 0, the rest -1) and carries aria-controls → the panel; the panel points
 * back via aria-labelledby and is itself a tab-stop (tabindex 0) so keyboard users can reach
 * panel content that has no focusable elements of its own (W3C APG tabs example). Only the
 * ACTIVE tab has aria-controls: with a single recycled panel node, a per-tab idref would dangle
 * for every inactive tab and trip aria-valid-attr-value.
 *
 * Dirty-tab marker (badge: true): a small warning-500 dot (decorative fill, aria-hidden) plus an
 * aria-label suffix "(unsaved changes)" on that tab. Colour is never the only signal — AT gets
 * the words, sighted users get the dot — and the accessible name keeps the visible label as its
 * prefix (WCAG 2.5.3 label-in-name).
 *
 * Visuals: a hairline bar under the strip; the active tab paints a 2px brand keel over it
 * (-mb-px so the two edges overlap) with text-brand-700; inactive tabs are ink-500. Focus
 * styling is the app-wide :focus-visible treatment from app.css — the same convention
 * BaseModal's buttons rely on — so no per-element focus classes here. Motion: transition-row
 * only, the ONE sanctioned 120ms colour ease, already neutralised for reduced-motion users.
 * Every tab is min-h-10 (40px) for coarse pointers.
 */
import { computed, ref, watch, useId } from 'vue';
import { useTabs } from '@/composables/useTabs';

const props = defineProps({
    // [{ id, label, badge? }] — badge marks the tab dirty (dot + aria-label suffix).
    tabs: {
        type: Array,
        required: true,
        validator: (v) => v.every((t) => t && t.id != null && typeof t.label === 'string'),
    },
    // Optional two-way active id; omit it and Tabs manages selection internally.
    modelValue: { type: [String, Number], default: null },
});
const emit = defineEmits(['update:modelValue']);

const uid = useId();
const ids = computed(() => props.tabs.map((t) => t.id));
const { active, select, onKeydown } = useTabs(ids, { initial: props.modelValue ?? undefined });

// v-model, both directions, echo-guarded: a parent-driven change lands via select() and then
// does NOT emit back (v === modelValue by then); a user-driven change emits exactly once.
watch(() => props.modelValue, (v) => { if (v != null && v !== active.value) select(v); });
watch(active, (v) => { if (v !== props.modelValue) emit('update:modelValue', v); });

// If the active tab disappears from the tabs prop, recover to the v-model id when it (re)exists,
// else to the first tab — never strand the panel on an id that no tab can reach.
watch(ids, (l) => {
    if (l.length && !l.includes(active.value)) {
        select(l.includes(props.modelValue) ? props.modelValue : l[0]);
    }
});

const tabDomId = (id) => `${uid}-tab-${id}`;
const panelDomId = computed(() => `${uid}-panel-${active.value}`);
const ariaName = (t) => (t.badge ? `${t.label} (unsaved changes)` : undefined);

const listEl = ref(null);
// The DOM half of roving tabindex: after an arrow/Home/End move, put real focus on the tab that
// just became active (its tabindex flips to 0 in the same patch). Position lookup, not an id
// selector, so ids never need CSS escaping.
function focusTab(id) {
    const i = ids.value.indexOf(id);
    if (i < 0) return;
    listEl.value?.querySelectorAll('[role="tab"]')[i]?.focus();
}
function onListKeydown(e) {
    const next = onKeydown(e);
    if (next != null) focusTab(next);
}
</script>

<template>
    <div>
        <div ref="listEl" role="tablist" class="flex items-end gap-1 border-b border-hairline" @keydown="onListKeydown">
            <button
                v-for="t in tabs"
                :id="tabDomId(t.id)"
                :key="t.id"
                type="button"
                role="tab"
                :aria-selected="active === t.id ? 'true' : 'false'"
                :aria-controls="active === t.id ? panelDomId : undefined"
                :aria-label="ariaName(t)"
                :tabindex="active === t.id ? 0 : -1"
                class="-mb-px min-h-10 rounded-t-md border-b-2 px-3 py-2 text-sm font-medium transition-row"
                :class="active === t.id
                    ? 'border-brand-500 text-brand-700'
                    : 'border-transparent text-ink-500 hover:bg-ink-50 hover:text-ink-700'"
                @click="select(t.id)"
            >
                {{ t.label }}<span
                    v-if="t.badge"
                    data-dirty-dot
                    aria-hidden="true"
                    class="ms-1.5 inline-block h-1.5 w-1.5 rounded-full bg-warning-500 align-middle"
                />
            </button>
        </div>
        <div
            v-if="active != null"
            :id="panelDomId"
            role="tabpanel"
            :aria-labelledby="tabDomId(active)"
            tabindex="0"
            class="pt-4"
        >
            <slot :active="active" />
        </div>
    </div>
</template>
