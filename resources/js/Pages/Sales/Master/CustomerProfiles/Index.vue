<!-- Customer Sales & Credit Profiles Index (§3B / §3K) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import SalesMasterSubNav from '@/Components/sales/SalesMasterSubNav.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { formatCurrency } from '@/Utils/formatters'

interface PartnerItem {
  id: number
  name: string
  sales_profile?: {
    sales_team?: { name: string }
    price_list?: { name: string }
    assigned_rep?: { name: string }
  }
  credit_profile?: {
    credit_limit: number
    payment_terms_days: number
    on_hold: boolean
  }
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
  customers: PaginatedData<PartnerItem>
  filters: { search?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.customers.per_page)

const columns = [
  { key: 'name', label: 'Customer Name', sortable: true },
  { key: 'assignment', label: 'Assigned Team / Rep' },
  { key: 'price_list', label: 'Price List' },
  { key: 'payment_terms', label: 'Payment Terms' },
  { key: 'credit_limit', label: 'Credit Limit', align: 'right' as const },
  { key: 'credit_standing', label: 'Credit Standing', align: 'center' as const },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('sales.master.customers.index'), {
    search: search.value || undefined,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Customer Sales & Credit Profiles"
      description="Configure default price lists, sales teams, reps, payment terms, and credit limits (§3B / §3K)."
    />

    <div class="mt-4">
      <SalesSubNav active="master" />
    </div>

    <div class="mt-4">
      <SalesMasterSubNav active="customers" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="customers.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:per-page="perPage"
        sticky-header
        storage-key="sales.master.customers"
        search-placeholder="Search customer name…"
        export-filename="sales-customer-profiles"
        :total="customers.total"
        :from="customers.from"
        :to="customers.to"
        :links="customers.links"
        empty-title="No customer profiles found"
        empty-description="Active customer partners in CRM automatically appear here for sales configuration."
      >
        <template #cell-name="{ item }">
          <Link
            :href="route('sales.master.customers.edit', item.id)"
            class="font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as PartnerItem).name }}
          </Link>
        </template>

        <template #cell-assignment="{ item }">
          <div class="text-xs text-ink-700">
            <p v-if="(item as PartnerItem).sales_profile?.sales_team">
              Team: <strong>{{ (item as PartnerItem).sales_profile?.sales_team?.name }}</strong>
            </p>
            <p v-if="(item as PartnerItem).sales_profile?.assigned_rep">
              Rep: {{ (item as PartnerItem).sales_profile?.assigned_rep?.name }}
            </p>
            <span v-if="!(item as PartnerItem).sales_profile?.sales_team && !(item as PartnerItem).sales_profile?.assigned_rep" class="text-ink-400">
              Unassigned
            </span>
          </div>
        </template>

        <template #cell-price_list="{ item }">
          <span class="text-xs text-ink-700">
            {{ (item as PartnerItem).sales_profile?.price_list?.name ?? 'Tenant Default' }}
          </span>
        </template>

        <template #cell-payment_terms="{ item }">
          <span class="text-xs font-mono">
            {{ (item as PartnerItem).credit_profile?.payment_terms_days ?? 30 }} Days
          </span>
        </template>

        <template #cell-credit_limit="{ item }">
          <span class="font-mono font-medium text-ink-900">
            {{ formatCurrency((item as PartnerItem).credit_profile?.credit_limit ?? 0) }}
          </span>
        </template>

        <template #cell-credit_standing="{ item }">
          <span
            v-if="(item as PartnerItem).credit_profile?.on_hold"
            class="text-xs font-bold text-signal-danger bg-rose-50 px-2 py-0.5 rounded"
          >
            ON HOLD
          </span>
          <span v-else class="text-xs font-semibold text-signal-success">
            Normal
          </span>
        </template>

        <template #cell-actions="{ item }">
          <Link
            :href="route('sales.master.customers.edit', item.id)"
            class="text-sm font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Configure &rarr;
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
