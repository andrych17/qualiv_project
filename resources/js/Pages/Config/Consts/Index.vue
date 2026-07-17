<!-- ponytail: Config consts listing -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import DataTablePagination from '@/Components/tables/DataTablePagination.vue'
import SearchInput from '@/Components/filters/SearchInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import { ref, watch } from 'vue'
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
}

const props = defineProps<{
  consts: PaginatedData<ConstRow>
  filters: { search?: string; const_group?: string }
  groups: Array<{ label: string; value: string }>
}>()

const search = ref(props.filters.search ?? '')
const constGroup = ref(props.filters.const_group ?? '')

const columns = [
  { key: 'const_group', label: 'Group' },
  { key: 'group_code', label: 'Key' },
  { key: 'seq', label: 'Seq', align: 'right' as const },
  { key: 'str1', label: 'Str1' },
  { key: 'num1', label: 'Num1', align: 'right' as const },
  { key: 'note1', label: 'Note' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, constGroup], debounce(() => {
  router.get(route('config.consts.index'), {
    search: search.value,
    const_group: constGroup.value,
  }, { preserveState: true, replace: true })
}, 400))

const confirmDelete = (item: ConstRow | Record<string, unknown>) => {
  const row = item as ConstRow
  if (!confirm(`Delete const ${row.const_group}.${row.group_code}?`)) return
  router.delete(route('config.consts.destroy', row.id))
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
      <div class="flex flex-col gap-3 sm:flex-row">
        <div class="w-full sm:max-w-xs">
          <SearchInput v-model="search" placeholder="Search group, key, value..." />
        </div>
        <div class="w-full sm:max-w-[200px]">
          <FormSelect
            v-model="constGroup"
            name="const_group"
            placeholder="All Groups"
            :options="groups"
          />
        </div>
      </div>

      <DataTable
        :columns="columns"
        :items="consts.data"
        empty-title="No constants"
        empty-description="Create a constant for app settings."
      >
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

      <DataTablePagination :links="consts.links" />
    </div>
  </AppLayout>
</template>
