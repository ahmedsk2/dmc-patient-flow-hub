<script setup>
import { watch, onMounted, onUnmounted, useId } from 'vue';
import { useModalA11y } from '@/composables/useModalA11y';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { useConfirm } from '@/composables/useConfirm';

/**
 * Single modal scaffold (Wave 3, Item 2). Replaces the ~10 hand-rolled modal divs that had drifted
 * in their focus-trap wiring, backdrop, aria-modal placement, and Esc cleanup.
 *
 * Preserves the EXACT a11y semantics the pages had before:
 *   - owns ONE useModalA11y() instance (trapRef / onOpen / onClose / onKeydown);
 *   - the dialog panel carries role="dialog" aria-modal="true" aria-labelledby={unique id} and
 *     @keydown="onKeydown" so Tab/Shift+Tab wrap inside the panel;
 *   - onOpen() captures the opener + focuses inside the panel on open (fieldFirst → first input);
 *   - onClose() returns focus to the opener when the modal closes;
 *   - a window-level Escape listener (matching the page-level onEsc dispatchers it replaces — same
 *     scope, so the IcdTypeahead's first-Esc dropdown swallow still works) emits `close`, and the
 *     listener is removed on unmount.
 *
 * Visual parity: same backdrop (bg-navy-950/40 + backdrop-blur-sm, grid-centred) and same panel
 * shell (rounded-2xl bg-card p-6 shadow-2xl) the previous modals used. `size` maps to the exact
 * max-width / scroll classes those modals had; `tall` adds the max-h-[90vh] overflow-auto used by
 * the bigger forms.
 *
 * Unsaved-changes guard (Wave 3, Item 2): the OPTIONAL `dirty` prop (default false — fully
 * backward compatible; every existing caller that never passes it keeps today's exact behavior,
 * no ask() ever called). When `dirty` is true, the three built-in close triggers this component
 * owns — Escape, backdrop click, and the header X button — all funnel through the ONE internal
 * close() below, which routes through useUnsavedGuard's discard-confirm (the same useConfirm()
 * singleton every other destructive action in the app uses) before emitting `close`. Declining
 * the confirm leaves the modal open — no `close` is emitted.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    // md = max-w-md, lg = max-w-lg, xl/2xl = max-w-2xl (the three widths the old modals used);
    // wide = max-w-4xl (~75%) for large multi-column forms (e.g. the Reassign modal's preflight list)
    size: { type: String, default: 'md', validator: (v) => ['md', 'lg', 'xl', '2xl', 'wide'].includes(v) },
    // big forms scrolled inside a capped height; toolbars/short modals did not
    tall: { type: Boolean, default: false },
    // clinical-action modals focused the first FIELD (not a button) on open — match that
    fieldFirst: { type: Boolean, default: false },
    // some modals omit the header close-X (toolbar-style); default shows it
    closable: { type: Boolean, default: true },
    // OPTIONAL unsaved-changes guard — see the class-doc comment above. Default false = unchanged.
    dirty: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const { trapRef, onOpen, onClose, onKeydown } = useModalA11y();
const { ask } = useConfirm();
const { guardedClose } = useUnsavedGuard(() => props.dirty, ask);
const titleId = `modal-title-${useId()}`;

const sizeClass = { md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-2xl', '2xl': 'max-w-2xl', wide: 'max-w-4xl' };

watch(
    () => props.open,
    (v, prev) => {
        if (v) onOpen(undefined, { fieldFirst: props.fieldFirst });
        else if (prev) onClose();
    },
);

// dirty=false (the default): emits close synchronously, exactly as before this guard existed.
// dirty=true: guardedClose() awaits the discard-confirm and only emits close on "yes, discard".
function close() { guardedClose(() => emit('close')); }

// Esc — owned once here, scoped to window like the page dispatchers it replaces, cleaned up on unmount.
function onKey(e) { if (e.key === 'Escape' && props.open) close(); }
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="close">
        <div
            ref="trapRef"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            @keydown="onKeydown"
            :class="['w-full rounded-2xl bg-card p-6 shadow-2xl', sizeClass[size], tall && 'max-h-[90vh] overflow-auto']"
        >
            <div :class="['flex items-start justify-between', subtitle ? 'mb-4' : 'mb-4 items-center']">
                <div>
                    <h3 :id="titleId" class="text-lg font-bold text-ink-900">{{ title }}</h3>
                    <!-- Wave 1 (EHC UI): callers may replace the plain-text subtitle with richer
                         header content (e.g. the IdentityChip tuple); the string prop remains the
                         default so every existing modal renders exactly as before -->
                    <slot name="subtitle">
                        <p v-if="subtitle" class="text-sm text-ink-400">{{ subtitle }}</p>
                    </slot>
                </div>
                <button v-if="closable" type="button" @click="close" aria-label="Close" class="text-ink-400 hover:text-ink-700">✕</button>
            </div>
            <slot />
        </div>
    </div>
</template>
