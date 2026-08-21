<!-- ponytail: WNE §3B — workflow definition list. Landing page until §3A's dashboard ships. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'

type DefinitionRow = {
  id: number
  code: string
  name: string
  category: string | null
  status: string
  published_version_no: number | null
  has_unpublished_draft: boolean
}

const props = defineProps<{ definitions: DefinitionRow[] }>()

const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'code', label: 'Code', sortable: true },
  { key: 'category', label: 'Category' },
  { key: 'status', label: 'Status' },
  { key: 'version', label: 'Version' },
]

const filtered = computed(() => {
  let list = props.definitions
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((d) => d.name.toLowerCase().includes(q) || d.code.toLowerCase().includes(q))
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
    <PageHeader
      title="Workflows"
      description="Process definitions — steps, transitions, and who does what (WNE §3B)."
    >
      <template #actions>
        <PrimaryButton :href="route('wne.workflows.create')">New workflow</PrimaryButton>
      </template>
    </PageHeader>

    <WneSubNav active="workflows" class="mt-6" />

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filtered"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search name or code…"
        status-rail-key="status"
        empty-title="No workflows yet"
        empty-description="Create your first workflow definition to get started."
      >
        <template #cell-name="{ item }">
          <Link :href="route('wne.workflows.edit', item.id)" class="text-sm font-medium text-accent hover:underline">
            {{ item.name }}
          </Link>
        </template>
        <template #cell-code="{ item }">
          <code class="text-xs text-ink-600">{{ item.code }}</code>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as DefinitionRow).status" />
        </template>
        <template #cell-version="{ item }">
          <span class="text-sm text-ink-600">
            <template v-if="(item as DefinitionRow).published_version_no">v{{ (item as DefinitionRow).published_version_no }}</template>
            <template v-else>—</template>
            <span v-if="(item as DefinitionRow).has_unpublished_draft" class="ml-1 text-signal-warning">(draft pending)</span>
          </span>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
