<!-- Return Detail & Lifecycle (§3J) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface ReturnLine {
  id: number
  qty_returned: number
  condition_notes: string | null
  sales_order_line?: {
    description: string
    unit_price: number
  }
}

interface ReturnDetail {
  id: number
  uuid: string
  reason_code: string
  status: string
  created_at: string
  customer: { id: number; name: string } | null
  order: { id: number; so_number: string } | null
  replacement_order: { id: number; so_number: string } | null
  creator: { id: number; name: string } | null
  lines: ReturnLine[]
}

const props = defineProps<{
  returnRecord: ReturnDetail
}>()

const approveReturn = () => {
  router.post(route('sales.returns.approve', props.returnRecord.id))
}

const receiveItems = () => {
  router.post(route('sales.returns.receive', props.returnRecord.id))
}

const processRefund = () => {
  if (confirm('Issue Accounting Credit Note refund and reverse representative commissions?')) {
    router.post(route('sales.returns.refund', props.returnRecord.id))
  }
}

const processReplacement = () => {
  if (confirm('Generate a replacement Sales Order for the returned goods?')) {
    router.post(route('sales.returns.replace', props.returnRecord.id))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Return (RMA)"
      :description="`Return UUID: ${props.returnRecord.uuid}`"
    >
      <template #actions>
        <SecondaryButton :href="route('sales.returns.index')">&larr; Back</SecondaryButton>

        <PrimaryButton
          v-if="props.returnRecord.status === 'pending'"
          @click="approveReturn"
        >
          Approve Return
        </PrimaryButton>

        <PrimaryButton
          v-if="props.returnRecord.status === 'approved'"
          @click="receiveItems"
        >
          Mark Goods Received
        </PrimaryButton>

        <SecondaryButton
          v-if="props.returnRecord.status === 'received'"
          @click="processRefund"
        >
          Issue Credit Note Refund
        </SecondaryButton>

        <PrimaryButton
          v-if="props.returnRecord.status === 'received'"
          @click="processReplacement"
        >
          Generate Replacement Order
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div class="space-y-6 lg:col-span-2">
        <Panel title="Return Information">
          <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
              <p class="text-xs text-ink-500 font-medium">Customer</p>
              <p class="mt-1 font-semibold text-ink-900">{{ props.returnRecord.customer?.name ?? 'Customer' }}</p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Return Status</p>
              <div class="mt-1"><StatusBadge :status="props.returnRecord.status" /></div>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Reason Code</p>
              <p class="mt-1 font-semibold text-ink-900">{{ props.returnRecord.reason_code }}</p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Original Order</p>
              <p class="mt-1 font-semibold text-accent">
                <Link v-if="props.returnRecord.order" :href="route('sales.orders.show', props.returnRecord.order.id)" class="hover:underline">
                  {{ props.returnRecord.order.so_number }}
                </Link>
                <span v-else class="text-ink-400">Direct RMA</span>
              </p>
            </div>
          </div>

          <div v-if="props.returnRecord.replacement_order" class="mt-4 p-3 bg-emerald-50 border border-emerald-200 rounded-md text-sm text-emerald-800 flex items-center justify-between">
            <span>Replacement Sales Order: <strong>{{ props.returnRecord.replacement_order.so_number }}</strong></span>
            <Link :href="route('sales.orders.show', props.returnRecord.replacement_order.id)" class="text-xs font-semibold text-emerald-900 underline">View Replacement Order &rarr;</Link>
          </div>
        </Panel>

        <!-- Returned items -->
        <Panel title="Returned Items">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
              <tr>
                <th class="py-2.5 px-3">Description</th>
                <th class="py-2.5 px-3 text-right">Qty Returned</th>
                <th class="py-2.5 px-3">Condition Notes</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="line in props.returnRecord.lines" :key="line.id" class="hover:bg-surface-50">
                <td class="py-2.5 px-3 font-medium text-ink-900">
                  {{ line.sales_order_line?.description ?? 'Returned Item' }}
                </td>
                <td class="py-2.5 px-3 text-right font-mono font-semibold text-ink-900">{{ line.qty_returned }}</td>
                <td class="py-2.5 px-3 text-ink-600 text-xs">{{ line.condition_notes ?? '-' }}</td>
              </tr>
            </tbody>
          </table>
        </Panel>
      </div>

      <!-- Right Column: Info -->
      <div class="space-y-6">
        <Panel title="Workflow & Accounting Rules">
          <p class="text-xs text-ink-600 leading-relaxed">
            Upon marking goods received, you can choose to refund via an Accounting Credit Note or generate a 0-value replacement order. Refunding will automatically trigger commission reversals for the sales rep.
          </p>
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
