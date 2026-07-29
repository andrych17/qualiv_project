<script setup lang="ts">
withDefaults(
  defineProps<{
    type?: 'button' | 'submit' | 'reset'
    disabled?: boolean
  }>(),
  {
    type: 'button',
    disabled: false,
  },
)
</script>

<template>
  <button
    :type="type"
    :disabled="disabled"
    class="inline-flex items-center justify-center rounded-sm bg-signal-danger px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-signal-danger/90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-signal-danger disabled:opacity-50 disabled:cursor-not-allowed"
  >
    <slot />
  </button>
</template>

