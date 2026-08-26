<!-- Contract Detail & Schedules (§3L) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'

interface BillingScheduleItem {
  id: number
  scheduled_date: string
  status: string
  accounting_invoice_id: number | null
}

interface SubscriptionLine {
  id: number
  description: string
  billing_interval: string
  recurring_amount: number
  next_billing_date: string
  recurring_schedules: BillingScheduleItem[]
}

interface ContractDetail {
  id: number
  uuid: string
  name: string
  status: string
  term_start: string
  term_end: string
  auto_renew: boolean
  created_at: string
  customer: { id: number; name: string } | null
  price_list: { id: number; name: string } | null
  creator: { id: number; name: string } | null
  subscriptions: SubscriptionLine[]
}

const props = defineProps<{
  contract: ContractDetail
}>()

const newTermEnd = ref('')
const showRenewModal = ref(false)

const formatCurrency = (val: number, curr = 'IDR') => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(val)
}

const activateContract = () => {
  if (confirm('Activate contract and generate recurring billing schedules?')) {
    router.post(route('sales.contracts.activate', props.contract.id))
  }
}

const cancelContract = () => {
  if (confirm('Cancel this contract and all future billing schedules?')) {
    router.post(route('sales.contracts.cancel', props.contract.id))
  }
}

const submitRenew = () => {
  router.post(route('sales.contracts.renew', props.contract.id), {
    term_end: newTermEnd.value,
  }, {
    onSuccess: () => {
      showRenewModal.value = false
    },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="props.contract.name"
      :description="`Contract UUID: ${props.contract.uuid}`"
    >
      <template #actions>
        <SecondaryButton :href="route('sales.contracts.index')">&larr; Back</SecondaryButton>

        <SecondaryButton
          v-if="props.contract.status === 'draft'"
          :href="route('sales.contracts.edit', props.contract.id)"
        >
          Edit Draft
        </SecondaryButton>

        <PrimaryButton
          v-if="props.contract.status === 'draft'"
          @click="activateContract"
        >
          Activate Contract
        </PrimaryButton>

        <SecondaryButton
          v-if="props.contract.status === 'active'"
          @click="showRenewModal = true"
        >
          Renew Contract
        </SecondaryButton>

        <DangerButton
          v-if="props.contract.status === 'active'"
          @click="cancelContract"
        >
          Cancel Contract
        </DangerButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div class="space-y-6 lg:col-span-2">
        <Panel title="Contract Summary">
          <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
              <p class="text-xs text-ink-500 font-medium">Customer</p>
              <p class="mt-1 font-semibold text-ink-900">{{ props.contract.customer?.name ?? 'Customer' }}</p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Status</p>
              <div class="mt-1"><StatusBadge :status="props.contract.status" /></div>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Term Start</p>
              <p class="mt-1 text-ink-900">{{ props.contract.term_start }}</p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Term End</p>
              <p class="mt-1 text-ink-900 font-semibold">{{ props.contract.term_end }}</p>
            </div>
          </div>
        </Panel>

        <!-- Subscriptions & Schedules -->
        <Panel title="Subscription Lines & Billing Schedules">
          <div class="space-y-6">
            <div
              v-for="sub in props.contract.subscriptions"
              :key="sub.id"
              class="border border-border rounded-lg p-4 bg-surface-50"
            >
              <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                <div>
                  <h4 class="font-semibold text-ink-900">{{ sub.description }}</h4>
                  <p class="text-xs text-ink-500 capitalize">Interval: {{ sub.billing_interval }}</p>
                </div>
                <div class="text-right">
                  <span class="font-mono text-base font-bold text-accent">{{ formatCurrency(Number(sub.recurring_amount)) }}</span>
                  <span class="text-xs text-ink-500"> / cycle</span>
                </div>
              </div>

              <!-- Schedules table -->
              <div class="mt-3 overflow-x-auto">
                <h5 class="text-xs font-semibold uppercase text-ink-500 mb-2">Billing Schedule Instances</h5>
                <table v-if="sub.recurring_schedules.length > 0" class="w-full text-left text-xs">
                  <thead class="bg-surface-100 text-ink-600">
                    <tr>
                      <th class="py-1.5 px-2">Scheduled Date</th>
                      <th class="py-1.5 px-2">Invoice Status</th>
                      <th class="py-1.5 px-2">Accounting Invoice</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-border bg-surface-0">
                    <tr v-for="sch in sub.recurring_schedules" :key="sch.id">
                      <td class="py-1.5 px-2 font-mono">{{ sch.scheduled_date }}</td>
                      <td class="py-1.5 px-2"><StatusBadge :status="sch.status" /></td>
                      <td class="py-1.5 px-2">
                        <span v-if="sch.accounting_invoice_id" class="text-accent font-semibold">
                          Invoice #{{ sch.accounting_invoice_id }}
                        </span>
                        <span v-else class="text-ink-400">Pending Billing Sweep</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
                <p v-else class="text-xs text-ink-500 italic py-2">
                  No billing schedules generated yet. Activate this contract to seed recurring schedules.
                </p>
              </div>
            </div>
          </div>
        </Panel>
      </div>

      <!-- Right Column: Settings -->
      <div class="space-y-6">
        <Panel title="Contract Parameters">
          <div class="space-y-3 text-sm">
            <div class="flex justify-between">
              <span class="text-ink-600">Auto Renewal:</span>
              <span class="font-semibold" :class="props.contract.auto_renew ? 'text-emerald-600' : 'text-ink-600'">
                {{ props.contract.auto_renew ? 'Enabled' : 'Disabled' }}
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-ink-600">Price List:</span>
              <span>{{ props.contract.price_list?.name ?? 'Default' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-ink-600">Created By:</span>
              <span>{{ props.contract.creator?.name ?? 'System' }}</span>
            </div>
          </div>
        </Panel>
      </div>
    </div>

    <!-- Renew Modal -->
    <div v-if="showRenewModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="w-full max-w-md rounded-lg bg-surface-0 p-6 shadow-xl border border-border">
        <h3 class="text-lg font-semibold text-ink-900">Renew Contract Agreement</h3>
        <p class="mt-1 text-sm text-ink-600">Extend the term end date for <strong>{{ props.contract.name }}</strong>.</p>

        <div class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700 mb-1">New Term End Date *</label>
            <input
              v-model="newTermEnd"
              type="date"
              class="w-full rounded border border-border bg-surface-0 py-2 px-3 text-sm text-ink-900 focus:outline-none"
              required
            />
          </div>

          <div class="flex items-center justify-end gap-2 pt-2">
            <SecondaryButton @click="showRenewModal = false">Cancel</SecondaryButton>
            <PrimaryButton @click="submitRenew" :disabled="!newTermEnd">Confirm Renewal</PrimaryButton>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
