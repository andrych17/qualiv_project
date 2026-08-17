<!-- ponytail: Tenant registry listing -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface TenantRow {
  id: string
  name: string
  plan: string
  access_status: string
  contact_email: string | null
}

const props = defineProps<{
  tenants: { data: TenantRow[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number; from: number | null; to: number | null; per_page: number }
  filters: { search?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null)
const perPage = ref(Number(props.filters.per_page) || props.tenants.per_page)

const columns = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'plan', label: 'Plan', sortable: true },
  { key: 'access_status', label: 'Access', sortable: true },
  { key: 'contact_email', label: 'Contact' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  router.get(route('central.tenants.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <CentralAdminLayout>
    <PageHeader title="Tenants" description="Every customer of the platform, and their provisioning/access status.">
      <template #actions>
        <Link :href="route('central.tenants.create')" class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800">
          Register Tenant
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="tenants.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:per-page="perPage"
        storage-key="central.tenants"
        search-placeholder="Search name..."
        :total="tenants.total"
        :from="tenants.from"
        :to="tenants.to"
        :links="tenants.links"
        empty-title="No tenants"
        empty-description="Register the first tenant to provision their database."
      >
        <template #cell-access_status="{ item }">
          <span :class="item.access_status === 'active' ? 'text-green-700' : 'text-amber-700'">{{ item.access_status }}</span>
        </template>
        <template #cell-actions="{ item }">
          <Link :href="route('central.tenants.edit', item.id)" class="text-sm font-medium text-gray-700 hover:text-gray-900">Edit</Link>
        </template>
      </DataTable>
    </div>
  </CentralAdminLayout>
</template>
