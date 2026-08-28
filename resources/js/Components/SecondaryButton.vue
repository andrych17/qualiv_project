<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

withDefaults(
  defineProps<{
    type?: 'button' | 'submit' | 'reset'
    disabled?: boolean
    href?: string
  }>(),
  {
    type: 'button',
    disabled: false,
  },
)

const classes =
  'inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:opacity-50'
</script>

<template>
  <Link v-if="href" :href="href" :class="classes">
    <slot />
  </Link>
  <button v-else :type="type" :disabled="disabled" :class="classes">
    <slot />
  </button>
</template>
