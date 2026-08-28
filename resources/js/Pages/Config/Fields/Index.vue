<!-- ponytail: Custom field definitions admin (CUSTOMFIELDS_SPECS.md §3A) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface FieldRow {
  id: number
  module_code: string | null
  entity_type: string
  code: string
  label: string
  field_type: string
  is_required: boolean
  seq: number
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
  defs: PaginatedData<FieldRow>
  filters: { search?: string; module_code?: string; entity_type?: string; sort?: string; direction?: string; per_page?: string }
  entityTypes: Array<{ label: string; value: string }>
  modules: Array<{ label: string; value: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  module_code: props.filters.module_code ?? '',
  entity_type: props.filters.entity_type ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.defs.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'module_code', label: 'Module', type: 'select', options: props.modules },
  { key: 'entity_type', label: 'Entity', type: 'select', options: props.entityTypes },
]

const columns = [
  { key: 'module_code', label: 'Module', sortable: true },
  { key: 'entity_type', label: 'Entity', sortable: true },
  { key: 'code', label: 'Code', sortable: true },
  { key: 'label', label: 'Label', sortable: true },
  { key: 'field_type', label: 'Type', sortable: true },
  { key: 'seq', label: 'Seq', align: 'right' as const, sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('config.fields.index'), {
    search: search.value,
    module_code: filters.value.module_code,
    entity_type: filters.value.entity_type,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: FieldRow | Record<string, unknown>) => {
  const row = item as FieldRow
  confirm({
    title: `Deactivate field ${row.entity_type}.${row.code}?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () => router.delete(route('config.fields.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Deactivate ${selected.value.length} selected field(s)?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () =>
      router.delete(route('config.fields.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Custom Fields"
      description="Tenant-defined extra fields. Inactive defs leave historical values readable."
    >
      <template #actions>
        <PrimaryButton :href="route('config.fields.create')">
          Create Field
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="defs.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="config.fields"
        search-placeholder="Search entity, code, label..."
        :filter-fields="filterFields"
        export-filename="custom-fields"
        :total="defs.total"
        :from="defs.from"
        :to="defs.to"
        :links="defs.links"
        empty-title="No custom fields"
        empty-description="Create a field definition for an entity type."
      >
        <template #bulk-actions>
          <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmBulkDelete">
            Deactivate selected
          </button>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('config.fields.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline cursor-pointer"
              @click="confirmDelete(item)"
            >
              Deactivate
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
