<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import { ref, watch, computed } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

interface ContactRow {
  id: number
  uuid: string
  name: string
  title_position: string | null
  parent_name: string | null
  is_active: boolean
  created_at_formatted: string | null
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
  contacts: PaginatedData<ContactRow>
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.contacts.per_page)

const filterFields = computed<FilterFieldDef[]>(() => [
  {
    key: 'status',
    label: t('common.status'),
    type: 'select',
    options: [
      { label: t('common.active'), value: 'active' },
      { label: t('common.inactive'), value: 'inactive' },
    ],
  },
])

const columns = computed(() => [
  { key: 'name', label: t('crm.contact'), sortable: true },
  { key: 'title_position', label: t('crm.position_title') },
  { key: 'parent_name', label: t('crm.company') },
  { key: 'is_active', label: t('common.status') },
  { key: 'created_at_formatted', label: t('crm.added'), sortable: true, sortKey: 'created_at' },
  { key: 'actions', label: t('common.actions'), align: 'right' as const },
])

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('crm.contacts.index'), {
    search: search.value,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDeactivate = (item: ContactRow | Record<string, unknown>) => {
  const row = item as ContactRow
  confirm({
    title: t('crm.deactivate_contact_title', { name: row.name }),
    variant: 'destructive',
    confirmText: t('crm.deactivate'),
    onConfirm: () => router.delete(route('crm.contacts.destroy', row.id)),
  })
}

const confirmBulkDeactivate = () => {
  confirm({
    title: t('crm.deactivate_bulk_contacts_title', { count: selected.value.length }),
    variant: 'destructive',
    confirmText: t('crm.deactivate'),
    onConfirm: () =>
      router.delete(route('crm.contacts.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="t('crm.contacts')"
      :description="t('crm.contacts_desc')"
    >
      <template #actions>
        <PrimaryButton :href="route('crm.contacts.create')">{{ t('crm.add_contact') }}</PrimaryButton>
      </template>
    </PageHeader>

    <CrmSubNav active="contacts" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="contacts.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="crm.contacts"
        :search-placeholder="t('common.search')"
        :filter-fields="filterFields"
        export-filename="crm-contacts"
        :total="contacts.total"
        :from="contacts.from"
        :to="contacts.to"
        :links="contacts.links"
        :empty-title="t('crm.empty_contacts_title')"
        :empty-description="t('crm.empty_contacts_desc')"
      >
        <template #bulk-actions>
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent cursor-pointer"
            @click="confirmBulkDeactivate"
          >
            {{ t('crm.deactivate_selected') }}
          </button>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as ContactRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-created_at_formatted="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ item.created_at_formatted }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('crm.contacts.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ t('common.edit') }}
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent cursor-pointer"
              @click="confirmDeactivate(item)"
            >
              {{ t('crm.deactivate') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
