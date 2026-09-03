<!-- ponytail: Shop Floor Operation UI (MES_SPECS.md §3G) -->
<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { Check, AlertTriangle } from 'lucide-vue-next'
import ShopFloorLayout from '@/Components/layout/ShopFloorLayout.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import Modal from '@/Components/Modal.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import { formatNumber } from '@/Utils/formatters'

interface OpRow {
  id: number
  seq: number
  op_code: string
  op_name: string
  status: string | null
  is_current: boolean
  auto_issue_components: boolean
}

interface ComponentRow {
  sku: string | null
  name: string | null
  qty_per_parent_unit: number
  available: number
}

const props = defineProps<{
  order: {
    id: number
    order_number: string
    product: { id: number; sku: string; name: string } | null
    qty: number
    uom_code: string | null
    status: string
    warehouse_name: string | null
  }
  ops: OpRow[]
  currentOp: {
    id: number
    op_code: string
    op_name: string
    standard_output_qty: number | null
    auto_issue_components: boolean
    status: string | null
    is_last: boolean
  } | null
  components: ComponentRow[]
  locations: Array<{ value: number; label: string }>
}>()

const start = () => router.post(route('mes.shopFloor.start', props.order.id), {}, { preserveScroll: true })
const pause = () => router.post(route('mes.shopFloor.pause', props.order.id), {}, { preserveScroll: true })
const resume = () => router.post(route('mes.shopFloor.resume', props.order.id), {}, { preserveScroll: true })

const showCompleteModal = ref(false)
const completeForm = useForm({
  qty_completed: props.currentOp?.standard_output_qty ?? 1,
  qty_rejected: 0,
  location_id: null as number | null,
  reject_reason_code: '',
  lot_number: '',
  serial_number: '',
})

const submitComplete = () => {
  completeForm.post(route('mes.shopFloor.complete', props.order.id), {
    preserveScroll: true,
    onSuccess: () => {
      showCompleteModal.value = false
      completeForm.reset()
    },
  })
}

const showScrapModal = ref(false)
const scrapForm = useForm({
  output_type: 'waste',
  product_id: null as number | null,
  qty: 1,
  location_id: null as number | null,
  reason_code: '',
  disposition: 'scrap',
  operation_ref: null as number | null,
})

const openScrap = () => {
  scrapForm.product_id = props.order.product?.id ?? null
  scrapForm.operation_ref = props.currentOp?.id ?? null
  showScrapModal.value = true
}

const submitScrap = () => {
  scrapForm.post(route('mes.prodOrders.productionOutputs.store', props.order.id), {
    preserveScroll: true,
    onSuccess: () => { showScrapModal.value = false },
  })
}
</script>

