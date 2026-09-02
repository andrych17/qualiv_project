<!-- ponytail: Routing listing (MES_SPECS.md §3E) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface RoutingRow {
  id: number
  product_sku: string | null
  product_name: string | null
  version: number
  op_count: number
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
  routings: PaginatedData<RoutingRow>
  filters: { search?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.routings.per_page)

const columns = [
  { key: 'product_sku', label: 'SKU' },
  { key: 'product_name', label: 'Product' },
  { key: 'version', label: 'Version', sortable: true },
  { key: 'op_count', label: 'Operations', align: 'right' as const },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('mes.routings.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: RoutingRow | Record<string, unknown>) => {
  const row = item as RoutingRow
  confirm({
    title: `Delete routing for ${row.product_sku} v${row.version}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('mes.routings.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected routing(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('mes.routings.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Routings"
      description="Discrete Routing / Operations — the execution-step sequence for a product. Only one active version per product."
    >
      <template #actions>
        <PrimaryButton :href="route('mes.routings.create')">Add Routing</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="routings.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="mes.routings"
        search-placeholder="Search SKU or name…"
        export-filename="mes-routings"
        :total="routings.total"
        :from="routings.from"
        :to="routings.to"
        :links="routings.links"
        empty-title="No routings yet"
        empty-description="Add a routing to sequence the execution steps for a discrete product."
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
        <template #cell-product_sku="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ (item as RoutingRow).product_sku }}</span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as RoutingRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('mes.routings.edit', item.id)"
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
