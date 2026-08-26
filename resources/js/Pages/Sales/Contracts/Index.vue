<!-- Contracts & Subscriptions List (§3L) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface SubscriptionItem {
  recurring_amount: number
  billing_interval: string
}

interface ContractItem {
  id: number
  uuid: string
  name: string
  status: string
  term_start: string
  term_end: string
  auto_renew: boolean
  customer: { id: number; name: string } | null
  subscriptions: SubscriptionItem[]
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
  contracts: PaginatedData<ContractItem>
  statuses: string[]
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.contracts.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: props.statuses.map((st) => ({ label: st.toUpperCase(), value: st })),
  },
]

const columns = [
  { key: 'name', label: 'Contract Name', sortable: true },
  { key: 'customer', label: 'Customer' },
  { key: 'duration', label: 'Term Duration' },
  { key: 'recurring_value', label: 'Recurring Value', align: 'right' as const },
  { key: 'auto_renew', label: 'Auto Renew', align: 'center' as const },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const { confirm } = useConfirm()

const triggerRecurringBilling = () => {
  confirm({
    title: 'Process Recurring Billing?',
    description: 'Run recurring billing cycle sweep now for all eligible active contracts?',
    confirmText: 'Run Sweep',
    onConfirm: () => router.post(route('sales.contracts.recurring.process')),
  })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('sales.contracts.index'), {
    search: search.value || undefined,
    status: filters.value.status || undefined,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Contracts & Recurring Billing"
      description="Service agreements, retainer subscriptions, and automated recurring billing schedules (§3L)."
    >
      <template #actions>
        <SecondaryButton @click="triggerRecurringBilling">Run Billing Cycle</SecondaryButton>
        <PrimaryButton :href="route('sales.contracts.create')">New Contract</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="contracts" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="contracts.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="sales.contracts"
        search-placeholder="Search contract name or customer…"
        :filter-fields="filterFields"
        export-filename="sales-contracts"
        status-rail-key="status"
        :total="contracts.total"
        :from="contracts.from"
        :to="contracts.to"
        :links="contracts.links"
        empty-title="No contracts found"
        empty-description="Create your first client service contract or retainer agreement."
      >
        <template #cell-name="{ item }">
          <Link
            :href="route('sales.contracts.show', item.id)"
            class="font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as ContractItem).name }}
          </Link>
        </template>

        <template #cell-customer="{ item }">
          <span class="font-medium text-ink-900">{{ (item as ContractItem).customer?.name ?? '-' }}</span>
        </template>

        <template #cell-duration="{ item }">
          <span class="font-mono text-xs text-ink-600">
            {{ formatDate((item as ContractItem).term_start) }} &rarr; {{ formatDate((item as ContractItem).term_end) }}
          </span>
        </template>

        <template #cell-recurring_value="{ item }">
          <span class="font-mono font-semibold text-ink-900">
            {{ formatCurrency((item as ContractItem).subscriptions.reduce((s, sub) => s + Number(sub.recurring_amount), 0)) }}
          </span>
        </template>

        <template #cell-auto_renew="{ item }">
          <span v-if="(item as ContractItem).auto_renew" class="font-medium text-signal-success">Yes</span>
          <span v-else class="text-ink-400">No</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as ContractItem).status" />
        </template>

        <template #cell-actions="{ item }">
          <Link
            :href="route('sales.contracts.show', item.id)"
            class="text-sm font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Manage &rarr;
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
