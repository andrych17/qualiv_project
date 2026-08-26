<!-- Purchase Contract Detail & Spend Tracking (§3H) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ContractData {
  id: number
  uuid: string
  title: string
  type: string
  supplier: { id: number; name: string } | null
  value: number | null
  spend_amount: number
  spend_pct: number
  currency_code: string
  start_date: string
  end_date: string
  auto_renew: boolean
  notice_period_days: number
  status: string
  creator: { id: number; name: string } | null
  created_at: string
}

interface RelatedPo {
  id: number
  po_no: string
  status: string
  total_amount: number
  currency_code: string
  created_at: string | null
}

const props = defineProps<{
  contract: ContractData
  relatedOrders: RelatedPo[]
}>()

const showRenewModal = ref(false)

const renewForm = useForm({
  end_date: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10),
  value: props.contract.value,
})

const formatCurrency = (val: number | null, cur: string = 'IDR') => {
  if (val === null) return '—'
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: cur || 'IDR', maximumFractionDigits: 0 }).format(val)
}

const { confirm } = useConfirm()

const activate = () => router.post(route('purchase.contracts.activate', props.contract.id))
const terminate = () => {
  confirm({
    title: 'Terminate Contract?',
    description: 'Are you sure you want to terminate this contract?',
    variant: 'destructive',
    confirmText: 'Terminate',
    onConfirm: () => router.post(route('purchase.contracts.terminate', props.contract.id)),
  })
}

