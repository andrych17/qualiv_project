<script setup lang="ts">
import { onMounted, ref } from 'vue';

const model = defineModel<string>({ required: true });

const input = ref<HTMLInputElement | null>(null);

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value?.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        class="w-full rounded-md border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 placeholder:text-ink-600/60 shadow-xs outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
        v-model="model"
        ref="input"
    />
</template>
