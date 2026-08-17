<!-- ponytail: Plan catalog listing -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface PlanRow {
  id: number
  code: string
  name: string
  price_monthly: string | number
  currency: string
  is_active: boolean
}

const props = defineProps<{
  plans: { data: PlanRow[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number; from: number | null; to: number | null; per_page: number }
  filters: { search?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null)
const perPage = ref(Number(props.filters.per_page) || props.plans.per_page)

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'price_monthly', label: 'Price/mo', align: 'right' as const, sortable: true },
  { key: 'currency', label: 'Currency' },
  { key: 'is_active', label: 'Active' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  router.get(route('central.plans.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <CentralAdminLayout>
    <PageHeader title="Plans" description="Pricing/packaging catalog for tenant subscriptions.">
      <template #actions>
        <Link :href="route('central.plans.create')" class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800">
          Create Plan
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="plans.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:per-page="perPage"
        storage-key="central.plans"
        search-placeholder="Search code, name..."
        :total="plans.total"
        :from="plans.from"
        :to="plans.to"
        :links="plans.links"
        empty-title="No plans"
        empty-description="Create a subscription plan."
      >
        <template #cell-is_active="{ item }">
          <span :class="item.is_active ? 'text-green-700' : 'text-gray-400'">{{ item.is_active ? 'Active' : 'Inactive' }}</span>
        </template>
        <template #cell-actions="{ item }">
          <Link :href="route('central.plans.edit', item.id)" class="text-sm font-medium text-gray-700 hover:text-gray-900">Edit</Link>
        </template>
      </DataTable>
    </div>
  </CentralAdminLayout>
</template>
