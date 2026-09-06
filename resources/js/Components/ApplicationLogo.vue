<!-- ponytail: Qualiv brand logo with theme-reactive typography -->
<script setup lang="ts">
withDefaults(
  defineProps<{
    size?: 'xs' | 'sm' | 'md' | 'lg'
    iconOnly?: boolean
  }>(),
  {
    size: 'md',
    iconOnly: false,
  }
)
</script>

<template>
  <div class="inline-flex items-center gap-2.5 select-none">
    <img
      src="/logos/qualiv-logo-icon.webp"
      alt="Qualiv"
      class="shrink-0 object-contain rounded-lg shadow-xs"
      :class="{
        'h-6 w-6': size === 'xs',
        'h-8 w-8': size === 'sm',
        'h-10 w-10': size === 'md',
        'h-14 w-14': size === 'lg',
      }"
    />
    <div v-if="!iconOnly" class="flex flex-col text-left">
      <span
        class="font-bold leading-tight tracking-tight text-ink-900"
        :class="{
          'text-xs': size === 'xs',
          'text-sm': size === 'sm',
          'text-lg': size === 'md',
          'text-2xl': size === 'lg',
        }"
      >
        Qualiv<span class="text-accent">.</span>
      </span>
      <span
        class="font-semibold uppercase tracking-widest text-ink-500"
        :class="{
          'text-[8px] -mt-0.5': size === 'xs',
          'text-[9px] -mt-0.5': size === 'sm',
          'text-[10px] -mt-0.5': size === 'md',
          'text-xs mt-0.5': size === 'lg',
        }"
      >
        Enterprise Platform
      </span>
    </div>
  </div>
</template>


