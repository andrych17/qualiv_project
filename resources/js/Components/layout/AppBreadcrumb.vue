<!-- ponytail: Dynamic breadcrumbs parsed from URL path with fallback -->
<script setup lang="ts">
import { computed } from 'vue'
import { usePage, Link } from '@inertiajs/vue3'
import { ChevronRight, Home } from 'lucide-vue-next'

const page = usePage()

const breadcrumbs = computed(() => {
  const path = page.url.split('?')[0]
  const segments = path.split('/').filter(Boolean)
  
  return segments.map((segment, index) => {
    const href = '/' + segments.slice(0, index + 1).join('/')
    const label = segment.replace(/[-_]/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    return { label, href, active: index === segments.length - 1 }
  })
})
</script>

<template>
  <nav class="flex items-center gap-2 text-sm text-gray-500">
    <Link :href="route('dashboard')" class="hover:text-gray-900 flex items-center">
      <Home class="h-4 w-4" />
    </Link>
    
    <div v-for="crumb in breadcrumbs" :key="crumb.href" class="flex items-center gap-2">
      <ChevronRight class="h-4 w-4 text-gray-400" />
      <span v-if="crumb.active" class="text-gray-900 font-medium">{{ crumb.label }}</span>
      <Link 
        v-else-if="crumb.href !== '/dashboard'"
        :href="crumb.href" 
        class="hover:text-gray-900"
      >
        {{ crumb.label }}
      </Link>
    </div>
  </nav>
</template>
