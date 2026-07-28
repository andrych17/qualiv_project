<!-- ponytail: Config consts listing -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { ref, reactive, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface ConstRow {
  id: number
  const_group: string | null
  group_code: string | null
  seq: number
  str1: string | null
  str2: string | null
  num1: string | number | null
  note1: string | null
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
  consts: PaginatedData<ConstRow>
  filters: { search?: string; const_group?: string; sort?: string; direction?: string; per_page?: string }
  groups: Array<{ label: string; value: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = reactive({ const_group: props.filters.const_group ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.consts.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'const_group', label: 'Group', type: 'select', options: props.groups },
]

const columns = [
  { key: 'const_group', label: 'Group', sortable: true },
  { key: 'group_code', label: 'Key', sortable: true },
  { key: 'seq', label: 'Seq', align: 'right' as const, sortable: true },
  { key: 'str1', label: 'Str1', sortable: true },
  { key: 'num1', label: 'Num1', align: 'right' as const, sortable: true },
  { key: 'note1', label: 'Note', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('config.consts.index'), {
    search: search.value,
    const_group: filters.const_group,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const confirmDelete = (item: ConstRow | Record<string, unknown>) => {
  const row = item as ConstRow
  if (!confirm(`Delete const ${row.const_group}.${row.group_code}?`)) return
  router.delete(route('config.consts.destroy', row.id))
}

const confirmBulkDelete = () => {
  if (!confirm(`Delete ${selected.value.length} selected const(s)?`)) return
  router.delete(route('config.consts.bulkDestroy'), {
    data: { ids: selected.value },
    onSuccess: () => { selected.value = [] },
  })
}

// InlineEditor demo — only str1/seq are quick-editable; everything else needs the full Edit form.
const onCellEdit = (item: Record<string, any>, key: string, value: string) => {
  router.patch(route('config.consts.quickUpdate', item.id), { field: key, value }, {
    preserveScroll: true,
    preserveState: true,
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Constants"
      description="Tenant config constants (status codes, thresholds, labels)."
    >
      <template #actions>
        <Link
          :href="route('config.consts.create')"
          class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800"
        >
          Create Const
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="consts.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="config.consts"
        search-placeholder="Search group, key, value..."
        :filter-fields="filterFields"
        :editable-keys="['str1', 'seq']"
        export-filename="config-consts"
        :total="consts.total"
        :from="consts.from"
        :to="consts.to"
        :links="consts.links"
        empty-title="No constants"
        empty-description="Create a constant for app settings."
        @cell-edit="onCellEdit"
      >
        <template #bulk-actions>
          <button type="button" class="text-sm font-medium text-red-600 hover:text-red-950" @click="confirmBulkDelete">
            Delete selected
          </button>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('config.consts.edit', item.id)"
              class="text-sm font-medium text-gray-700 hover:text-gray-900"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-red-600 hover:text-red-950"
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
