<!-- ponytail: Machine listing (MES_SPECS.md §3D) -->
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

interface MachineRow {
  id: number
  code: string
  name: string
  work_center_code: string | null
  work_center_name: string | null
  status: string
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
  machines: PaginatedData<MachineRow>
  filters: { search?: string; work_center_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  workCenters: Array<{ value: number; label: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ work_center_id: props.filters.work_center_id ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.machines.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'work_center_id',
    label: 'Work Center',
    type: 'select',
    options: props.workCenters.map((w) => ({ label: w.label, value: String(w.value) })),
  },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Running', value: 'running' },
      { label: 'Idle', value: 'idle' },
      { label: 'Down', value: 'down' },
      { label: 'Maintenance', value: 'maintenance' },
      { label: 'Setup', value: 'setup' },
      { label: 'Waiting Material', value: 'waiting_material' },
      { label: 'Waiting Operator', value: 'waiting_operator' },
      { label: 'Waiting QC', value: 'waiting_qc' },
    ],
  },
]

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'work_center_code', label: 'Work Center' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('mes.machines.index'), {
    search: search.value,
    work_center_id: filters.value.work_center_id,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: MachineRow | Record<string, unknown>) => {
  const row = item as MachineRow
  confirm({
    title: `Delete machine ${row.code}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('mes.machines.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected machine(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('mes.machines.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Machines"
      description="One machine within a work center. Status drives the Shop Floor UI's equipment strip."
    >
      <template #actions>
        <PrimaryButton :href="route('mes.machines.create')">Add Machine</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="machines.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="mes.machines"
        search-placeholder="Search code or name…"
        :filter-fields="filterFields"
        export-filename="mes-machines"
        :total="machines.total"
        :from="machines.from"
        :to="machines.to"
        :links="machines.links"
        empty-title="No machines yet"
        empty-description="Add a machine to a work center to track its status and reference it from routing operations."
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
          <span class="font-mono text-xs text-ink-900">{{ (item as MachineRow).code }}</span>
        </template>
        <template #cell-work_center_code="{ item }">
          <span class="text-xs text-ink-600">{{ (item as MachineRow).work_center_code }} — {{ (item as MachineRow).work_center_name }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as MachineRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('mes.machines.edit', item.id)"
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
