<!-- Purchase Requisition Show (§3B) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface LineItem {
  id: number
  line_no: number
  catalog_item: { id: number; item_code: string; description: string; unit: string } | null
  description: string
  qty: number
  estimated_unit_price: number
  line_total: number
  category: { id: number; name: string; kind: string } | null
  local_content_pct: number | null
}

interface OrderItem {
  id: number
  po_no: string
  status: string
  total_amount: number
}

const props = defineProps<{
  requisition: {
    id: number
    uuid: string
    pr_no: string
    requester: { id: number; name: string; email: string } | null
    cost_center: { id: number; code: string; name: string } | null
    creator: { id: number; name: string } | null
    needed_by: string | null
    subject_type: string | null
    subject_id: number | null
    status: string
    estimated_total: number
    budget_warning: boolean
    duplicate_warning: boolean
    notes: string | null
    created_at: string | null
    lines: LineItem[]
    orders: OrderItem[]
  }
  eligiblePartners: Array<{ id: number; name: string; type: string }>
}>()

const formatCurrency = (val: number) => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(val)
}

// Convert to PO Modal
const showConvertModal = ref(false)
const convertForm = useForm({
  supplier_id: null as number | null,
  expected_delivery_date: props.requisition.needed_by ?? '',
})

const submitConvert = () => {
  convertForm.post(route('purchase.requisitions.convert-to-po', props.requisition.id), {
    onSuccess: () => {
      showConvertModal.value = false
    },
  })
}

// Reject Modal
const showRejectModal = ref(false)
const rejectForm = useForm({
  reason: '',
})

const submitReject = () => {
  rejectForm.post(route('purchase.requisitions.reject', props.requisition.id), {
    onSuccess: () => {
      showRejectModal.value = false
    },
  })
}

const submitForApproval = () => {
  router.post(route('purchase.requisitions.submit', props.requisition.id))
}

const { confirm } = useConfirm()

const approve = () => {
  router.post(route('purchase.requisitions.approve', props.requisition.id))
}

