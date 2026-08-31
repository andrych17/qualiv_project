<!-- ponytail: Resource Reference listing (PP_SPECS.md §3E) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatNumber } from '@/Utils/formatters'

interface ResourceRow {
  id: number
  type: string
  code: string
  name: string
  capacity: number | null
  uom_code: string | null
  is_active: boolean
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  total: number
  from: number | null
  to: number | null
  per_page: number
}

const props = defineProps<{
  resources: PaginatedData<ResourceRow>
  filters: { search?: string; type?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ type: props.filters.type ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.resources.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'type',
    label: 'Type',
    type: 'select',
    options: [
      { label: 'Tool', value: 'tool' },
      { label: 'Tank', value: 'tank' },
      { label: 'Utility', value: 'utility' },
      { label: 'Warehouse', value: 'warehouse' },
    ],
  },
]

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'type', label: 'Type', sortable: true },
  { key: 'capacity', label: 'Capacity', align: 'right' as const },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('pp.resources.index'), {
    search: search.value,
    type: filters.value.type,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: ResourceRow | Record<string, unknown>) => {
  const row = item as ResourceRow
  confirm({
    title: `Delete resource ${row.code}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('pp.resources.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected resource(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('pp.resources.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Resources"
      description="Resource types no other Core module owns yet — tool, tank, utility, warehouse-as-capacity. Machine/work-center identity stays in MES."
    >
      <template #actions>
        <PrimaryButton :href="route('pp.resources.create')">Add Resource</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="resources.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="pp.resources"
        search-placeholder="Search code or name…"
        :filter-fields="filterFields"
        export-filename="pp-resources"
        :total="resources.total"
        :from="resources.from"
        :to="resources.to"
        :links="resources.links"
        empty-title="No resources yet"
        empty-description="Add a tool, tank, utility, or warehouse capacity resource to reference from a resource group."
      >
        <template #bulk-actions>
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmBulkDelete"
          >
            Delete selected
          </button>
        </template>
        <template #cell-code="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ (item as ResourceRow).code }}</span>
        </template>
        <template #cell-type="{ item }">
          <span class="text-xs uppercase text-ink-600">{{ (item as ResourceRow).type }}</span>
        </template>
        <template #cell-capacity="{ item }">
          <span class="font-mono text-xs text-ink-600">
            {{ (item as ResourceRow).capacity !== null ? formatNumber((item as ResourceRow).capacity!) : '—' }}
            {{ (item as ResourceRow).uom_code ?? '' }}
          </span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as ResourceRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('pp.resources.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
