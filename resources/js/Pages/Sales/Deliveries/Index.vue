<!-- Deliveries List (§3H) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'

interface DeliveryItem {
  id: number
  uuid: string
  status: string
  carrier: string | null
  tracking_number: string | null
  shipped_at: string | null
  delivered_at: string | null
  created_at: string
  order: {
    id: number
    so_number: string
    customer: { id: number; name: string } | null
  } | null
  lines: Array<{ qty_shipped: number; sales_order_line: { description: string } | null }>
}

const props = defineProps<{
  deliveries: {
    data: DeliveryItem[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  statuses: string[]
  filters: { search?: string; status?: string }
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

const applyFilters = () => {
  router.get(route('sales.deliveries.index'), {
    search: search.value || undefined,
    status: status.value || undefined,
  }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Deliveries & Fulfillment"
      description="Pick, pack, ship, and track customer delivery notes with stock issue integration (§3H)."
    >
      <template #actions>
        <PrimaryButton :href="route('sales.deliveries.create')">New Delivery</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="deliveries" />
    </div>

    <!-- Filters -->
    <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <input
          v-model="search"
          @keydown.enter="applyFilters"
          type="text"
          placeholder="Search carrier, tracking #, or SO…"
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

    <!-- Deliveries Table -->
    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Delivery UUID</th>
            <th class="py-3 px-4">Sales Order #</th>
            <th class="py-3 px-4">Customer</th>
            <th class="py-3 px-4">Carrier & Tracking</th>
            <th class="py-3 px-4">Items</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="dlv in props.deliveries.data" :key="dlv.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-mono font-medium text-accent">
              <Link :href="route('sales.deliveries.show', dlv.id)" class="hover:underline">
                {{ dlv.uuid.slice(0, 8) }}…
              </Link>
            </td>
            <td class="py-3 px-4 font-semibold text-ink-900">
              <Link v-if="dlv.order" :href="route('sales.orders.show', dlv.order.id)" class="hover:underline text-accent">
                {{ dlv.order.so_number }}
              </Link>
              <span v-else>-</span>
            </td>
            <td class="py-3 px-4 text-ink-900">{{ dlv.order?.customer?.name ?? '-' }}</td>
            <td class="py-3 px-4 text-xs text-ink-600">
              <span v-if="dlv.carrier">{{ dlv.carrier }} ({{ dlv.tracking_number ?? 'No tracking' }})</span>
              <span v-else class="text-ink-400">Manual / Pickup</span>
            </td>
            <td class="py-3 px-4 font-mono text-xs">
              {{ dlv.lines.reduce((s, l) => s + Number(l.qty_shipped), 0) }} units
            </td>
            <td class="py-3 px-4"><StatusBadge :status="dlv.status" /></td>
            <td class="py-3 px-4 text-right">
              <Link :href="route('sales.deliveries.show', dlv.id)" class="text-xs font-semibold text-accent hover:underline">
                Manage &rarr;
              </Link>
            </td>
          </tr>
          <tr v-if="props.deliveries.data.length === 0">
            <td colspan="7" class="py-8 text-center text-ink-500">No deliveries found.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