const cancel = () => {
  confirm({
    title: 'Cancel Requisition?',
    description: 'Are you sure you want to cancel this requisition?',
    variant: 'destructive',
    confirmText: 'Cancel Requisition',
    onConfirm: () => router.post(route('purchase.requisitions.cancel', props.requisition.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="requisition.pr_no" description="Purchase Requisition (§3B).">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('purchase.requisitions.index')">Back to list</SecondaryButton>

          <!-- Draft / Rejected: Edit & Submit -->
          <Link
            v-if="requisition.status === 'draft' || requisition.status === 'rejected'"
            :href="route('purchase.requisitions.edit', requisition.id)"
            class="inline-flex items-center rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-ink-700 shadow-sm hover:bg-surface-elevated transition"
          >
            Edit
          </Link>
          <PrimaryButton
            v-if="requisition.status === 'draft' || requisition.status === 'rejected'"
            @click="submitForApproval"
          >
            Submit for approval
          </PrimaryButton>

          <!-- Pending Approval: Approve & Reject -->
          <PrimaryButton
            v-if="requisition.status === 'pending_approval'"
            @click="approve"
          >
            Approve
          </PrimaryButton>
          <DangerButton
            v-if="requisition.status === 'pending_approval'"
            @click="showRejectModal = true"
          >
            Reject
          </DangerButton>

          <!-- Approved: Convert to PO -->
          <PrimaryButton
            v-if="requisition.status === 'approved'"
            @click="showConvertModal = true"
          >
            Generate Purchase Order (PO)
          </PrimaryButton>

          <!-- Cancel action for non-converted, non-cancelled -->
          <SecondaryButton
            v-if="requisition.status !== 'converted' && requisition.status !== 'cancelled'"
            class="text-rose-600 hover:text-rose-700"
            @click="cancel"
          >
            Cancel PR
          </SecondaryButton>
        </div>
      </template>
    </PageHeader>

    <!-- Warnings alert if any -->
    <div v-if="requisition.budget_warning || requisition.duplicate_warning" class="mt-4 space-y-2">
      <div v-if="requisition.budget_warning" class="p-3 bg-amber-50 border border-amber-200 rounded-md text-amber-900 text-sm flex items-start gap-2">
        <span class="font-bold text-amber-700">⚠ Soft Budget Warning:</span>
        <span>The estimated total for this requisition may exceed the configured budget allocation for this period/cost center.</span>
      </div>
      <div v-if="requisition.duplicate_warning" class="p-3 bg-rose-50 border border-rose-200 rounded-md text-rose-900 text-sm flex items-start gap-2">
        <span class="font-bold text-rose-700">⚠ Duplicate Warning:</span>
        <span>A similar requisition with identical catalog item or description was submitted by this requester within the last 30 days.</span>
      </div>
    </div>

    <!-- Header Summary Panel -->
    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
      <Panel title="Overview" class="md:col-span-2">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
          <div>
            <dt class="text-ink-500 font-medium">Status</dt>
            <dd class="mt-1"><StatusBadge :status="requisition.status" /></dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Estimated Total</dt>
            <dd class="mt-1 text-base font-bold text-ink-900">{{ formatCurrency(requisition.estimated_total) }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Requester</dt>
            <dd class="mt-1 text-ink-900">{{ requisition.requester?.name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Cost Center / Dept</dt>
            <dd class="mt-1 text-ink-900">{{ requisition.cost_center ? `${requisition.cost_center.code} - ${requisition.cost_center.name}` : '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Needed By</dt>
            <dd class="mt-1 text-ink-900">{{ requisition.needed_by ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Created At</dt>
            <dd class="mt-1 text-ink-900">{{ requisition.created_at ?? '—' }}</dd>
          </div>
        </dl>

        <div v-if="requisition.notes" class="mt-4 pt-4 border-t border-border">
          <h4 class="text-xs font-semibold text-ink-500 uppercase tracking-wider">Notes / Justification</h4>
          <p class="mt-1 text-sm text-ink-700 whitespace-pre-line">{{ requisition.notes }}</p>
        </div>
      </Panel>

      <!-- Linked Orders / Status Panel -->
      <Panel title="Linked Documents">
        <div v-if="requisition.orders.length > 0" class="space-y-3">
          <div v-for="order in requisition.orders" :key="order.id" class="p-3 border border-border rounded-md bg-surface-elevated flex items-center justify-between">
            <div>
              <Link :href="route('purchase.orders.show', order.id)" class="text-sm font-semibold text-accent hover:underline">
                {{ order.po_no }}
              </Link>
              <div class="text-xs text-ink-500">{{ formatCurrency(order.total_amount) }}</div>
            </div>
            <StatusBadge :status="order.status" />
          </div>
        </div>
        <div v-else class="text-sm text-ink-500">
          No Purchase Orders generated yet.
        </div>
      </Panel>
    </div>

    <!-- Line Items Table -->
    <Panel title="Line Items" class="mt-6">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-border text-sm">
          <thead>
            <tr class="text-left text-xs font-semibold text-ink-500 uppercase tracking-wider">
              <th class="py-2.5 px-3 w-12 text-center">#</th>
              <th class="py-2.5 px-3">Item / Description</th>
              <th class="py-2.5 px-3 w-36">Category</th>
              <th class="py-2.5 px-3 w-24 text-right">Qty</th>
              <th class="py-2.5 px-3 w-36 text-right">Est. Unit Price</th>
              <th class="py-2.5 px-3 w-24 text-right">TKDN %</th>
              <th class="py-2.5 px-3 w-40 text-right">Est. Total</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="line in requisition.lines" :key="line.id">
              <td class="py-3 px-3 text-center text-ink-500">{{ line.line_no }}</td>
              <td class="py-3 px-3">
                <div class="font-medium text-ink-900">{{ line.description }}</div>
                <div v-if="line.catalog_item" class="text-xs text-ink-500 font-mono">
                  Catalog: {{ line.catalog_item.item_code }} ({{ line.catalog_item.unit }})
                </div>
              </td>
              <td class="py-3 px-3 text-ink-700">
                {{ line.category?.name ?? '—' }}
              </td>
              <td class="py-3 px-3 text-right font-medium text-ink-900">
                {{ line.qty }}
              </td>
              <td class="py-3 px-3 text-right text-ink-800">
                {{ formatCurrency(line.estimated_unit_price) }}
              </td>
              <td class="py-3 px-3 text-right text-ink-600">
                {{ line.local_content_pct !== null ? `${line.local_content_pct}%` : '—' }}
              </td>
              <td class="py-3 px-3 text-right font-semibold text-ink-900">
                {{ formatCurrency(line.line_total) }}
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-border font-bold text-ink-900">
              <td colspan="6" class="py-3 px-3 text-right">Grand Total:</td>
              <td class="py-3 px-3 text-right text-base">{{ formatCurrency(requisition.estimated_total) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </Panel>

    <!-- Convert to PO Modal -->
    <Modal :show="showConvertModal" @close="showConvertModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Generate Purchase Order from {{ requisition.pr_no }}</h3>
        <p class="mt-1 text-sm text-ink-600">Select the winning or preferred vendor partner to issue the PO (§3D).</p>

        <form class="mt-4 space-y-4" @submit.prevent="submitConvert">
          <FormSelect
            v-model="convertForm.supplier_id"
            name="supplier_id"
            label="Supplier / Vendor *"
            placeholder="Select vendor partner"
            :options="eligiblePartners.map((p) => ({ label: p.name, value: p.id }))"
            :error="convertForm.errors.supplier_id"
            required
          />

          <FormInput
            v-model="convertForm.expected_delivery_date"
            name="expected_delivery_date"
            type="date"
            label="Expected Delivery Date"
            :error="convertForm.errors.expected_delivery_date"
          />

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showConvertModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="convertForm.processing">Create Purchase Order</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Reject Modal -->
    <Modal :show="showRejectModal" @close="showRejectModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Reject Requisition</h3>
        <p class="mt-1 text-sm text-ink-600">Provide a reason for rejecting this purchase requisition.</p>

        <form class="mt-4 space-y-4" @submit.prevent="submitReject">
          <FormTextarea
            v-model="rejectForm.reason"
            name="reason"
            label="Rejection Reason"
            placeholder="Reason for rejection…"
            :rows="3"
            :error="rejectForm.errors.reason"
            required
          />

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showRejectModal = false">Cancel</SecondaryButton>
            <DangerButton type="submit" :disabled="rejectForm.processing">Confirm Rejection</DangerButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
