<!-- ponytail: Accounting §3S — payroll component → GL account mapping list. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface MappingRow {
  id: number
  component_code: string
  component_label: string
  component_type: 'earning' | 'deduction' | 'employer_cost'
  gl_account: string
  payable_account: string | null
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  mappings: MappingRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'component_code', label: 'Code', sortable: true },
  { key: 'component_label', label: 'Component Label', sortable: true },
  { key: 'component_type', label: 'Type', sortable: true },
  { key: 'gl_account', label: 'GL Expense Account', sortable: true },
  { key: 'payable_account', label: 'Payable Account' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const typeLabel = (t: string) => ({ earning: 'Earning', deduction: 'Deduction', employer_cost: 'Employer Cost' }[t] ?? t)

const filteredMappings = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.mappings
  return props.mappings.filter(
    (m) =>
      m.component_code.toLowerCase().includes(q) ||
      m.component_label.toLowerCase().includes(q) ||
      m.gl_account.toLowerCase().includes(q)
  )
})

const { confirm } = useConfirm()

const switchCompany = (e: Event) => router.get(route('accounting.payroll-component-gl-mappings.index'), { company_id: (e.target as HTMLSelectElement).value }, { preserveState: true })

const destroy = (m: MappingRow) => {
  confirm({
    title: 'Delete Payroll GL Mapping?',
    description: `Delete the mapping for "${m.component_code}"? Payroll runs using it will fail loudly and queue for review until it's remapped.`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.payroll-component-gl-mappings.destroy', m.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Payroll GL Mappings" description="Which accounts a Payroll component posts against — earning/employer-cost components debit an expense account, deductions credit a payable.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.payroll-posting-failures.index', { company_id: selectedCompanyId })">
            Review Queue
          </SecondaryButton>
          <PrimaryButton :href="route('accounting.payroll-component-gl-mappings.create', { company_id: selectedCompanyId })">
            New Mapping
          </PrimaryButton>
        </div>
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
        :items="filteredMappings"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.payroll-gl-mappings"
        search-placeholder="Search component code, label, or GL account…"
        export-filename="payroll-gl-mappings"
        empty-title="No payroll GL mappings found"
        empty-description="Create account mappings for salary components, deductions, and employer contributions."
      >
        <template #cell-component_code="{ item }">
          <Link
            :href="route('accounting.payroll-component-gl-mappings.edit', (item as MappingRow).id)"
            class="font-mono font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as MappingRow).component_code }}
          </Link>
        </template>

        <template #cell-component_label="{ item }">
          <span class="font-medium text-ink-900">{{ (item as MappingRow).component_label }}</span>
        </template>

        <template #cell-component_type="{ item }">
          <span class="text-xs capitalize text-ink-700 font-medium">{{ typeLabel((item as MappingRow).component_type) }}</span>
        </template>

        <template #cell-gl_account="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as MappingRow).gl_account }}</span>
        </template>

        <template #cell-payable_account="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as MappingRow).payable_account ?? '—' }}</span>
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.payroll-component-gl-mappings.edit', (item as MappingRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="destroy(item as MappingRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
