<!-- ponytail: Config menu listing — SYSCONFIG.config_menus CRUD -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ConfigMenuRow {
  id: number
  code: string
  menu_caption: string
  menu_header: string | null
  menu_link: string | null
  icon: string | null
  seq: number
  status_code: string
  status_label: string
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
  items: PaginatedData<ConfigMenuRow>
  filters: {
    search?: string
    status?: string
    header?: string
    sort?: string
    direction?: string
    per_page?: string
  }
  headers: Array<{ label: string; value: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '', header: props.filters.header ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.items.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Active', value: 'A' },
      { label: 'Inactive', value: 'I' },
    ],
  },
  { key: 'header', label: 'Header', type: 'select', options: props.headers },
]

const columns: Array<{
  key: string
  label: string
  align?: 'left' | 'center' | 'right'
  sortable?: boolean
}> = [
  { key: 'seq', label: 'Seq', align: 'right', sortable: true },
  { key: 'code', label: 'Code', sortable: true },
  { key: 'menu_caption', label: 'Caption', sortable: true },
  { key: 'menu_header', label: 'Header', sortable: true },
  { key: 'menu_link', label: 'Link' },
  { key: 'icon', label: 'Icon' },
  { key: 'status_label', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('config.menus.index'), {
    search: search.value,
    status: filters.value.status,
    header: filters.value.header,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, {
    preserveState: true,
    replace: true,
  })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: ConfigMenuRow | Record<string, unknown>) => {
  const row = item as ConfigMenuRow
  confirm({
    title: `Delete menu ${row.menu_caption} (${row.code})?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('config.menus.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected menu(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('config.menus.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Menus"
      description="Sidebar menus and links for this tenant."
    >
      <template #actions>
        <PrimaryButton :href="route('config.menus.create')">
          Create Menu
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="items.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="config.menus"
        search-placeholder="Search code, caption, link..."
        :filter-fields="filterFields"
        export-filename="config-menus"
        :total="items.total"
        :from="items.from"
        :to="items.to"
        :links="items.links"
        empty-title="No menus"
        empty-description="Create a menu so it can appear in the sidebar."
      >
        <template #bulk-actions>
          <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmBulkDelete">
            Delete selected
          </button>
        </template>
        <template #cell-status_label="{ item }">
          <StatusBadge :status="item.status_label" />
        </template>

        <template #cell-menu_link="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ item.menu_link || '#' }}</span>
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('config.menus.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline cursor-pointer"
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
