<!-- Quotation Detail & Revisions (§3E) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface QuotationLine {
  id: number
  line_no: number
  item_type: string
  description: string
  quantity: number
  unit_price: number
  discount_amount: number
  tax_amount: number
  line_total: number
}

interface QuotationRevision {
  id: number
  uuid: string
  revision_no: number
  status: string
  created_at: string
  creator: { id: number; name: string } | null
}

interface QuotationDetail {
  id: number
  uuid: string
  quote_group_id: string
  revision_no: number
  validity_date: string | null
  status: string
  created_at: string
  customer: { id: number; name: string } | null
  opportunity: { id: number; name: string } | null
  price_list: { id: number; name: string } | null
  creator: { id: number; name: string } | null
  converted_sales_order: { id: number; so_number: string } | null
  lines: QuotationLine[]
  revisions: QuotationRevision[]
}

import { formatCurrency, formatDate } from '@/Utils/formatters'

const props = defineProps<{
  quotation: QuotationDetail
  subtotal: number
  totalDiscount: number
  totalTax: number
  totalAmount: number
}>()

const { confirm } = useConfirm()

const sendQuote = () => {
  router.post(route('sales.quotations.send', props.quotation.id))
}

const convertToOrder = () => {
  confirm({
    title: 'Convert to Sales Order?',
    description: 'Convert this quotation to a Sales Order?',
    confirmText: 'Convert to Order',
    onConfirm: () => router.post(route('sales.quotations.convert', props.quotation.id)),
  })
}

