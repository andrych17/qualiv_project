<script setup lang="ts">
import { computed, onMounted, ref, useAttrs } from 'vue';
import { Eye, EyeOff } from 'lucide-vue-next';

defineOptions({ inheritAttrs: false });

const model = defineModel<string>({ required: true });
const input = ref<HTMLInputElement | null>(null);
const showPassword = ref(false);
const attrs = useAttrs();

const isPasswordType = computed(() => attrs.type === 'password');
const computedType = computed(() => {
    if (!isPasswordType.value) {
        return (attrs.type as string) || 'text';
    }
    return showPassword.value ? 'text' : 'password';
});

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative w-full">
        <input
            v-bind="$attrs"
            :type="computedType"
            class="w-full rounded-md border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 placeholder:text-ink-600/60 shadow-xs outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
            :class="{ 'pr-10': isPasswordType }"
            v-model="model"
            ref="input"
        />
        <button
            v-if="isPasswordType"
            type="button"
            @click="showPassword = !showPassword"
            class="absolute inset-y-0 right-0 flex items-center pr-3 text-ink-500 hover:text-ink-700 focus:outline-none cursor-pointer"
            tabindex="-1"
            :title="showPassword ? 'Sembunyikan password' : 'Lihat password'"
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
        >
            <EyeOff v-if="showPassword" class="h-4 w-4" />
            <Eye v-else class="h-4 w-4" />
        </button>
    </div>
</template>
