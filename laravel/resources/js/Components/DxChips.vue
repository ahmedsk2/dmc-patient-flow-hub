<script setup>
/**
 * Diagnosis chip list (Wave 3, Item 5). The removable chip row used by every Modify/edit form
 * (Patients, Admissions, Registry) and by the admission Create page — previously copy-pasted as
 * `<span class="… bg-brand-100 …">{{ code }} {{ name }} <button>✕</button></span>` in each.
 *
 * Rendering is byte-for-byte the legacy chip row so appearance is unchanged. Pass `removable` to
 * show the ✕ buttons (edit forms); omit it for a read-only chip list.
 */
defineProps({
    // [{ code, name }]
    diagnoses: { type: Array, default: () => [] },
    removable: { type: Boolean, default: false },
});
defineEmits(['remove']);
</script>

<template>
    <div v-if="diagnoses.length" class="mt-2 flex flex-wrap gap-1.5">
        <span v-for="d in diagnoses" :key="d.code" class="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2.5 py-1 text-xs font-semibold text-brand-700">
            <span class="nums">{{ d.code }}</span> {{ d.name }}
            <button v-if="removable" type="button" @click="$emit('remove', d.code)" class="text-brand-500 hover:text-danger-600">✕</button>
        </span>
    </div>
</template>
