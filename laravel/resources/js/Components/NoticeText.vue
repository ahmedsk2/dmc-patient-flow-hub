<script setup>
import { computed } from 'vue';

// Renders one run of notice text. Square-bracketed review markers ([VERIFY ARTICLE], [PLACEHOLDER],
// ...) are part of the DRAFT wording that comes from resources/lang/{en,ar}/privacy.php; they are
// split out here so each one shows as a highlighted mark that a reviewer cannot miss. Plain text
// otherwise — no HTML is ever injected.
const props = defineProps({
    text: { type: String, default: '' },
    markerLabel: { type: String, default: 'Open review item' },
});

const segments = computed(() => props.text
    .split(/(\[[^\]]+\])/)
    .filter((s) => s.length > 0)
    .map((s) => ({ text: s, marker: /^\[[^\]]+\]$/.test(s) })));
</script>

<template>
    <span><template v-for="(seg, i) in segments" :key="i"><mark v-if="seg.marker" class="rounded bg-tint-warning px-1 font-semibold text-on-warning" :title="markerLabel">{{ seg.text }}</mark><template v-else>{{ seg.text }}</template></template></span>
</template>
