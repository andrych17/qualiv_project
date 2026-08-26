<!-- Commission Settlement Detail & Actions (§3M) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface CommissionLine {
  id: number
  commission_rate: number
  commission_amount: number
  commission_plan?: { name: string }
  sales_order_line?: {
    description: string
    line_total: number
    order?: { so_number: string }
  }
}

interface SettlementDetail {
  id: number
  period_start: string
  period_end: string
  total_commission: number
  status: string
  approved_at: string | null
  paid_at: string | null
  rep: { id: number; name: string } | null
  approver: { id: number; name: string } | null
  lines: CommissionLine[]
}

const props = defineProps<{
  settlement: SettlementDetail
}>()

const formatCurrency = (val: number, curr = 'IDR') => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(val)
}

const approveSettlement = () => {
  if (confirm('Approve this commission settlement batch?')) {
    router.post(route('sales.commissions.approve', props.settlement.id))
  }
}

const markPaid = () => {
  if (confirm('Mark this commission settlement as paid out to representative?')) {
    router.post(route('sales.commissions.pay', props.settlement.id))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="`Settlement COMM-${props.settlement.id.toString().padStart(5, '0')}`"
      :description="`Sales commission settlement for ${props.settlement.rep?.name ?? 'Rep'}`"
    >
      <template #actions>
        <SecondaryButton :href="route('sales.commissions.index')">&larr; Back</SecondaryButton>

        <PrimaryButton
          v-if="props.settlement.status === 'draft'"
          @click="approveSettlement"
        >
          Approve Settlement
        </PrimaryButton>

        <PrimaryButton
          v-if="props.settlement.status === 'approved'"
          @click="markPaid"
        >
          Mark as Paid
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div class="space-y-6 lg:col-span-2">
        <Panel title="Settlement Information">
          <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
              <p class="text-xs text-ink-500 font-medium">Representative</p>
              <p class="mt-1 font-semibold text-ink-900">{{ props.settlement.rep?.name ?? '-' }}</p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Settlement Status</p>
              <div class="mt-1"><StatusBadge :status="props.settlement.status" /></div>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Period Duration</p>
              <p class="mt-1 font-mono text-ink-900">{{ props.settlement.period_start }} &rarr; {{ props.settlement.period_end }}</p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Total Payout</p>
              <p class="mt-1 font-mono font-bold text-accent text-base">{{ formatCurrency(Number(props.settlement.total_commission)) }}</p>
            </div>
          </div>
        </Panel>

        <!-- Settlement Lines -->
        <Panel title="Commission Line Details">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
              <tr>
                <th class="py-2.5 px-3">Order / Item</th>
                <th class="py-2.5 px-3">Applied Plan</th>
                <th class="py-2.5 px-3 text-right">Revenue Base</th>
                <th class="py-2.5 px-3 text-right">Commission Rate</th>
                <th class="py-2.5 px-3 text-right">Commission Earned</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="line in props.settlement.lines" :key="line.id" class="hover:bg-surface-50">
                <td class="py-2.5 px-3">
                  <div class="font-medium text-ink-900">{{ line.sales_order_line?.description ?? 'Item' }}</div>
                  <div v-if="line.sales_order_line?.order" class="text-xs text-accent font-semibold">
                    {{ line.sales_order_line.order.so_number }}
                  </div>
                </td>
                <td class="py-2.5 px-3 text-xs text-ink-600">{{ line.commission_plan?.name ?? 'Standard Plan' }}</td>
                <td class="py-2.5 px-3 text-right font-mono">{{ formatCurrency(Number(line.sales_order_line?.line_total || 0)) }}</td>
                <td class="py-2.5 px-3 text-right font-mono">{{ line.commission_rate }}%</td>
                <td class="py-2.5 px-3 text-right font-mono font-bold text-ink-900">{{ formatCurrency(Number(line.commission_amount)) }}</td>
              </tr>
            </tbody>
          </table>
        </Panel>
      </div>

      <!-- Right Column: Approvals -->
      <div class="space-y-6">
        <Panel title="Audit & Approval Details">
          <div class="space-y-3 text-sm">
            <div class="flex justify-between">
              <span class="text-ink-600">Approver:</span>
              <span class="font-medium">{{ props.settlement.approver?.name ?? 'Pending Approval' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-ink-600">Approved At:</span>
              <span>{{ props.settlement.approved_at ?? '-' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-ink-600">Paid At:</span>
              <span>{{ props.settlement.paid_at ?? '-' }}</span>
            </div>
          </div>
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