const cloneExpired = () => {
  router.post(route('sales.quotations.clone', props.quotation.id))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="`Quotation (Rev. ${props.quotation.revision_no})`"
      :description="`Quotation UUID: ${props.quotation.uuid}`"
    >
      <template #actions>
        <SecondaryButton :href="route('sales.quotations.index')">&larr; Back</SecondaryButton>
        <SecondaryButton :href="route('sales.quotations.edit', props.quotation.id)">
          {{ props.quotation.status === 'draft' ? 'Edit Draft' : 'Revise Quote' }}
        </SecondaryButton>

        <SecondaryButton
          v-if="['draft', 'approved'].includes(props.quotation.status)"
          @click="sendQuote"
        >
          Mark as Sent
        </SecondaryButton>

        <PrimaryButton
          v-if="props.quotation.status !== 'converted'"
          @click="convertToOrder"
        >
          Convert to Sales Order
        </PrimaryButton>

        <SecondaryButton
          v-if="props.quotation.status === 'expired'"
          @click="cloneExpired"
        >
          Clone as New Draft
        </SecondaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <!-- Left 2 cols: Quotation summary & Lines -->
      <div class="space-y-6 lg:col-span-2">
        <Panel title="Quotation Overview">
          <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
              <p class="text-xs text-ink-500 font-medium">Customer</p>
              <p class="mt-1 font-semibold text-ink-900">{{ props.quotation.customer?.name ?? 'Customer' }}</p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Status</p>
              <div class="mt-1"><StatusBadge :status="props.quotation.status" /></div>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Validity Expiration</p>
              <p class="mt-1 text-ink-900">{{ props.quotation.validity_date ?? 'No expiry date' }}</p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Linked Opportunity</p>
              <p class="mt-1 text-ink-900">{{ props.quotation.opportunity?.name ?? 'None' }}</p>
            </div>
          </div>

          <div v-if="props.quotation.converted_sales_order" class="mt-4 p-3 bg-emerald-50 border border-emerald-200 rounded-md text-sm text-emerald-800 flex items-center justify-between">
            <span>Converted to Sales Order: <strong>{{ props.quotation.converted_sales_order.so_number }}</strong></span>
            <Link :href="route('sales.orders.show', props.quotation.converted_sales_order.id)" class="text-xs font-semibold text-emerald-900 underline">View Order &rarr;</Link>
          </div>
        </Panel>

        <!-- Line Items -->
        <Panel title="Items & Services">
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
                <tr>
                  <th class="py-2.5 px-3">#</th>
                  <th class="py-2.5 px-3">Type</th>
                  <th class="py-2.5 px-3">Description</th>
                  <th class="py-2.5 px-3 text-right">Qty</th>
                  <th class="py-2.5 px-3 text-right">Unit Price</th>
                  <th class="py-2.5 px-3 text-right">Discount</th>
                  <th class="py-2.5 px-3 text-right">Tax</th>
                  <th class="py-2.5 px-3 text-right">Line Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="line in props.quotation.lines" :key="line.id" class="hover:bg-surface-50">
                  <td class="py-2.5 px-3 font-mono text-xs text-ink-400">{{ line.line_no }}</td>
                  <td class="py-2.5 px-3 capitalize text-xs text-ink-600">{{ line.item_type }}</td>
                  <td class="py-2.5 px-3 font-medium text-ink-900">{{ line.description }}</td>
                  <td class="py-2.5 px-3 text-right font-mono">{{ line.quantity }}</td>
                  <td class="py-2.5 px-3 text-right font-mono">{{ formatCurrency(Number(line.unit_price)) }}</td>
                  <td class="py-2.5 px-3 text-right font-mono text-emerald-600">{{ line.discount_amount > 0 ? formatCurrency(Number(line.discount_amount)) : '-' }}</td>
                  <td class="py-2.5 px-3 text-right font-mono">{{ line.tax_amount > 0 ? formatCurrency(Number(line.tax_amount)) : '-' }}</td>
                  <td class="py-2.5 px-3 text-right font-mono font-semibold text-ink-900">
                    {{ formatCurrency(Number(line.line_total) - Number(line.discount_amount) + Number(line.tax_amount)) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4 flex justify-end border-t border-border pt-4">
            <div class="w-72 space-y-1.5 text-sm">
              <div class="flex justify-between text-ink-600">
                <span>Subtotal:</span>
                <span class="font-mono">{{ formatCurrency(props.subtotal) }}</span>
              </div>
              <div class="flex justify-between text-ink-600">
                <span>Total Discount:</span>
                <span class="font-mono text-emerald-600">- {{ formatCurrency(props.totalDiscount) }}</span>
              </div>
              <div class="flex justify-between text-ink-600">
                <span>Total Tax:</span>
                <span class="font-mono">+ {{ formatCurrency(props.totalTax) }}</span>
              </div>
              <div class="flex justify-between font-bold text-ink-900 pt-2 border-t border-border">
                <span>Grand Total:</span>
                <span class="font-mono text-lg text-accent">{{ formatCurrency(props.totalAmount) }}</span>
              </div>
            </div>
          </div>
        </Panel>
      </div>

      <!-- Right col: Revision History & Metadata -->
      <div class="space-y-6">
        <Panel title="Revision History">
          <p class="text-xs text-ink-500 mb-3">
            Quotations use immutable revisions. Editing creates a new revision without destroying past records.
          </p>

          <div class="space-y-3">
            <div
              v-for="rev in props.quotation.revisions"
              :key="rev.id"
              class="rounded-md border p-3 text-sm transition"
              :class="rev.id === props.quotation.id ? 'border-accent bg-accent/5' : 'border-border bg-surface-0 hover:bg-surface-50'"
            >
              <div class="flex items-center justify-between">
                <span class="font-semibold text-ink-900">Revision {{ rev.revision_no }}</span>
                <StatusBadge :status="rev.status" />
              </div>
              <div class="mt-2 flex items-center justify-between text-xs text-ink-500">
                <span>{{ rev.creator?.name ?? 'System' }}</span>
                <Link
                  v-if="rev.id !== props.quotation.id"
                  :href="route('sales.quotations.show', rev.id)"
                  class="font-medium text-accent hover:underline"
                >
                  View &rarr;
                </Link>
                <span v-else class="font-semibold text-accent">Active view</span>
              </div>
            </div>
          </div>
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
