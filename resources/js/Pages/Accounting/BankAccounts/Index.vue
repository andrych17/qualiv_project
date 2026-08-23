<!-- ponytail: Accounting §3F cash/bank accounts — plain company-scoped list, same convention as TaxCodes. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
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
        <PrimaryButton :href="route('accounting.bank-accounts.create', { company_id: selectedCompanyId })">New bank account</PrimaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <select
        :value="selectedCompanyId"
        class="mb-4 rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @change="switchCompany"
      >
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
      </select>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Name</th>
            <th class="py-2">Bank</th>
            <th class="py-2">Account no.</th>
            <th class="py-2">Currency</th>
            <th class="py-2">GL account</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in bankAccounts" :key="b.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2">
              <Link :href="route('accounting.bank-accounts.show', b.id)" class="font-medium text-accent hover:underline">{{ b.name }}</Link>
            </td>
            <td class="py-2 text-ink-700">{{ b.bank_name ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ b.account_number_masked ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ b.currency_code }}</td>
            <td class="py-2 text-ink-700">{{ b.gl_account_label }}</td>
            <td class="py-2"><StatusBadge :status="b.is_active ? 'active' : 'inactive'" /></td>
            <td class="py-2 text-right">
              <Link :href="route('accounting.bank-accounts.edit', b.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete(b)">Delete</button>
            </td>
          </tr>
          <tr v-if="!bankAccounts.length"><td colspan="7" class="py-6 text-center text-ink-600">No bank accounts yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
