<!-- ponytail: GL is a per-company account picker into AccountLedgerController::show(), same
     drill-down Trial Balance rows already use — no separate ledger engine to build. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'

interface AccountRow {
  id: number
  account_code: string
  account_name: string
  account_type: string
  normal_balance: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  accounts: AccountRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'account_code', label: 'Code', sortable: true },
  { key: 'account_name', label: 'Account Name', sortable: true },
  { key: 'account_type', label: 'Type', sortable: true },
  { key: 'normal_balance', label: 'Normal Balance' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredAccounts = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.accounts
  return props.accounts.filter((a) => a.account_name.toLowerCase().includes(q) || a.account_code.includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.general-ledger.index'), { company_id: companyId }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="General Ledger" description="Every account for this company — open one to see its full posted history.">
      <template #actions>
        <SecondaryButton :href="route('accounting.reports.trial-balance')">Trial Balance</SecondaryButton>
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
        :items="filteredAccounts"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.general_ledger"
        search-placeholder="Search accounts by code or name…"
        export-filename="general-ledger-accounts"
        empty-title="No accounts found"
        empty-description="Set up the Chart of Accounts for this company first."
      >
        <template #cell-account_code="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as AccountRow).account_code }}</span>
        </template>

        <template #cell-account_type="{ item }">
          <span class="text-xs capitalize text-ink-700 font-medium">{{ (item as AccountRow).account_type }}</span>
        </template>

        <template #cell-normal_balance="{ item }">
          <span class="text-xs font-mono capitalize text-ink-700">{{ (item as AccountRow).normal_balance }}</span>
        </template>

        <template #cell-actions="{ item }">
          <Link
            :href="route('accounting.reports.account-ledger', (item as AccountRow).id)"
            class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            View Ledger
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
