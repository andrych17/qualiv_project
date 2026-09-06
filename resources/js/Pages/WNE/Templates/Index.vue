<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

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

const columns = computed(() => [
  { key: 'category_code', label: t('wne.category'), sortable: true },
  { key: 'channel', label: t('wne.channel'), sortable: true },
  { key: 'locale', label: t('wne.locale') },
  { key: 'subject', label: t('wne.subject') },
  { key: 'is_active', label: t('common.status') },
])

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
    <PageHeader :title="t('wne.templates')" :description="t('wne.templates_desc')">
      <template #actions>
        <PrimaryButton :href="route('wne.templates.create')">{{ t('wne.new_template') }}</PrimaryButton>
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
        :search-placeholder="t('common.search')"
        :empty-title="t('wne.empty_templates_title')"
        :empty-description="t('wne.empty_templates_desc')"
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
