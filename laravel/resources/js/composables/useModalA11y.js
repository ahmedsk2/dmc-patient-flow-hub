import { ref, nextTick } from 'vue';

// Attribute-layer focus management for the existing modal markup (no restructuring needed).
// Returns { trapRef, onOpen, onClose, onKeydown }:
//   - bind ref="trapRef" to the modal's focusable panel (the white sheet, NOT the backdrop)
//   - onOpen(openerEl, { fieldFirst }): remember the opener, focus inside the panel. By default
//       focus lands on the first focusable element; with { fieldFirst: true } it skips buttons/
//       links and focuses the first input/select/textarea instead (clinical-action modals — so the
//       user types immediately rather than landing on a close/cancel button). Item 6.
//   - onClose(): return focus to the remembered opener
//   - onKeydown(e): on Tab/Shift+Tab at the trap edges, wrap focus to keep it inside the panel
// Use ONE instance per logical modal slot (e.g. action / reassign / handover / modify).
export function useModalA11y() {
    const trapRef = ref(null);
    let opener = null;
    const focusableSelectors = 'button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href],[tabindex]:not([tabindex="-1"])';
    const getFocusable = () => [...(trapRef.value?.querySelectorAll(focusableSelectors) ?? [])];

    const onOpen = (el, { fieldFirst = false } = {}) => {
        opener = el ?? document.activeElement;
        nextTick(() => {
            const items = getFocusable();
            if (fieldFirst) {
                const field = items.find((i) => ['INPUT', 'SELECT', 'TEXTAREA'].includes(i.tagName));
                (field ?? items[0])?.focus();
            } else {
                items[0]?.focus();
            }
        });
    };
    const onClose = () => { const el = opener; opener = null; el?.focus?.(); };
    const onKeydown = (e) => {
        if (e.key !== 'Tab') return;
        const items = getFocusable();
        if (!items.length) return;
        const first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    return { trapRef, onOpen, onClose, onKeydown };
}
