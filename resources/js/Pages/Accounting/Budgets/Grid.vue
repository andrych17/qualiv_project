<!-- ponytail: Accounting §3J Budget grid — one flat annual budget per company/fiscal year,
     edited one cost-center scope at a time. Bulk-paste-friendly: paste a block copied from
     a spreadsheet into any cell and it fills across/down from there, Excel-style. -->
<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

type Cell = { fiscal_period_id: number; amount: number | null }
type AccountRow = { account_id: number; account_code: string; account_name: string; cells: Cell[] }
type Grid = { periods: { fiscal_period_id: number; period_no: number }[]; accounts: AccountRow[] }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  fiscalYears: Array<{ value: number; label: string }>
  selectedFiscalYearId: number | null
  costCenters: Array<{ value: number; label: string }>
  selectedCostCenterId: number | null
  budgetId: number | null
  grid: Grid | null
}>()

const local = reactive<Grid>(props.grid ? JSON.parse(JSON.stringify(props.grid)) : { periods: [], accounts: [] })
const saving = ref(false)
const savedMessage = ref('')

const goTo = (params: Record<string, string | number | null>) => {
  router.get(route('accounting.budgets.index'), {
    company_id: props.selectedCompanyId,
    fiscal_year_id: props.selectedFiscalYearId,
    cost_center_id: props.selectedCostCenterId ?? '',
    ...params,
  }, { preserveState: true })
}

const switchCompany = (e: Event) => goTo({ company_id: (e.target as HTMLSelectElement).value, fiscal_year_id: null, cost_center_id: '' })
const switchFiscalYear = (e: Event) => goTo({ fiscal_year_id: (e.target as HTMLSelectElement).value })
const switchCostCenter = (e: Event) => goTo({ cost_center_id: (e.target as HTMLSelectElement).value })

const save = () => {
  if (!props.budgetId) return
  saving.value = true
  savedMessage.value = ''

  const cells = local.accounts.flatMap((a) =>
    a.cells.filter((c) => c.amount !== null && c.amount !== undefined && !Number.isNaN(c.amount))
      .map((c) => ({ account_id: a.account_id, fiscal_period_id: c.fiscal_period_id, amount: c.amount as number })),
  )

  router.post(route('accounting.budgets.grid.store', props.budgetId), {
    cost_center_id: props.selectedCostCenterId,
    cells,
  }, {
    preserveScroll: true,
    onFinish: () => { saving.value = false },
    onSuccess: () => { savedMessage.value = 'Saved.' },
  })
}

// Excel-style block paste: paste a copied range of cells starting at whichever input has
// focus, filling across (periods) then down (accounts) from that position — same UX as
// pasting into a spreadsheet. A single-cell paste (no tabs/newlines) just falls through to
// the browser's default single-value paste.
const onPaste = (e: ClipboardEvent, rowIndex: number, colIndex: number) => {
  const text = e.clipboardData?.getData('text/plain') ?? ''
  const rows = text.replace(/\r/g, '').split('\n').filter((r, i, arr) => !(i === arr.length - 1 && r === ''))
  if (rows.length <= 1 && !rows[0]?.includes('\t')) return

  e.preventDefault()
  rows.forEach((rowText, r) => {
    const values = rowText.split('\t')
    values.forEach((v, c) => {
      const account = local.accounts[rowIndex + r]
      const cell = account?.cells[colIndex + c]
      if (!cell) return
      const trimmed = v.trim()
      cell.amount = trimmed === '' ? null : Number(trimmed)
    })
  })
}

const importForm = useForm<{ file: File | null }>({ file: null })
const importOpen = ref(false)

const submitImport = () => {
  if (!props.budgetId) return
  importForm.post(route('accounting.budgets.import', props.budgetId), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { importForm.reset(); importOpen.value = false },
  })
}

const monthLabel = (periodNo: number) => new Date(2000, periodNo - 1, 1).toLocaleString('en', { month: 'short' })
const hasGrid = computed(() => local.accounts.length > 0 && local.periods.length > 0)
</script>

<template>
  <AppLayout>
    <PageHeader title="Budget" description="One flat annual budget per company/fiscal year — account × cost center × period. Paste a block copied from a spreadsheet directly into the grid.">
      <template #actions>
        <Link :href="route('accounting.reports.budget-vs-actual', { company_id: selectedCompanyId, fiscal_year_id: selectedFiscalYearId })" class="text-sm font-medium text-accent hover:underline">Budget vs. Actual →</Link>
      </template>
    </PageHeader>

    <Panel class="mt-6 p-4">
      <div class="flex flex-wrap items-center gap-3">
        <select :value="selectedCompanyId" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchCompany">
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
        <select :value="selectedFiscalYearId" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchFiscalYear">
          <option v-for="y in fiscalYears" :key="y.value" :value="y.value">{{ y.label }}</option>
        </select>
        <select :value="selectedCostCenterId ?? ''" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchCostCenter">
          <option value="">Unassigned (no cost center)</option>
          <option v-for="c in costCenters" :key="c.value" :value="c.value">{{ c.label }}</option>
        </select>

        <div class="ml-auto flex items-center gap-3">
          <span v-if="savedMessage" class="text-sm text-ink-600">{{ savedMessage }}</span>
          <button type="button" class="text-sm font-medium text-accent hover:underline" @click="importOpen = !importOpen">Import CSV</button>
          <PrimaryButton :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</PrimaryButton>
        </div>
      </div>

      <div v-if="importOpen" class="mt-4 flex items-center gap-3 rounded-sm border border-border bg-surface-50 p-3">
        <input type="file" accept=".csv,.txt" class="text-sm" @change="importForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
        <PrimaryButton :disabled="!importForm.file || importForm.processing" @click="submitImport">{{ importForm.processing ? 'Importing…' : 'Import' }}</PrimaryButton>
        <span class="text-xs text-ink-600">Columns: account_code, cost_center_code (blank = unassigned), period_no, amount. Any invalid row rejects the whole file.</span>
      </div>
      <ul v-if="importForm.errors.file" class="mt-2 list-disc pl-5 text-sm text-signal-danger">
        <li v-for="(err, i) in (Array.isArray(importForm.errors.file) ? importForm.errors.file : [importForm.errors.file])" :key="i">{{ err }}</li>
      </ul>
    </Panel>

    <Panel v-if="hasGrid" class="mt-4 overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="sticky left-0 bg-surface-0 px-3 py-2">Account</th>
            <th v-for="p in local.periods" :key="p.fiscal_period_id" class="px-2 py-2 text-right">{{ monthLabel(p.period_no) }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(a, ai) in local.accounts" :key="a.account_id" class="border-b border-border">
            <td class="sticky left-0 whitespace-nowrap bg-surface-0 px-3 py-1.5 text-ink-900">{{ a.account_code }} — {{ a.account_name }}</td>
            <td v-for="(cell, ci) in a.cells" :key="cell.fiscal_period_id" class="px-1 py-1">
              <input
                v-model.number="cell.amount"
                type="number"
                step="0.01"
                class="w-24 rounded-sm border border-border px-1.5 py-1 text-right text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent/20"
                @paste="onPaste($event, ai, ci)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </Panel>
    <Panel v-else class="mt-4 p-6 text-center text-ink-600">No active accounts to budget for this company.</Panel>
  </AppLayout>
</template>
