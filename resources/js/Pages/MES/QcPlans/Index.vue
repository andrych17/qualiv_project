<!-- ponytail: QC Inspection Plan listing (MES_SPECS.md §3L) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface PlanRow {
  id: number
  name: string
  product_sku: string | null
  product_name: string | null
  characteristic_count: number
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
  plans: PaginatedData<PlanRow>
  filters: { search?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.plans.per_page)

const columns = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'product_sku', label: 'Product' },
  { key: 'characteristic_count', label: 'Characteristics', align: 'right' as const },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  router.get(route('mes.qcPlans.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: PlanRow) => {
  confirm({
    title: `Delete inspection plan "${item.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('mes.qcPlans.destroy', item.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="QC Inspection Plans"
      description="Basic Quality (§3L Phase 1) — a named set of characteristics to sample against, optionally scoped to one product."
    >
      <template #actions>
        <PrimaryButton :href="route('mes.qcPlans.create')">Add Plan</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="plans.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:per-page="perPage"
        sticky-header
        storage-key="mes.qcPlans"
        search-placeholder="Search plan name…"
        export-filename="mes-qc-plans"
        :total="plans.total"
        :from="plans.from"
        :to="plans.to"
        :links="plans.links"
        empty-title="No inspection plans yet"
        empty-description="Add a plan to record QC samples against an order or batch phase."
      >
        <template #cell-product_sku="{ item }">
          <span class="text-xs text-ink-600">{{ (item as PlanRow).product_sku ?? 'Any product' }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('mes.qcPlans.edit', (item as PlanRow).id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item as PlanRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
