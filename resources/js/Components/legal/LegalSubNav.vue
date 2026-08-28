<!-- ponytail: Matters/Deeds/Protocol switcher — one LEGAL menu row covers all Legal routes,
     same second-level nav pattern as CrmSubNav. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'matters' | 'deeds' | 'ppat-deeds' | 'land-objects' | 'protocol-books' | 'field-visits'
}>()
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border overflow-x-auto scrollbar-none" role="tablist">
    <Link
      :href="route('legal.matters.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'matters' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Matters
    </Link>
    <Link
      :href="route('legal.deeds.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'deeds' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Deeds
    </Link>
    <Link
      :href="route('legal.ppatDeeds.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'ppat-deeds' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      PPAT Deeds
    </Link>
    <Link
      :href="route('legal.landObjects.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'land-objects' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Land Objects
    </Link>
    <Link
      :href="route('legal.protocolBooks.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'protocol-books' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Protocol
    </Link>
    <Link
      :href="route('legal.fieldVisits.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'field-visits' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Field Visits
    </Link>
  </div>
</template>
