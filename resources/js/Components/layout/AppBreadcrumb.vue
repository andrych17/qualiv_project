<!-- ponytail: Breadcrumbs from URL; remap section roots that have no index page -->
<script setup lang="ts">
import { computed } from 'vue'
import { usePage, Link } from '@inertiajs/vue3'
import { ChevronRight, Home } from 'lucide-vue-next'

/** Bare /config|/legal|/inventory have no page — link to first real screen. */
const SECTION_HOME: Record<string, string> = {
  config: '/config/menus',
  legal: '/legal/dashboard',
  inventory: '/inventory/products',
  crm: '/crm/dashboard',
  wne: '/wne/dashboard',
  dms: '/dms/dashboard',
  schedule: '/schedule/dashboard',
  accounting: '/accounting/accounts',
}

/** Same idea as SECTION_HOME, one level deeper — a sub-resource that's only ever reached
 * with an ID (no index page of its own), so the truncated crumb has nowhere real to land. */
const NO_INDEX_REMAP: Record<string, string> = {
  '/accounting/bank-reconciliation': '/accounting/bank-accounts',
}

/** Acronym segments that title-casing would otherwise mangle (crm → Crm). */
const LABEL_OVERRIDES: Record<string, string> = {
  crm: 'CRM',
  wne: 'WNE',
  dms: 'DMS',
}

const page = usePage()

const breadcrumbs = computed(() => {
  const path = page.url.split('?')[0]
  const segments = path.split('/').filter(Boolean)

  return segments.map((segment, index) => {
    const built = '/' + segments.slice(0, index + 1).join('/')
    // ponytail: numeric ID segments have no detail page — only edit exists
    // (e.g. /issues/2 links to GET /issues/2 which 405s; route is PUT-only).
    const href =
      segments[index + 1] === 'edit' && /^\d+$/.test(segment)
        ? `${built}/edit`
        : NO_INDEX_REMAP[built] ?? SECTION_HOME[built.slice(1)] ?? (SECTION_HOME[segment] && built === `/${segment}` ? SECTION_HOME[segment] : built)
    const label = LABEL_OVERRIDES[segment] ?? segment.replace(/[-_]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
    return { label, href, active: index === segments.length - 1 }
  })
})
</script>

<template>
  <nav class="flex items-center gap-2 text-sm text-ink-600">
    <Link
      :href="route('dashboard')"
      class="flex items-center hover:text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
    >
      <Home class="h-4 w-4" />
    </Link>

    <div v-for="crumb in breadcrumbs" :key="crumb.label + crumb.href" class="flex items-center gap-2">
      <ChevronRight class="h-4 w-4 text-ink-600/50" />
      <span v-if="crumb.active" class="font-medium text-ink-900">{{ crumb.label }}</span>
      <Link
        v-else
        :href="crumb.href"
        class="hover:text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
      >
        {{ crumb.label }}
      </Link>
    </div>
  </nav>
</template>
