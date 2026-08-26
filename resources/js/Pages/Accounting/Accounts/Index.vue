<!-- ponytail: Accounting §3B Chart of Accounts — depth-indented flat listing per company, same convention as DMS's Folder tree. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface AccountRow {
  id: number
  account_code: string
  account_name: string
  depth: number
  account_type: string
  normal_balance: string
  is_control_account: boolean
  is_active: boolean
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
  { key: 'is_control_account', label: 'Control Account' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredAccounts = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.accounts
  return props.accounts.filter((a) => a.account_name.toLowerCase().includes(q) || a.account_code.includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.accounts.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (account: AccountRow) => {
  confirm({
    title: `Delete account "${account.account_code} ${account.account_name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.accounts.destroy', account.id)),
  })
}

const seedStarter = () => {
  const companyId = props.selectedCompanyId
  if (!companyId) return
  confirm({
    title: 'Seed the starter Chart of Accounts?',
    description: 'Creates the standard Indonesian-grouping starter accounts (Kas, Bank, Piutang Usaha, Utang Usaha, ...) for this company.',
    confirmText: 'Seed accounts',
    onConfirm: () => router.post(route('accounting.accounts.seed-starter', companyId)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Chart of Accounts" description="Per-company COA — control accounts (AR/AP/Inventory) can only be posted to by their own subledger engine, never a manual journal.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.companies.index')">Companies</SecondaryButton>
          <SecondaryButton :href="route('accounting.inventory-gl-mappings.index')">Inventory GL</SecondaryButton>
          <SecondaryButton :href="route('accounting.payroll-component-gl-mappings.index')">Payroll GL</SecondaryButton>
          <SecondaryButton :href="route('accounting.reports.index')">Reports</SecondaryButton>
          <PrimaryButton :href="route('accounting.accounts.create', { company_id: selectedCompanyId })">New Account</PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
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

        <button
          v-if="!accounts.length && selectedCompanyId"
          type="button"
          class="text-xs font-semibold text-accent hover:underline"
          @click="seedStarter"
        >
          + Seed starter COA
        </button>
      </div>

      <DataTable
        :columns="columns"
        :items="filteredAccounts"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.accounts"
        search-placeholder="Search accounts by code or name…"
        export-filename="chart-of-accounts"
        status-rail-key="is_active"
        empty-title="No accounts found"
        empty-description="Seed the standard starter COA or create an account."
      >
        <template #cell-account_code="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as AccountRow).account_code }}</span>
        </template>

        <template #cell-account_name="{ item }">
          <span
            class="font-medium text-ink-900 block"
            :style="{ paddingLeft: `${(item as AccountRow).depth * 16}px` }"
          >
            <span v-if="(item as AccountRow).depth > 0" class="text-ink-400 mr-1 font-mono">└</span>
            {{ (item as AccountRow).account_name }}
          </span>
        </template>

        <template #cell-account_type="{ item }">
          <span class="text-xs capitalize text-ink-700 font-medium">{{ (item as AccountRow).account_type }}</span>
        </template>

        <template #cell-normal_balance="{ item }">
          <span class="text-xs font-mono capitalize text-ink-700">{{ (item as AccountRow).normal_balance }}</span>
        </template>

        <template #cell-is_control_account="{ item }">
          <span v-if="(item as AccountRow).is_control_account" class="inline-flex rounded-full bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent">
            Control
          </span>
          <span v-else class="text-xs text-ink-400">—</span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as AccountRow).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.accounts.edit', (item as AccountRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item as AccountRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
