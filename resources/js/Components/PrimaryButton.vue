<!-- ponytail: Primary action button — renders an Inertia Link when `href` is set,
     a <button> otherwise. Single visual identity for both cases so pages never
     hand-roll the accent-button classes. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

withDefaults(
  defineProps<{
    type?: 'button' | 'submit' | 'reset'
    disabled?: boolean
    href?: string
  }>(),
  {
    type: 'submit',
    disabled: false,
  },
)

const classes =
  'inline-flex items-center justify-center rounded-sm bg-accent px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-accent/90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:opacity-50'
</script>

<template>
  <Link v-if="href" :href="href" :class="classes">
    <slot />
  </Link>
  <button v-else :type="type" :disabled="disabled" :class="classes">
    <slot />
  </button>
</template>
