<!-- ponytail: Simple pagination component for Inertia links matching Laravel standard link structure -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  links: Array<{
    url: string | null
    label: string
    active: boolean
  }>
}>()
</script>

<template>
  <div v-if="links.length > 3" class="flex items-center justify-between border-t border-border bg-surface-0 px-4 py-3 sm:px-6 rounded-lg shadow-2xs">
    <div class="flex flex-1 justify-between sm:hidden">
      <!-- Simple Mobile Pagination -->
      <Link
        :href="links[0].url || '#'"
        :class="[!links[0].url ? 'pointer-events-none opacity-50' : '']"
        class="relative inline-flex items-center rounded-md border border-border bg-surface-0 px-4 py-2 text-sm font-medium text-ink-900 shadow-xs hover:bg-surface-50"
      >
        Previous
      </Link>
      <Link
        :href="links[links.length - 1].url || '#'"
        :class="[!links[links.length - 1].url ? 'pointer-events-none opacity-50' : '']"
        class="relative ml-3 inline-flex items-center rounded-md border border-border bg-surface-0 px-4 py-2 text-sm font-medium text-ink-900 shadow-xs hover:bg-surface-50"
      >
        Next
      </Link>
    </div>
    
    <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
      <div>
        <p class="text-sm text-ink-600">
          Showing pagination pages
        </p>
      </div>
      <div>
        <nav class="isolate inline-flex -space-x-px rounded-md shadow-xs" aria-label="Pagination">
          <Link
            v-for="(link, idx) in links"
            :key="idx"
            :href="link.url || '#'"
            class="relative inline-flex items-center px-4 py-2 text-sm font-semibold focus:z-10 border transition-colors"
            :class="[
              link.active 
                ? 'z-10 bg-accent text-accent-text focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent border-accent' 
                : 'text-ink-900 bg-surface-0 border-border hover:bg-surface-50 focus:outline-offset-0',
              !link.url ? 'pointer-events-none opacity-50' : ''
            ]"
            v-html="link.label"
          />
        </nav>
      </div>
    </div>
  </div>
</template>
