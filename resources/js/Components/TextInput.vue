<script setup lang="ts">
import { onMounted, ref, useAttrs } from 'vue';
import { Eye, EyeOff } from 'lucide-vue-next';

defineOptions({ inheritAttrs: false });

const model = defineModel<string>({ required: true });
const input = ref<HTMLInputElement | null>(null);
const showPassword = ref(false);
const attrs = useAttrs();

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value?.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div v-if="attrs.type === 'password'" class="relative w-full">
        <input
            v-bind="$attrs"
            :type="showPassword ? 'text' : 'password'"
            class="w-full rounded-md border border-border bg-surface-0 pl-3 pr-10 py-2 text-sm text-ink-900 placeholder:text-ink-600/60 shadow-xs outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
            v-model="model"
            ref="input"
        />
        <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center pr-3 text-ink-400 hover:text-ink-700 cursor-pointer focus:outline-none"
            :title="showPassword ? 'Sembunyikan password' : 'Lihat password'"
            tabindex="-1"
            @click="showPassword = !showPassword"
        >
            <EyeOff v-if="showPassword" class="h-4 w-4" />
            <Eye v-else class="h-4 w-4" />
        </button>
    </div>
    <input
        v-else
        v-bind="$attrs"
        class="w-full rounded-md border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 placeholder:text-ink-600/60 shadow-xs outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
        v-model="model"
        ref="input"
    />
</template>
