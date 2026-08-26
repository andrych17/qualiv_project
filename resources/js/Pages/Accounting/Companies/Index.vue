<!-- ponytail: Accounting §3K minimal Companies master — §3B's own dependency. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'

interface CompanyRow {
  id: number
  legal_name: string
  npwp: string | null
  base_currency: string
  fiscal_year_start_month: number
  is_active: boolean
}

const props = defineProps<{ companies: CompanyRow[] }>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'legal_name', label: 'Legal Name', sortable: true },
  { key: 'npwp', label: 'NPWP', sortable: true },
  { key: 'base_currency', label: 'Base Currency' },
  { key: 'fiscal_year_start_month', label: 'FY Start Month' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredCompanies = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.companies
  return props.companies.filter((c) => c.legal_name.toLowerCase().includes(q) || (c.npwp ?? '').includes(q))
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Companies" description="Legal entities inside this tenant — accounts, fiscal years, and journals all belong to one company.">
      <template #actions>
        <PrimaryButton :href="route('accounting.companies.create')">New Company</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredCompanies"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.companies"
        search-placeholder="Search company or NPWP…"
        export-filename="companies"
        status-rail-key="is_active"
        empty-title="No companies found"
        empty-description="Register legal entity companies for accounting ledgers."
      >
        <template #cell-legal_name="{ item }">
          <Link
            :href="route('accounting.companies.edit', (item as CompanyRow).id)"
            class="font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as CompanyRow).legal_name }}
          </Link>
        </template>

        <template #cell-npwp="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as CompanyRow).npwp ?? '—' }}</span>
        </template>

        <template #cell-base_currency="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as CompanyRow).base_currency }}</span>
        </template>

        <template #cell-fiscal_year_start_month="{ item }">
          <span class="text-xs text-ink-700">Month {{ (item as CompanyRow).fiscal_year_start_month }}</span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as CompanyRow).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.companies.edit', (item as CompanyRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <Link
              :href="route('accounting.accounts.index', { company_id: (item as CompanyRow).id })"
              class="text-xs font-semibold text-ink-600 hover:text-ink-900 hover:underline"
            >
              COA &rarr;
            </Link>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
