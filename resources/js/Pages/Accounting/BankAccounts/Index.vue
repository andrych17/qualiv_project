<!-- ponytail: Accounting §3F cash/bank accounts — plain company-scoped list, same convention as TaxCodes. -->
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

interface BankAccountRow {
  id: number
  name: string
  bank_name: string | null
  account_number_masked: string | null
  currency_code: string
  gl_account_label: string
  is_active: boolean
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  bankAccounts: BankAccountRow[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'name', label: 'Account Name', sortable: true },
  { key: 'bank_name', label: 'Bank', sortable: true },
  { key: 'account_number_masked', label: 'Account No' },
  { key: 'currency_code', label: 'Currency' },
  { key: 'gl_account_label', label: 'GL Control Account' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredAccounts = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.bankAccounts
  return props.bankAccounts.filter(
    (b) => b.name.toLowerCase().includes(q) || (b.bank_name ?? '').toLowerCase().includes(q)
  )
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.bank-accounts.index'), { company_id: companyId }, { preserveState: true })
}

const { confirm } = useConfirm()
const confirmDelete = (bankAccount: BankAccountRow) => {
  confirm({
    title: `Delete bank account "${bankAccount.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.bank-accounts.destroy', bankAccount.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Cash & Bank Accounts" description="Each account reconciles to one GL cash/bank control account — its cash book is derived from the ledger, not a separate record.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.cash-transfers.create', { company_id: selectedCompanyId })">
            Settlement / Transfer Kas
          </SecondaryButton>
          <PrimaryButton :href="route('accounting.bank-accounts.create', { company_id: selectedCompanyId })">
            New Bank Account
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
        :items="filteredAccounts"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.bank-accounts"
        search-placeholder="Search account or bank…"
        export-filename="bank-accounts"
        status-rail-key="is_active"
        empty-title="No cash or bank accounts found"
        empty-description="Create bank accounts mapped to GL cash control accounts."
      >
        <template #cell-name="{ item }">
          <Link
            :href="route('accounting.bank-accounts.show', (item as BankAccountRow).id)"
            class="font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as BankAccountRow).name }}
          </Link>
        </template>

        <template #cell-bank_name="{ item }">
          <span class="text-xs text-ink-700 font-medium">{{ (item as BankAccountRow).bank_name ?? '—' }}</span>
        </template>

        <template #cell-account_number_masked="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as BankAccountRow).account_number_masked ?? '—' }}</span>
        </template>

        <template #cell-currency_code="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as BankAccountRow).currency_code }}</span>
        </template>

        <template #cell-gl_account_label="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as BankAccountRow).gl_account_label }}</span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as BankAccountRow).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('accounting.bank-accounts.edit', (item as BankAccountRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item as BankAccountRow)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
