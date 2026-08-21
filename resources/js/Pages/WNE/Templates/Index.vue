<!-- ponytail: WNE §3L — template list + coverage warnings. Client-side filter/sort is fine
     here (template counts stay small — one row per category×channel×locale), same choice
     already made for Workflows/Index.vue. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'

type TemplateRow = {
  id: number
  category_code: string
  channel: string
  locale: string
  subject: string | null
  is_active: boolean
}

const props = defineProps<{
  templates: TemplateRow[]
  coverageWarnings: Array<{ category: string; missing_channels: string[] }>
}>()

const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'category_code', label: 'Category', sortable: true },
  { key: 'channel', label: 'Channel', sortable: true },
  { key: 'locale', label: 'Locale' },
  { key: 'subject', label: 'Subject' },
  { key: 'is_active', label: 'Status' },
]

const filtered = computed(() => {
  let list = props.templates
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((t) => t.category_code.toLowerCase().includes(q) || t.channel.toLowerCase().includes(q))
  }
  if (sort.value) {
    const { key, direction } = sort.value
    list = [...list].sort((a, b) => {
      const av = String((a as unknown as Record<string, unknown>)[key] ?? '')
      const bv = String((b as unknown as Record<string, unknown>)[key] ?? '')
      return direction === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av)
    })
  }
  return list
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Templates" description="Subject/body per category × channel × locale (WNE §3L).">
      <template #actions>
        <PrimaryButton :href="route('wne.templates.create')">New template</PrimaryButton>
      </template>
    </PageHeader>

    <WneSubNav active="templates" class="mt-6" />

    <div v-if="coverageWarnings.length" class="mt-6 space-y-2">
      <div
        v-for="w in coverageWarnings"
        :key="w.category"
        class="rounded-md border border-signal-warning/25 bg-signal-warning/10 px-4 py-3 text-sm text-ink-900"
      >
        <strong>{{ w.category }}</strong> has no active template for: {{ w.missing_channels.join(', ') }}
      </div>
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filtered"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search category or channel…"
        empty-title="No templates yet"
        empty-description="Create a template so a category's notifications render more than the generic fallback text."
      >
        <template #cell-category_code="{ item }">
          <Link :href="route('wne.templates.edit', item.id)" class="text-sm font-medium text-accent hover:underline">
            {{ item.category_code }}
          </Link>
        </template>
        <template #cell-subject="{ item }">
          <span class="text-sm text-ink-600">{{ (item as TemplateRow).subject ?? '—' }}</span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as TemplateRow).is_active ? 'active' : 'inactive'" />
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