<template>
  <ShopFloorLayout :exit-href="route('mes.prodOrders.show', order.id)" :title="order.order_number">
    <div class="mx-auto max-w-3xl space-y-6">
      <Panel>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">Order</div>
            <div class="mt-1 font-mono text-lg font-bold text-ink-900">{{ order.order_number }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">Product</div>
            <div class="mt-1 text-lg font-semibold text-ink-900">{{ order.product?.sku }}</div>
            <div class="text-xs text-ink-600">{{ order.product?.name }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">Target</div>
            <div class="mt-1 font-mono text-lg font-bold text-ink-900">{{ formatNumber(order.qty) }} {{ order.uom_code }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">Status</div>
            <div class="mt-1"><StatusBadge :status="order.status" /></div>
          </div>
        </div>
      </Panel>

      <Panel title="Routing Sequence">
        <div class="space-y-2">
          <div
            v-for="op in ops"
            :key="op.id"
            class="flex items-center justify-between rounded-md border p-3"
            :class="op.is_current ? 'border-accent bg-accent/5' : 'border-border'"
          >
            <div class="flex items-center gap-3">
              <span class="font-mono text-xs text-ink-500">{{ op.seq }}</span>
              <div>
                <div class="text-sm font-semibold text-ink-900">{{ op.op_code }} — {{ op.op_name }}</div>
              </div>
            </div>
            <StatusBadge :status="op.status ?? 'pending'" />
          </div>
        </div>
      </Panel>

      <Panel v-if="currentOp" :title="`Current Operation — ${currentOp.op_code} ${currentOp.op_name}`">
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <PrimaryButton
              v-if="currentOp.status === null"
              class="justify-center py-4 text-base"
              @click="start"
            >
              START
            </PrimaryButton>
            <SecondaryButton
              v-if="currentOp.status === 'operation_started'"
              class="justify-center py-4 text-base"
              @click="pause"
            >
              PAUSE
            </SecondaryButton>
            <PrimaryButton
              v-if="currentOp.status === 'operation_paused'"
              class="justify-center py-4 text-base"
              @click="resume"
            >
              RESUME
            </PrimaryButton>
            <PrimaryButton
              v-if="currentOp.status === 'operation_started' || currentOp.status === 'operation_paused'"
              class="justify-center bg-signal-success py-4 text-base hover:bg-signal-success/90"
              @click="showCompleteModal = true"
            >
              COMPLETE
            </PrimaryButton>
            <DangerButton class="justify-center py-4 text-base" @click="openScrap">SCRAP</DangerButton>
          </div>

          <div v-if="components.length > 0">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Materials (BOM)</p>
            <div class="mt-2 space-y-1.5">
              <div v-for="(c, i) in components" :key="c.sku ?? i" class="flex items-center gap-2 text-sm">
                <Check v-if="c.available >= c.qty_per_parent_unit" class="h-4 w-4 shrink-0 text-signal-success" />
                <AlertTriangle v-else class="h-4 w-4 shrink-0 text-signal-warning" />
                <span class="text-ink-900">{{ c.sku }} — {{ c.name }}</span>
                <span class="text-ink-500">({{ formatNumber(c.available) }} available, needs {{ formatNumber(c.qty_per_parent_unit) }}/unit)</span>
              </div>
            </div>
          </div>
        </div>
      </Panel>

      <Panel v-else title="All operations complete">
        <p class="text-sm text-ink-600">This order's routing has no more operations to execute.</p>
      </Panel>
    </div>

    <!-- Complete Operation Modal -->
    <Modal :show="showCompleteModal" max-width="md" @close="showCompleteModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Complete {{ currentOp?.op_code }}</h3>
        <p class="mt-1 text-sm text-ink-600">
          <span v-if="currentOp?.auto_issue_components">Auto-issues this order's BOM components 1:1, scaled to qty completed (§3G).</span>
          <span v-if="currentOp?.is_last"> This is the final operation — posts finished output and completes the order.</span>
        </p>

        <form class="mt-4 space-y-4" @submit.prevent="submitComplete">
          <div class="grid grid-cols-2 gap-4">
            <FormNumberInput v-model="completeForm.qty_completed" name="qty_completed" label="Qty Completed" :decimals="4" :error="completeForm.errors.qty_completed" required />
            <FormNumberInput v-model="completeForm.qty_rejected" name="qty_rejected" label="Qty Rejected" :decimals="4" :error="completeForm.errors.qty_rejected" />
          </div>

          <FormSelect
            v-if="currentOp?.auto_issue_components || currentOp?.is_last"
            v-model="completeForm.location_id"
            name="location_id"
            label="Location"
            :options="locations"
            :error="completeForm.errors.location_id"
            required
          />

          <FormInput v-if="completeForm.qty_rejected > 0" v-model="completeForm.reject_reason_code" name="reject_reason_code" label="Reject Reason Code" :error="completeForm.errors.reject_reason_code" />

          <template v-if="currentOp?.is_last">
            <FormInput v-model="completeForm.lot_number" name="lot_number" label="Finished lot (if batch-tracked)" :error="completeForm.errors.lot_number" />
            <FormInput v-model="completeForm.serial_number" name="serial_number" label="Finished serial (if serial-tracked)" :error="completeForm.errors.serial_number" />
          </template>

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showCompleteModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="completeForm.processing">Complete</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Scrap Modal (§3N handoff — records waste output scoped to the current operation) -->
    <Modal :show="showScrapModal" max-width="md" @close="showScrapModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Scrap</h3>
        <p class="mt-1 text-sm text-ink-600">Records a waste output row scoped to {{ currentOp?.op_code }}.</p>

        <form class="mt-4 space-y-4" @submit.prevent="submitScrap">
          <FormNumberInput v-model="scrapForm.qty" name="qty" label="Quantity" :decimals="4" :error="scrapForm.errors.qty" required />
          <FormSelect v-model="scrapForm.location_id" name="location_id" label="Location" :options="locations" :error="scrapForm.errors.location_id" />
          <FormInput v-model="scrapForm.reason_code" name="reason_code" label="Reason Code" placeholder="e.g. bolt_torque_out_of_spec" :error="scrapForm.errors.reason_code" required />
          <FormSelect
            v-model="scrapForm.disposition"
            name="disposition"
            label="Disposition"
            :options="[{ value: 'scrap', label: 'Scrap' }, { value: 'rework', label: 'Rework' }]"
            :error="scrapForm.errors.disposition"
          />

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showScrapModal = false">Cancel</SecondaryButton>
            <DangerButton type="submit" :disabled="scrapForm.processing">Record Scrap</DangerButton>
          </div>
        </form>
      </div>
    </Modal>
  </ShopFloorLayout>
</template>
