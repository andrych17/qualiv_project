<!-- ponytail: Accounting §3L exchange rates — plain company-scoped list, same convention as TaxCodes. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatDate } from '@/Utils/formatters'

interface RateRow {
  id: number
  currency_code: string
  rate_to_base: number
  effective_date: string
  source: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  baseCurrency: string | null
  rates: RateRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'currency_code', label: 'Currency', sortable: true },
  { key: 'rate_to_base', label: `Rate to ${props.baseCurrency ?? 'Base'}`, sortable: true, align: 'right' as const },
  { key: 'effective_date', label: 'Effective Date', sortable: true },
  { key: 'source', label: 'Source', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredRates = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.rates
  return props.rates.filter((r) => r.currency_code.toLowerCase().includes(q) || r.source.toLowerCase().includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.exchange-rates.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (rate: RateRow) => {
  confirm({
    title: `Delete the ${rate.currency_code} rate effective ${rate.effective_date}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.exchange-rates.destroy', rate.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Exchange Rates" :description="`Rate to base currency (${baseCurrency ?? '—'}), effective on a date — AR/AP posting picks the most recent rate on or before the transaction date.`">
      <template #actions>
        <PrimaryButton :href="route('accounting.exchange-rates.create', { company_id: selectedCompanyId })">
          New Rate
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="flex items-center gap-3">
        <label class="text-xs font-semibold text-ink-600">Company:</label>
        <select
          :value="selectedCompanyId"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
      </div>

      <DataTable
        :columns="columns"
        :items="filteredRates"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.exchange-rates"
        search-placeholder="Search currency code or source…"
        export-filename="exchange-rates"
        empty-title="No exchange rates found"
        :empty-description="`No foreign exchange rates defined. ${baseCurrency ?? 'Base currency'}-only transactions do not require conversion rates.`"
      >
        <template #cell-currency_code="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as RateRow).currency_code }}</span>
        </template>

        <template #cell-rate_to_base="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">{{ (item as RateRow).rate_to_base }}</span>
        </template>

        <template #cell-effective_date="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDate((item as RateRow).effective_date) }}</span>
        </template>

        <template #cell-source="{ item }">
          <span class="text-xs capitalize text-ink-700">{{ (item as RateRow).source }}</span>
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.exchange-rates.edit', (item as RateRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item as RateRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
