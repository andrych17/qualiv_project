<!-- ponytail: Changeover Matrix listing (PP_SPECS.md §3J) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ChangeoverMatrixRow {
  id: number
  from_label: string
  to_label: string
  resource_group_label: string
  changeover_minutes: number
  cleaning_minutes: number
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
  rows: PaginatedData<ChangeoverMatrixRow>
  filters: { resource_group_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  resourceGroupOptions: Array<{ value: number; label: string }>
}>()

const resourceGroupId = ref(props.filters.resource_group_id ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.rows.per_page)

const columns = [
  { key: 'from_label', label: 'From' },
  { key: 'to_label', label: 'To' },
  { key: 'resource_group_label', label: 'Resource Group' },
  { key: 'changeover_minutes', label: 'Changeover (min)', align: 'right' as const, sortable: true },
  { key: 'cleaning_minutes', label: 'Cleaning (min)', align: 'right' as const, sortable: true },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([resourceGroupId, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('pp.changeoverMatrix.index'), {
    resource_group_id: resourceGroupId.value || undefined,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: ChangeoverMatrixRow | Record<string, unknown>) => {
  const row = item as ChangeoverMatrixRow
  confirm({
    title: `Delete this changeover matrix row?`,
    description: `${row.from_label} → ${row.to_label}`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('pp.changeoverMatrix.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected row(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('pp.changeoverMatrix.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Setup & Changeover Matrix"
      description="Switching cost from one product/family to another on a resource group (§3J) — consumed by the minimize setup / minimize changeover dispatch strategies (§3I)."
    >
      <template #actions>
        <PrimaryButton :href="route('pp.changeoverMatrix.create')">Add Row</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 max-w-xs">
      <FormSelect
        v-model="resourceGroupId"
        name="resource_group_filter"
        label="Filter by resource group"
        :options="resourceGroupOptions"
        placeholder="All groups"
      />
    </div>

    <div class="mt-4">
      <DataTable
        :columns="columns"
        :items="rows.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="pp.changeoverMatrix"
        :total="rows.total"
        :from="rows.from"
        :to="rows.to"
        :links="rows.links"
        empty-title="No changeover matrix rows yet"
        empty-description="Define switching costs so the minimize setup / minimize changeover dispatch strategies have real data to optimize against."
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
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as ChangeoverMatrixRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('pp.changeoverMatrix.edit', item.id)"
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
