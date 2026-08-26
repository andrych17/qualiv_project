<!-- Returns List (§3J) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'

interface ReturnItem {
  id: number
  uuid: string
  reason_code: string
  status: string
  created_at: string
  customer: { id: number; name: string } | null
  order: { id: number; so_number: string } | null
  replacement_order: { id: number; so_number: string } | null
  lines: Array<{ qty_returned: number }>
}

const props = defineProps<{
  returns: {
    data: ReturnItem[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  statuses: string[]
  filters: { search?: string; status?: string }
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

const applyFilters = () => {
  router.get(route('sales.returns.index'), {
    search: search.value || undefined,
    status: status.value || undefined,
  }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Returns (RMA)"
      description="Process return merchandise authorizations, refunds via credit note, and replacement sales orders (§3J)."
    >
      <template #actions>
        <PrimaryButton :href="route('sales.returns.create')">New Return Request</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="returns" />
    </div>

    <!-- Filters -->
    <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <input
          v-model="search"
          @keydown.enter="applyFilters"
          type="text"
          placeholder="Search reason or customer…"
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

    <!-- Returns Table -->
    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Return UUID</th>
            <th class="py-3 px-4">Customer</th>
            <th class="py-3 px-4">Original Order</th>
            <th class="py-3 px-4">Reason</th>
            <th class="py-3 px-4">Items</th>
            <th class="py-3 px-4">Replacement SO</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="ret in props.returns.data" :key="ret.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-mono font-medium text-accent">
              <Link :href="route('sales.returns.show', ret.id)" class="hover:underline">
                {{ ret.uuid.slice(0, 8) }}…
              </Link>
            </td>
            <td class="py-3 px-4 font-medium text-ink-900">{{ ret.customer?.name ?? '-' }}</td>
            <td class="py-3 px-4">
              <Link v-if="ret.order" :href="route('sales.orders.show', ret.order.id)" class="text-accent hover:underline">
                {{ ret.order.so_number }}
              </Link>
              <span v-else class="text-ink-400">Direct RMA</span>
            </td>
            <td class="py-3 px-4 text-ink-700">{{ ret.reason_code }}</td>
            <td class="py-3 px-4 font-mono text-xs">{{ ret.lines.reduce((s, l) => s + Number(l.qty_returned), 0) }} units</td>
            <td class="py-3 px-4 text-xs font-semibold text-accent">
              <Link v-if="ret.replacement_order" :href="route('sales.orders.show', ret.replacement_order.id)" class="hover:underline">
                {{ ret.replacement_order.so_number }}
              </Link>
              <span v-else class="text-ink-400 font-normal">-</span>
            </td>
            <td class="py-3 px-4"><StatusBadge :status="ret.status" /></td>
            <td class="py-3 px-4 text-right">
              <Link :href="route('sales.returns.show', ret.id)" class="text-xs font-semibold text-accent hover:underline">
                View &rarr;
              </Link>
            </td>
          </tr>
          <tr v-if="props.returns.data.length === 0">
            <td colspan="8" class="py-8 text-center text-ink-500">No returns found.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
