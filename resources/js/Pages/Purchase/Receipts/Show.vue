<!-- Purchase Goods Receipt Show (§3E) -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface LineItem {
  id: number
  po_line_id: number
  description: string
  quantity_received: number
  qty_ordered: number | null
  unit_cost: number | null
  condition_notes: string | null
  over_receipt_flag: boolean
}

const props = defineProps<{
  receipt: {
    id: number
    uuid: string
    gr_no: string
    po_id: number
    po_no: string | null
    supplier: { id: number; name: string } | null
    pr_no: string | null
    receiver: { id: number; name: string } | null
    received_at: string | null
    status: string
    discrepancy_notes: string | null
    lines: LineItem[]
  }
}>()

const formatCurrency = (val: number) => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(val)
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="receipt.gr_no" description="Goods Receipt Record (§3E).">
      <template #actions>
        <SecondaryButton :href="route('purchase.receipts.index')">Back to list</SecondaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
      <Panel title="Receipt Overview" class="md:col-span-2">
        <dl class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-3 text-sm">
          <div>
            <dt class="text-ink-500 font-medium">Status</dt>
            <dd class="mt-1"><StatusBadge :status="receipt.status" /></dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Purchase Order</dt>
            <dd class="mt-1">
              <Link :href="route('purchase.orders.show', receipt.po_id)" class="text-accent font-semibold hover:underline">
                {{ receipt.po_no }}
              </Link>
            </dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Supplier / Vendor</dt>
            <dd class="mt-1 font-medium text-ink-900">{{ receipt.supplier?.name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Receiver</dt>
            <dd class="mt-1 text-ink-900">{{ receipt.receiver?.name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Received At</dt>
            <dd class="mt-1 text-ink-900">{{ receipt.received_at ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Linked Requisition</dt>
            <dd class="mt-1 text-ink-700">{{ receipt.pr_no ?? '—' }}</dd>
          </div>
        </dl>

        <div v-if="receipt.discrepancy_notes" class="mt-4 pt-4 border-t border-border">
          <h4 class="text-xs font-semibold text-ink-500 uppercase tracking-wider">Discrepancy / Intake Notes</h4>
          <p class="mt-1 text-sm text-ink-700 whitespace-pre-line">{{ receipt.discrepancy_notes }}</p>
        </div>
      </Panel>

      <Panel title="Three-Way Match Role">
        <div class="text-sm text-ink-600 space-y-2">
          <p>
            This Goods Receipt serves as the <strong>authoritative physical record</strong> for the upcoming three-way match (§3F).
          </p>
          <p class="text-xs text-ink-500">
            When vendor bills arrive, billed quantities are reconciled directly against the received lines listed below.
          </p>
        </div>
      </Panel>
    </div>

    <!-- Line Items Table -->
    <Panel title="Received Items" class="mt-6">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-border text-sm">
          <thead>
            <tr class="text-left text-xs font-semibold text-ink-500 uppercase tracking-wider">
              <th class="py-2.5 px-3">Item Description</th>
              <th class="py-2.5 px-3 w-28 text-right">Qty Ordered</th>
              <th class="py-2.5 px-3 w-32 text-right">Qty Received</th>
              <th class="py-2.5 px-3 w-36 text-right">Unit Cost</th>
              <th class="py-2.5 px-3 w-64">Condition Notes</th>
              <th class="py-2.5 px-3 w-32 text-center">Flags</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="line in receipt.lines" :key="line.id">
              <td class="py-3 px-3 font-medium text-ink-900">
                {{ line.description }}
              </td>
              <td class="py-3 px-3 text-right text-ink-600">
                {{ line.qty_ordered ?? '—' }}
              </td>
              <td class="py-3 px-3 text-right font-bold text-emerald-700">
                {{ line.quantity_received }}
              </td>
              <td class="py-3 px-3 text-right text-ink-800">
                {{ line.unit_cost !== null ? formatCurrency(line.unit_cost) : '—' }}
              </td>
              <td class="py-3 px-3 text-ink-700">
                {{ line.condition_notes ?? '—' }}
              </td>
              <td class="py-3 px-3 text-center">
                <span v-if="line.over_receipt_flag" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                  Over-receipt
                </span>
                <span v-else class="text-xs text-ink-400">
                  Normal
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>
  </AppLayout>
</template>
