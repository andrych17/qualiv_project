<!-- ponytail: Standard actionable Empty State component.
     Follows DESIGN.md §3: "always actionable, never just 'no data'".
     Composes with design tokens (ink-900, ink-600, surface-50, border). -->
<script setup lang="ts">
import type { Component } from 'vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { FolderSearch } from 'lucide-vue-next'

withDefaults(
  defineProps<{
    title: string
    description?: string
    icon?: Component
    actionLabel?: string
    actionHref?: string
  }>(),
  {
    description: undefined,
    icon: undefined,
    actionLabel: undefined,
    actionHref: undefined,
  },
)

const emit = defineEmits<{
  (e: 'action'): void
}>()
</script>

<template>
  <div class="flex flex-col items-center justify-center py-12 px-4 text-center">
    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-surface-50 border border-border text-ink-600 mb-4">
      <component :is="icon || FolderSearch" class="h-6 w-6" />
    </div>

    <h3 class="text-base font-semibold text-ink-900">
      {{ title }}
    </h3>

    <p v-if="description" class="mt-1.5 max-w-sm text-sm text-ink-600">
      {{ description }}
    </p>

    <div v-if="actionLabel || $slots.action" class="mt-5">
      <slot name="action">
        <PrimaryButton
          v-if="actionHref"
          :href="actionHref"
        >
          {{ actionLabel }}
        </PrimaryButton>
        <PrimaryButton
          v-else-if="actionLabel"
          type="button"
          @click="emit('action')"
        >
          {{ actionLabel }}
        </PrimaryButton>
      </slot>
    </div>
  </div>
</template>
