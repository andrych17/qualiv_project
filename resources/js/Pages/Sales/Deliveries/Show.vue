<!-- Delivery Detail & Lifecycle (§3H) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'

interface DeliveryLine {
  id: number
  qty_shipped: number
  sales_order_line?: {
    description: string
    item_type: string
  }
}

interface DeliveryDetail {
  id: number
  uuid: string
  status: string
  carrier: string | null
  tracking_number: string | null
  source_location_id: number | null
  inventory_goods_issue_id: number | null
  shipped_at: string | null
  delivered_at: string | null
  created_at: string
  order: {
    id: number
    so_number: string
    customer: { id: number; name: string } | null
  } | null
  lines: DeliveryLine[]
}

const props = defineProps<{
  delivery: DeliveryDetail
  statuses: string[]
}>()

const carrier = ref(props.delivery.carrier ?? '')
const trackingNumber = ref(props.delivery.tracking_number ?? '')

const advanceStatus = (newStatus: string) => {
  router.patch(route('sales.deliveries.status', props.delivery.id), {
    status: newStatus,
    carrier: carrier.value,
    tracking_number: trackingNumber.value,
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Delivery Note"
      :description="`Delivery UUID: ${props.delivery.uuid}`"
    >
      <template #actions>
        <SecondaryButton :href="route('sales.deliveries.index')">&larr; Back</SecondaryButton>

        <SecondaryButton
          v-if="props.delivery.status === 'pending'"
          @click="advanceStatus('picked')"
        >
          Mark as Picked
        </SecondaryButton>

        <SecondaryButton
          v-if="props.delivery.status === 'picked'"
          @click="advanceStatus('packed')"
        >
          Mark as Packed
        </SecondaryButton>

        <PrimaryButton
          v-if="['pending', 'picked', 'packed'].includes(props.delivery.status)"
          @click="advanceStatus('shipped')"
        >
          Ship & Post Issue
        </PrimaryButton>

        <PrimaryButton
          v-if="props.delivery.status === 'shipped'"
          @click="advanceStatus('delivered')"
        >
          Mark as Delivered
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div class="space-y-6 lg:col-span-2">
        <Panel title="Delivery Information">
          <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
              <p class="text-xs text-ink-500 font-medium">Sales Order</p>
              <p class="mt-1 font-semibold text-accent">
                <Link v-if="props.delivery.order" :href="route('sales.orders.show', props.delivery.order.id)" class="hover:underline">
                  {{ props.delivery.order.so_number }}
                </Link>
                <span v-else>-</span>
              </p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Customer</p>
              <p class="mt-1 font-semibold text-ink-900">{{ props.delivery.order?.customer?.name ?? 'Customer' }}</p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Delivery Status</p>
              <div class="mt-1"><StatusBadge :status="props.delivery.status" /></div>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Shipped Timestamp</p>
              <p class="mt-1 text-ink-900">{{ props.delivery.shipped_at ?? 'Not shipped yet' }}</p>
            </div>
          </div>

          <div v-if="props.delivery.inventory_goods_issue_id" class="mt-4 p-3 bg-indigo-50 border border-indigo-200 rounded-md text-sm text-indigo-800 flex items-center justify-between">
            <span>Posted to Inventory Stock Ledger (Goods Issue #{{ props.delivery.inventory_goods_issue_id }})</span>
            <span class="text-xs font-semibold text-indigo-900">Stock Decrement Recorded &check;</span>
          </div>
        </Panel>

        <!-- Line Items -->
        <Panel title="Shipped Items">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
              <tr>
                <th class="py-2.5 px-3">Description</th>
                <th class="py-2.5 px-3">Type</th>
                <th class="py-2.5 px-3 text-right">Qty Shipped</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="line in props.delivery.lines" :key="line.id" class="hover:bg-surface-50">
                <td class="py-2.5 px-3 font-medium text-ink-900">{{ line.sales_order_line?.description ?? 'Item' }}</td>
                <td class="py-2.5 px-3 capitalize text-xs text-ink-600">{{ line.sales_order_line?.item_type ?? 'service' }}</td>
                <td class="py-2.5 px-3 text-right font-mono font-semibold text-ink-900">{{ line.qty_shipped }}</td>
              </tr>
            </tbody>
          </table>
        </Panel>
      </div>

      <!-- Carrier & Tracking Info -->
      <div class="space-y-6">
        <Panel title="Tracking & Logistics">
          <div class="space-y-4 text-sm">
            <FormInput
              label="Carrier / Shipping Provider"
              v-model="carrier"
              placeholder="e.g. JNE Regular"
            />
            <FormInput
              label="Tracking Number / AWB"
              v-model="trackingNumber"
              placeholder="e.g. JNE-987654321"
            />

            <div v-if="props.delivery.status !== 'delivered' && props.delivery.status !== 'cancelled'">
              <SecondaryButton
                type="button"
                @click="advanceStatus(props.delivery.status)"
                class="w-full justify-center text-xs"
              >
                Save Logistics Info
              </SecondaryButton>
            </div>
          </div>
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
