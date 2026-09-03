<!-- ponytail: read-only tracker list — actions (billing code/paid/validated) live on the deed's DeedTaxPanel -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import LegalSubNav from '@/Components/legal/LegalSubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { formatCurrency } from '@/Utils/formatters'

interface DeedTaxRow {
  id: number
  deed_id: number
  deed_number: string | null
  matter_code: string | null
  tax_type: string
  status: string
  base_amount: string | null
  computed_amount: string | null
  billing_code: string | null
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
  taxes: PaginatedData<DeedTaxRow>
  filters: { status?: string; tax_type?: string; sort?: string; direction?: string; per_page?: string }
}>()

const filters = ref({ status: props.filters.status ?? '', tax_type: props.filters.tax_type ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.taxes.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Pending', value: 'pending' },
      { label: 'Billing code issued', value: 'billing_code_issued' },
      { label: 'Paid', value: 'paid' },
      { label: 'Validated', value: 'validated' },
    ],
  },
  {
    key: 'tax_type',
    label: 'Tax Type',
    type: 'select',
    options: [
      { label: 'PPh Final', value: 'pph_final' },
      { label: 'BPHTB', value: 'bphtb' },
    ],
  },
]

const columns = [
  { key: 'deed_number', label: 'Deed' },
  { key: 'matter_code', label: 'Matter' },
  { key: 'tax_type', label: 'Tax Type', sortable: true },
  { key: 'base_amount', label: 'Base Amount' },
  { key: 'computed_amount', label: 'Computed' },
  { key: 'billing_code', label: 'Billing Code' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('legal.taxes.index'), {
    status: filters.value.status,
    tax_type: filters.value.tax_type,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader title="Deed Taxes" description="PPh Final and BPHTB tax records generated per deed, tracked through billing code, payment, and validation." />

    <LegalSubNav active="taxes" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="taxes.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="legal.deed_taxes"
        status-rail-key="status"
        :filter-fields="filterFields"
        export-filename="legal-deed-taxes"
        :total="taxes.total"
        :from="taxes.from"
        :to="taxes.to"
        :links="taxes.links"
        empty-title="No deed taxes yet"
        empty-description="Tax records are generated per deed once due diligence is complete."
      >
        <template #cell-deed_number="{ item }">
          {{ item.deed_number ?? '—' }}
        </template>
        <template #cell-tax_type="{ item }">
          {{ item.tax_type === 'pph_final' ? 'PPh Final' : 'BPHTB' }}
        </template>
        <template #cell-base_amount="{ item }">
          {{ formatCurrency(item.base_amount) }}
        </template>
        <template #cell-computed_amount="{ item }">
          {{ formatCurrency(item.computed_amount) }}
        </template>
        <template #cell-billing_code="{ item }">
          {{ item.billing_code ?? '—' }}
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-actions="{ item }">
          <Link
            :href="route('legal.deeds.edit', item.deed_id)"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Open deed
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
