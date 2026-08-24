<!-- ponytail: Accounting §3B Chart of Accounts — depth-indented flat listing per company, same convention as DMS's Folder tree. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
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
const filtered = computed(() => {
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
        <Link :href="route('accounting.companies.index')" class="mr-4 text-sm font-medium text-accent hover:underline">Companies</Link>
        <Link :href="route('accounting.inventory-gl-mappings.index')" class="mr-4 text-sm font-medium text-accent hover:underline">Inventory GL mappings</Link>
        <Link :href="route('accounting.payroll-component-gl-mappings.index')" class="mr-4 text-sm font-medium text-accent hover:underline">Payroll GL mappings</Link>
        <Link :href="route('accounting.reports.index')" class="mr-4 text-sm font-medium text-accent hover:underline">Reports</Link>
        <PrimaryButton :href="route('accounting.accounts.create', { company_id: selectedCompanyId })">New account</PrimaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <select
          :value="selectedCompanyId"
          class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>

        <input
          v-model="search"
          type="text"
          placeholder="Search accounts…"
          class="w-full max-w-xs rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        />

        <button
          v-if="!accounts.length && selectedCompanyId"
          type="button"
          class="ml-auto text-sm font-medium text-accent hover:underline"
          @click="seedStarter"
        >
          Seed starter COA
        </button>
      </div>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Code</th>
            <th class="py-2">Name</th>
            <th class="py-2">Type</th>
            <th class="py-2">Normal balance</th>
            <th class="py-2">Control</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in filtered" :key="a.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900">{{ a.account_code }}</td>
            <td class="py-2 text-ink-900" :style="{ paddingLeft: `${8 + a.depth * 16}px` }">{{ a.account_name }}</td>
            <td class="py-2 text-ink-700 capitalize">{{ a.account_type }}</td>
            <td class="py-2 text-ink-700 capitalize">{{ a.normal_balance }}</td>
            <td class="py-2 text-ink-700">{{ a.is_control_account ? 'Yes' : '—' }}</td>
            <td class="py-2"><StatusBadge :status="a.is_active ? 'active' : 'inactive'" /></td>
            <td class="py-2 text-right">
              <Link :href="route('accounting.accounts.edit', a.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmDelete(a)">Delete</button>
            </td>
          </tr>
          <tr v-if="!filtered.length"><td colspan="7" class="py-6 text-center text-ink-600">No accounts yet — seed the starter COA or create one.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
