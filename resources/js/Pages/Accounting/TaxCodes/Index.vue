<!-- ponytail: Accounting §3M PPN tax codes — plain company-scoped list, same convention as CostCenters. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface TaxCodeRow {
  id: number
  code: string
  rate: number
  tax_type: string
  gl_account_label: string
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  taxCodes: TaxCodeRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'rate', label: 'Rate (%)', sortable: true, align: 'right' as const },
  { key: 'tax_type', label: 'Type', sortable: true },
  { key: 'gl_account_label', label: 'GL Tax Account', sortable: true },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredTaxCodes = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.taxCodes
  return props.taxCodes.filter((t) => t.code.toLowerCase().includes(q) || t.tax_type.toLowerCase().includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.tax-codes.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (taxCode: TaxCodeRow) => {
  confirm({
    title: `Delete tax code "${taxCode.code}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.tax-codes.destroy', taxCode.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Tax Codes" description="PPN rates and their output/input treatment — configurable per company so a rate change is a data edit, not a deploy.">
      <template #actions>
        <PrimaryButton :href="route('accounting.tax-codes.create', { company_id: selectedCompanyId })">
          New Tax Code
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
        :items="filteredTaxCodes"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.tax-codes"
        search-placeholder="Search code or tax type…"
        export-filename="tax-codes"
        status-rail-key="is_active"
        empty-title="No tax codes found"
        empty-description="Create tax codes for PPN 11%, 12%, or zero-rated tax treatments."
      >
        <template #cell-code="{ item }">
          <Link
            :href="route('accounting.tax-codes.edit', (item as TaxCodeRow).id)"
            class="font-mono font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as TaxCodeRow).code }}
          </Link>
        </template>

        <template #cell-rate="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">{{ (item as TaxCodeRow).rate }}%</span>
        </template>

        <template #cell-tax_type="{ item }">
          <span class="text-xs capitalize text-ink-700 font-medium">{{ (item as TaxCodeRow).tax_type }}</span>
        </template>

        <template #cell-gl_account_label="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as TaxCodeRow).gl_account_label }}</span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as TaxCodeRow).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.tax-codes.edit', (item as TaxCodeRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item as TaxCodeRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
