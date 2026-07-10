import { ref, toValue } from 'vue';

/**
 * useTabs — selection + keyboard model for the ARIA tabs pattern (Wave 3; consumed by Tabs.vue).
 *
 * `tabIds` may be a plain array, a ref, or a computed of ids (toValue reads all three), so a
 * caller whose tab set changes at runtime always navigates the CURRENT list, never a snapshot
 * taken at setup time.
 *
 * Keyboard model — W3C APG "automatic activation", the simpler of the two APG variants: the
 * arrow keys MOVE SELECTION directly (wrapping at both ends) and Home/End jump to the first/last
 * id. Activation follows focus, so there is no separate Enter/Space step. The DOM half of the
 * roving-tabindex pattern belongs to the component: onKeydown() returns the id selection moved
 * to (or null when the key was not ours) so the caller can put real focus on that tab's element.
 * Unhandled keys are left untouched — Tab itself must keep leaving the tablist, and the vertical
 * arrows are not part of a horizontal tablist's model.
 *
 * RTL simplification (deliberate, documented): ArrowRight always means NEXT IN DOM ORDER. The
 * app lays tabs out with logical properties, so a future RTL mode mirrors the strip visually
 * while DOM order stays the reading order; we do not swap the arrow keys per text direction the
 * way the full APG example does.
 */
export function useTabs(tabIds, { initial } = {}) {
    const ids = () => toValue(tabIds) ?? [];

    const list = ids();
    const active = ref(initial != null && list.includes(initial) ? initial : (list[0] ?? null));

    // Unknown ids are ignored, so a stale caller (e.g. a v-model echoing an id that has since
    // been removed) can never select a tab that does not exist.
    function select(id) {
        if (ids().includes(id)) active.value = id;
    }

    // Returns the id selection moved to, or null when the event is not part of the tabs model.
    // An empty list is a safe no-op. If `active` is somehow no longer in the list, its index
    // coerces to the first slot so navigation stays anchored instead of throwing.
    function onKeydown(event) {
        const l = ids();
        if (!l.length) return null;
        const i = Math.max(0, l.indexOf(active.value));
        let next;
        if (event.key === 'ArrowRight') next = l[(i + 1) % l.length];
        else if (event.key === 'ArrowLeft') next = l[(i - 1 + l.length) % l.length];
        else if (event.key === 'Home') next = l[0];
        else if (event.key === 'End') next = l[l.length - 1];
        else return null;
        event.preventDefault(); // the tablist owns these keys; stop the page from scrolling
        select(next);
        return next;
    }

    return { active, select, onKeydown };
}
