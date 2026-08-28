<!-- ponytail: Config consts listing -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ConstRow {
  id: number
  appl_id: string | null
  const_group: string | null
  group_code: string | null
  value: string | null
  value_type: string | null
  seq: number
  str1: string | null
  str2: string | null
  num1: string | number | null
  note1: string | null
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
  consts: PaginatedData<ConstRow>
  filters: { search?: string; const_group?: string; lens?: string; sort?: string; direction?: string; per_page?: string }
  groups: Array<{ label: string; value: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ const_group: props.filters.const_group ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.consts.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'const_group', label: 'Group', type: 'select', options: props.groups },
]

const lens = ref(props.filters.lens ?? 'settings')

const columns = [
  { key: 'const_group', label: 'Group', sortable: true },
  { key: 'group_code', label: 'Key', sortable: true },
  { key: 'appl_id', label: 'Module', sortable: true },
  { key: 'value', label: 'Value', sortable: true },
  { key: 'str1', label: 'Label', sortable: true },
  { key: 'seq', label: 'Seq', align: 'right' as const, sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage, lens], debounce(() => {
  selected.value = []
  router.get(route('config.consts.index'), {
    search: search.value,
    const_group: filters.value.const_group,
    lens: lens.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: ConstRow | Record<string, unknown>) => {
  const row = item as ConstRow
  confirm({
    title: `Deactivate const ${row.const_group}.${row.group_code}?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () => router.delete(route('config.consts.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Deactivate ${selected.value.length} selected const(s)?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () =>
      router.delete(route('config.consts.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
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
        <PrimaryButton :href="route('config.consts.create')">
          Create Const
        </PrimaryButton>
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
          <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmBulkDelete">
            Deactivate selected
          </button>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('config.consts.edit', item.id)"
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
