<!-- Contracts & Subscriptions List (§3L) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'

interface ContractItem {
  id: number
  uuid: string
  name: string
  status: string
  term_start: string
  term_end: string
  auto_renew: boolean
  customer: { id: number; name: string } | null
  subscriptions: Array<{
    recurring_amount: number
    billing_interval: string
  }>
}

const props = defineProps<{
  contracts: {
    data: ContractItem[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  statuses: string[]
  filters: { search?: string; status?: string }
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

const formatCurrency = (val: number, curr = 'IDR') => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(val)
}

const applyFilters = () => {
  router.get(route('sales.contracts.index'), {
    search: search.value || undefined,
    status: status.value || undefined,
  }, { preserveState: true })
}

const triggerRecurringBilling = () => {
  if (confirm('Run recurring billing cycle sweep now?')) {
    router.post(route('sales.contracts.recurring.process'))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Contracts & Recurring Billing"
      description="Service agreements, retainer subscriptions, and automated recurring billing schedules (§3L)."
    >
      <template #actions>
        <SecondaryButton @click="triggerRecurringBilling">Run Billing Cycle</SecondaryButton>
        <PrimaryButton :href="route('sales.contracts.create')">New Contract</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="contracts" />
    </div>

    <!-- Filters -->
    <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <input
          v-model="search"
          @keydown.enter="applyFilters"
          type="text"
          placeholder="Search contract name or customer…"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm text-ink-900 focus:border-accent focus:outline-none"
        />
        <select
          v-model="status"
          @change="applyFilters"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm text-ink-900 focus:border-accent focus:outline-none"
        >
          <option value="">All Statuses</option>
          <option v-for="st in props.statuses" :key="st" :value="st">{{ st.toUpperCase() }}</option>
        </select>
      </div>
    </div>

    <!-- Contracts Table -->
    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Contract Name</th>
            <th class="py-3 px-4">Customer</th>
            <th class="py-3 px-4">Term Duration</th>
            <th class="py-3 px-4">Recurring Value</th>
            <th class="py-3 px-4">Auto Renew</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="ctr in props.contracts.data" :key="ctr.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-semibold text-accent">
              <Link :href="route('sales.contracts.show', ctr.id)" class="hover:underline">
                {{ ctr.name }}
              </Link>
            </td>
            <td class="py-3 px-4 font-medium text-ink-900">{{ ctr.customer?.name ?? '-' }}</td>
            <td class="py-3 px-4 text-ink-600 text-xs font-mono">
              {{ ctr.term_start }} &rarr; {{ ctr.term_end }}
            </td>
            <td class="py-3 px-4 font-mono font-semibold text-ink-900">
              {{ formatCurrency(ctr.subscriptions.reduce((s, sub) => s + Number(sub.recurring_amount), 0)) }}
            </td>
            <td class="py-3 px-4 text-xs font-medium">
              <span v-if="ctr.auto_renew" class="text-emerald-600">Yes</span>
              <span v-else class="text-ink-400">No</span>
            </td>
            <td class="py-3 px-4"><StatusBadge :status="ctr.status" /></td>
            <td class="py-3 px-4 text-right">
              <Link :href="route('sales.contracts.show', ctr.id)" class="text-xs font-semibold text-accent hover:underline">
                View &rarr;
              </Link>
            </td>
          </tr>
          <tr v-if="props.contracts.data.length === 0">
            <td colspan="7" class="py-8 text-center text-ink-500">No contracts found.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