const submitRenew = () => {
  renewForm.post(route('purchase.contracts.renew', props.contract.id), {
    onSuccess: () => {
      showRenewModal.value = false
    },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="contract.title" :description="`Contract ID: ${contract.uuid}`">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('purchase.contracts.index')">Back</SecondaryButton>
          <SecondaryButton :href="route('purchase.contracts.edit', contract.id)">Edit</SecondaryButton>

          <PrimaryButton v-if="contract.status === 'draft'" @click="activate">
            Activate Contract
          </PrimaryButton>

          <SecondaryButton v-if="['active', 'expiring_soon', 'expired'].includes(contract.status)" @click="showRenewModal = true">
            🔄 Renew Contract
          </SecondaryButton>

          <button
            v-if="['active', 'expiring_soon'].includes(contract.status)"
            type="button"
            class="px-3 py-2 text-xs font-semibold text-rose-700 bg-rose-50 border border-rose-200 rounded-md hover:bg-rose-100"
            @click="terminate"
          >
            Terminate
          </button>
        </div>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="contracts" />
    </div>

    <!-- Top Status Strip -->
    <div class="mt-6 flex items-center justify-between p-4 bg-surface rounded-lg border border-border">
      <div class="flex items-center gap-4">
        <span class="text-xs font-semibold uppercase tracking-wider text-ink-500">Contract Status:</span>
        <StatusBadge :status="contract.status" />
      </div>
      <div class="text-xs text-ink-600">
        Supplier: <strong class="text-ink-900">{{ contract.supplier?.name ?? '—' }}</strong> • Type: <strong class="uppercase">{{ contract.type }}</strong>
      </div>
    </div>

    <!-- Spend Against Contract Progress Bar (§3H) -->
    <div class="mt-6">
      <Panel title="Spend Against Contract (§3H)">
        <div class="space-y-3">
          <div class="flex justify-between items-end text-sm">
            <div>
              <span class="text-xs text-ink-500 font-medium">Committed PO Spend:</span>
              <div class="text-xl font-bold text-ink-900">
                {{ formatCurrency(contract.spend_amount, contract.currency_code) }}
              </div>
            </div>
            <div class="text-right">
              <span class="text-xs text-ink-500 font-medium">Contract Ceiling:</span>
              <div class="text-xl font-bold text-ink-900">
                {{ formatCurrency(contract.value, contract.currency_code) }}
              </div>
            </div>
          </div>

          <div v-if="contract.value && contract.value > 0" class="space-y-1.5">
            <div class="w-full bg-border rounded-full h-3 overflow-hidden">
              <div
                class="h-full rounded-full transition-all duration-500"
                :class="contract.spend_pct > 100 ? 'bg-rose-500' : contract.spend_pct > 80 ? 'bg-amber-500' : 'bg-emerald-500'"
                :style="{ width: `${Math.min(contract.spend_pct, 100)}%` }"
              />
            </div>
            <div class="flex justify-between text-xs font-medium" :class="contract.spend_pct > 100 ? 'text-rose-700' : 'text-ink-600'">
              <span>{{ contract.spend_pct }}% Utilized</span>
              <span v-if="contract.value > contract.spend_amount">
                Remaining Balance: {{ formatCurrency(contract.value - contract.spend_amount, contract.currency_code) }}
              </span>
              <span v-else class="font-bold text-rose-700">
                ⚠️ Over-Ceiling by {{ formatCurrency(contract.spend_amount - contract.value, contract.currency_code) }}
              </span>
            </div>
          </div>
          <div v-else class="text-xs text-ink-500">
            Open-ended / project contract with no fixed ceiling amount.
          </div>
        </div>
      </Panel>
    </div>

    <!-- Contract Specifications Grid -->
    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
      <Panel title="Contract Parameters">
        <dl class="divide-y divide-border text-sm">
          <div class="py-2.5 flex justify-between">
            <dt class="text-ink-500">Contract Title</dt>
            <dd class="font-medium text-ink-900 text-right">{{ contract.title }}</dd>
          </div>
          <div class="py-2.5 flex justify-between">
            <dt class="text-ink-500">Supplier / Vendor</dt>
            <dd class="font-medium text-ink-900 text-right">{{ contract.supplier?.name ?? '—' }}</dd>
          </div>
          <div class="py-2.5 flex justify-between">
            <dt class="text-ink-500">Agreement Type</dt>
            <dd class="font-medium text-ink-900 capitalize">{{ contract.type }}</dd>
          </div>
          <div class="py-2.5 flex justify-between">
            <dt class="text-ink-500">Currency</dt>
            <dd class="font-medium text-ink-900">{{ contract.currency_code }}</dd>
          </div>
        </dl>
      </Panel>

      <Panel title="Period & Renewal">
        <dl class="divide-y divide-border text-sm">
          <div class="py-2.5 flex justify-between">
            <dt class="text-ink-500">Start Date</dt>
            <dd class="font-medium text-ink-900">{{ contract.start_date }}</dd>
          </div>
          <div class="py-2.5 flex justify-between">
            <dt class="text-ink-500">End Date</dt>
            <dd class="font-medium text-ink-900">{{ contract.end_date }}</dd>
          </div>
          <div class="py-2.5 flex justify-between">
            <dt class="text-ink-500">Renewal Notice Period</dt>
            <dd class="font-medium text-ink-900">{{ contract.notice_period_days }} Days</dd>
          </div>
          <div class="py-2.5 flex justify-between">
            <dt class="text-ink-500">Auto-Renewal Flag</dt>
            <dd class="font-medium" :class="contract.auto_renew ? 'text-emerald-700' : 'text-ink-500'">
              {{ contract.auto_renew ? 'Enabled' : 'Disabled' }}
            </dd>
          </div>
        </dl>
      </Panel>
    </div>

    <!-- Related Purchase Orders List -->
    <div class="mt-6">
      <Panel title="Related Purchase Orders Under Contract">
        <div v-if="relatedOrders.length > 0" class="divide-y divide-border text-sm">
          <div v-for="po in relatedOrders" :key="po.id" class="py-3 flex items-center justify-between">
            <div>
              <Link :href="route('purchase.orders.show', po.id)" class="font-semibold text-accent hover:underline">
                {{ po.po_no }}
              </Link>
              <div class="text-xs text-ink-500">Issued on {{ po.created_at }}</div>
            </div>
            <div class="flex items-center gap-4">
              <span class="font-semibold text-ink-900">{{ formatCurrency(po.total_amount, po.currency_code) }}</span>
              <StatusBadge :status="po.status" />
            </div>
          </div>
        </div>
        <div v-else class="text-sm text-ink-500 py-3">No purchase orders issued under this supplier in the contract period yet.</div>
      </Panel>
    </div>

    <!-- Renew Modal -->
    <Modal :show="showRenewModal" @close="showRenewModal = false">
      <form class="p-6 space-y-4" @submit.prevent="submitRenew">
        <h3 class="text-lg font-semibold text-ink-900">Renew Contract</h3>
        <p class="text-xs text-ink-600">
          Extend the contract duration and optionally adjust the ceiling value.
        </p>

        <FormInput
          v-model="renewForm.end_date"
          name="end_date"
          type="date"
          label="New End Date *"
          :error="renewForm.errors.end_date"
          required
        />

        <FormInput
          v-model.number="renewForm.value"
          name="value"
          type="number"
          step="0.01"
          min="0"
          label="New Ceiling Value (Optional)"
          :error="renewForm.errors.value"
        />

        <div class="flex justify-end gap-2 pt-4">
          <SecondaryButton @click="showRenewModal = false">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="renewForm.processing">
            Confirm Renewal
          </PrimaryButton>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>
