<!-- Customer Sales & Credit Profiles Index (§3B / §3K) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import SalesMasterSubNav from '@/Components/sales/SalesMasterSubNav.vue'

interface PartnerItem {
  id: number
  name: string
  sales_profile?: {
    sales_team?: { name: string }
    price_list?: { name: string }
    assigned_rep?: { name: string }
  }
  credit_profile?: {
    credit_limit: number
    payment_terms_days: number
    on_hold: boolean
  }
}

const props = defineProps<{
  customers: {
    data: PartnerItem[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  filters: { search?: string }
}>()

const search = ref(props.filters.search ?? '')

const formatCurrency = (val: number | null | undefined, curr = 'IDR') => {
  if (!val) return 'IDR 0'
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(val)
}

const applyFilters = () => {
  router.get(route('sales.master.customers.index'), {
    search: search.value || undefined,
  }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Customer Sales & Credit Profiles"
      description="Configure default price lists, sales teams, reps, payment terms, and credit limits (§3B / §3K)."
    />

    <div class="mt-4">
      <SalesSubNav active="master" />
    </div>

    <div class="mt-4">
      <SalesMasterSubNav active="customers" />
    </div>

    <!-- Filter -->
    <div class="mt-6">
      <input
        v-model="search"
        @keydown.enter="applyFilters"
        type="text"
        placeholder="Search customer name…"
        class="w-72 rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm text-ink-900 focus:border-accent focus:outline-none"
      />
    </div>

    <!-- Table -->
    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Customer Name</th>
            <th class="py-3 px-4">Assigned Team / Rep</th>
            <th class="py-3 px-4">Price List</th>
            <th class="py-3 px-4">Payment Terms</th>
            <th class="py-3 px-4">Credit Limit</th>
            <th class="py-3 px-4">Credit Standing</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="c in props.customers.data" :key="c.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-semibold text-ink-900">
              <Link :href="route('sales.master.customers.edit', c.id)" class="hover:underline text-accent">
                {{ c.name }}
              </Link>
            </td>
            <td class="py-3 px-4 text-xs text-ink-700">
              <p v-if="c.sales_profile?.sales_team">Team: <strong>{{ c.sales_profile.sales_team.name }}</strong></p>
              <p v-if="c.sales_profile?.assigned_rep">Rep: {{ c.sales_profile.assigned_rep.name }}</p>
              <span v-if="!c.sales_profile?.sales_team && !c.sales_profile?.assigned_rep" class="text-ink-400">Unassigned</span>
            </td>
            <td class="py-3 px-4 text-xs text-ink-700">
              {{ c.sales_profile?.price_list?.name ?? 'Tenant Default' }}
            </td>
            <td class="py-3 px-4 text-xs font-mono">
              {{ c.credit_profile?.payment_terms_days ?? 30 }} Days
            </td>
            <td class="py-3 px-4 font-mono font-medium text-ink-900">
              {{ formatCurrency(c.credit_profile?.credit_limit) }}
            </td>
            <td class="py-3 px-4 text-xs font-bold">
              <span v-if="c.credit_profile?.on_hold" class="text-rose-600 bg-rose-50 px-2 py-0.5 rounded">
                ON HOLD
              </span>
              <span v-else class="text-emerald-600">
                Active
              </span>
            </td>
            <td class="py-3 px-4 text-right">
              <Link :href="route('sales.master.customers.edit', c.id)" class="text-xs font-medium text-accent hover:underline">
                Edit Profile &rarr;
              </Link>
            </td>
          </tr>
          <tr v-if="props.customers.data.length === 0">
            <td colspan="7" class="py-8 text-center text-ink-500">No customers found.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
