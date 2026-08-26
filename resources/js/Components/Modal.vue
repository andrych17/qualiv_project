<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

// Global reactive stack tracker to handle nested/stacked modals cleanly (double/triple dialogs)
let globalModalIdCounter = 0;
const activeModalStack = ref<number[]>([]);

const props = withDefaults(
    defineProps<{
        show?: boolean;
        maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
        closeable?: boolean;
    }>(),
    {
        show: false,
        maxWidth: '2xl',
        closeable: true,
    },
);

const emit = defineEmits(['close']);
const dialog = ref<HTMLDialogElement | null>(null);
const showSlot = ref(props.show);

// Unique ID for this modal instance
const modalId = ++globalModalIdCounter;

const stackIndex = computed(() => activeModalStack.value.indexOf(modalId));
const isTopModal = computed(() => {
    const len = activeModalStack.value.length;
    return len > 0 && activeModalStack.value[len - 1] === modalId;
});

// Dynamic z-index so nested dialogs always stack above parent dialogs cleanly
const zIndex = computed(() => {
    const base = 50;
    const index = stackIndex.value >= 0 ? stackIndex.value : 0;
    return base + index * 10;
});

watch(
    () => props.show,
    (isShowing) => {
        if (isShowing) {
            if (!activeModalStack.value.includes(modalId)) {
                activeModalStack.value.push(modalId);
            }
            document.body.style.overflow = 'hidden';
            showSlot.value = true;
            dialog.value?.showModal();
        } else {
            const idx = activeModalStack.value.indexOf(modalId);
            if (idx !== -1) {
                activeModalStack.value.splice(idx, 1);
            }
            // Only release body scroll when ALL modals in stack are closed
            if (activeModalStack.value.length === 0) {
                document.body.style.overflow = '';
            }

            setTimeout(() => {
                dialog.value?.close();
                showSlot.value = false;
            }, 200);
        }
    },
    { immediate: true },
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

// Escape key only closes the TOP-MOST modal in the stack
const closeOnEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && props.show && isTopModal.value) {
        e.preventDefault();
        e.stopPropagation();
        close();
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));

onUnmounted(() => {
    const idx = activeModalStack.value.indexOf(modalId);
    if (idx !== -1) {
        activeModalStack.value.splice(idx, 1);
    }
    if (activeModalStack.value.length === 0) {
        document.body.style.overflow = '';
    }
    document.removeEventListener('keydown', closeOnEscape);
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[props.maxWidth];
});
</script>

<template>
    <dialog
        class="m-0 min-h-full min-w-full overflow-y-auto bg-transparent p-0 backdrop:bg-transparent"
        :style="{ zIndex }"
        ref="dialog"
    >
        <div
            class="fixed inset-0 overflow-y-auto"
            :style="{ zIndex }"
            scroll-region
        >
            <!-- Backdrop Transition with subtle stacking opacity -->
            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-show="show"
                    class="fixed inset-0 transition-opacity"
                    :class="[
                        stackIndex > 0
                            ? 'bg-ink-900/30 backdrop-blur-[1px]'
                            : 'bg-ink-900/50 backdrop-blur-[2px]',
                    ]"
                    aria-hidden="true"
                    @click="close"
                />
            </Transition>

            <!-- Centering Flexbox Wrapper -->
            <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-6">
                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <div
                        v-show="show"
                        class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full"
                        :class="maxWidthClass"
                    >
                        <slot v-if="showSlot" />
                    </div>
                </Transition>
            </div>
        </div>
    </dialog>
</template>
