<!-- ponytail: Accounting §3K minimal Companies master — §3B's own dependency. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

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
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.companies
  return props.companies.filter((c) => c.legal_name.toLowerCase().includes(q))
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Companies" description="Legal entities inside this tenant — accounts, fiscal years, and journals all belong to one company.">
      <template #actions>
        <PrimaryButton :href="route('accounting.companies.create')">New company</PrimaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <input
        v-model="search"
        type="text"
        placeholder="Search companies…"
        class="mb-4 w-full max-w-xs rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
      />

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Legal name</th>
            <th class="py-2">NPWP</th>
            <th class="py-2">Base currency</th>
            <th class="py-2">FY start month</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in filtered" :key="c.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 text-ink-900">{{ c.legal_name }}</td>
            <td class="py-2 text-ink-700">{{ c.npwp ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ c.base_currency }}</td>
            <td class="py-2 text-ink-700">{{ c.fiscal_year_start_month }}</td>
            <td class="py-2"><StatusBadge :status="c.is_active ? 'active' : 'inactive'" /></td>
            <td class="py-2 text-right">
              <Link :href="route('accounting.companies.edit', c.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
              <Link :href="route('accounting.accounts.index', { company_id: c.id })" class="text-sm font-medium text-accent hover:underline">Accounts</Link>
            </td>
          </tr>
          <tr v-if="!filtered.length"><td colspan="6" class="py-6 text-center text-ink-600">No companies yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
