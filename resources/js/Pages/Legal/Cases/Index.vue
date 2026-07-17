<!-- ponytail: Legal cases list — first vertical MVP -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import DataTablePagination from '@/Components/tables/DataTablePagination.vue'
import SearchInput from '@/Components/filters/SearchInput.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface CaseRow {
  id: number
  uuid: string
  code: string
  title: string
  status: string
  created_at_formatted: string | null
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
}

const props = defineProps<{
  cases: PaginatedData<CaseRow>
  filters: { search?: string; status?: string }
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

const columns = [
  { key: 'code', label: 'Code' },
  { key: 'title', label: 'Title' },
  { key: 'status', label: 'Status' },
  { key: 'created_at_formatted', label: 'Opened' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, status], debounce(() => {
  router.get(route('legal.cases.index'), {
    search: search.value,
    status: status.value,
  }, { preserveState: true, replace: true })
}, 400))

const confirmDelete = (item: CaseRow | Record<string, unknown>) => {
  const row = item as CaseRow
  if (!confirm(`Delete case ${row.code}?`)) return
  router.delete(route('legal.cases.destroy', row.id))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Legal Cases"
      description="Track matters and case status for this firm."
    >
      <template #actions>
        <Link
          :href="route('legal.cases.create')"
          class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800"
        >
          Open Case
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="flex flex-col gap-3 sm:flex-row">
        <div class="w-full sm:max-w-xs">
          <SearchInput v-model="search" placeholder="Search code or title..." />
        </div>
        <div class="w-full sm:max-w-[180px]">
          <FormSelect
            v-model="status"
            name="status"
            placeholder="All Status"
            :options="[
              { label: 'Open', value: 'open' },
              { label: 'Pending', value: 'pending' },
              { label: 'Closed', value: 'closed' },
            ]"
          />
        </div>
      </div>

      <DataTable
        :columns="columns"
        :items="cases.data"
        empty-title="No cases yet"
        empty-description="Open your first case to start the Legal module."
      >
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('legal.cases.edit', item.id)"
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

      <DataTablePagination :links="cases.links" />
    </div>
  </AppLayout>
</template>
