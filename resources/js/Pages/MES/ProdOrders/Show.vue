<!-- ponytail: Production Order detail + event ledger + material consumption/output (MES_SPECS.md §3A/§3C/§3J) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatNumber } from '@/Utils/formatters'

interface EventItem {
  id: number
  event_type: string
  payload: Record<string, unknown> | null
  occurred_at: string | null
  user_name: string | null
  machine_code: string | null
}

interface MaterialConsumptionItem {
  id: number
  material_sku: string | null
  material_name: string | null
  lot_number: string | null
  serial_number: string | null
  qty: number
  uom_code: string | null
  type: string
  created_at: string | null
}

interface ProductionOutputItem {
  id: number
  output_type: string
  product_sku: string | null
  product_name: string | null
  lot_number: string | null
  serial_number: string | null
  qty: number
  uom_code: string | null
  reason_code: string | null
  disposition: string | null
  created_at: string | null
  rework_order: { id: number; order_number: string } | null
}

interface SerialLinkItem {
  id: number
  serial_number: string | null
  component_serial_number: string | null
  component_lot_number: string | null
  material_sku: string | null
  material_name: string | null
  created_at: string | null
}

interface QcCharacteristic {
  id: number
  characteristic_name: string
  spec_type: string
  target_value: number | null
  min_value: number | null
  max_value: number | null
  uom_code: string | null
}

interface QcSampleItem {
  id: number
  sample_number: string
  taken_by_name: string | null
  taken_at: string | null
  results: Array<{ characteristic_name: string | null; actual_value: number | null; result: string }>
}

interface QcHoldItem {
  id: number
  subject_type: string
  subject_id: number
  reason: string | null
  status: string
  released_by_name: string | null
  released_at: string | null
  created_at: string | null
}

const props = defineProps<{
  order: {
    id: number
    order_number: string
    product: { id: number; sku: string; name: string } | null
    production_model: string
    bom_version: number | null
    recipe_version: number | null
    routing_version: number | null
    qty: number
    uom_code: string | null
    planned_start: string | null
    planned_end: string | null
    actual_start: string | null
    actual_end: string | null
    priority: string
    warehouse_name: string | null
    line_area: string | null
    status: string
    parent_order: { id: number; order_number: string } | null
    is_rework_order: boolean
    created_at: string | null
    yield: { good_qty: number; scrap_qty: number; yield_pct: number | null }
    events: EventItem[]
    material_consumptions: MaterialConsumptionItem[]
    production_outputs: ProductionOutputItem[]
    serial_links: SerialLinkItem[]
  }
  locations: Array<{ value: number; label: string }>
  routingOps: Array<{ value: number; label: string }>
  qcPlan: { id: number; name: string; characteristics: QcCharacteristic[] } | null
  qcSamples: QcSampleItem[]
  qcHolds: QcHoldItem[]
}>()

const canRecordMovements = ['released', 'in_progress', 'paused'].includes(props.order.status)

const { confirm } = useConfirm()

const release = () => {
  confirm({
    title: `Release ${props.order.order_number}?`,
    description: 'This allows material reservation/issue to begin and writes the order_released ledger event.',
    confirmText: 'Release',
    onConfirm: () => router.post(route('mes.prodOrders.release', props.order.id)),
  })
}

const cancelOrder = () => {
  confirm({
    title: `Cancel ${props.order.order_number}?`,
    variant: 'destructive',
    confirmText: 'Cancel Order',
    onConfirm: () => router.post(route('mes.prodOrders.cancel', props.order.id)),
  })
}

// Material Consumption modal
const showConsumptionModal = ref(false)
const consumptionForm = useForm({
  type: 'issue',
  material_product_id: null as number | null,
  operation_ref: null as number | null,
  location_id: null as number | null,
  lot_id: null as number | null,
  serial_number: '',
  qty: 1,
  uom_code: props.order.uom_code ?? '',
})

const submitConsumption = () => {
  consumptionForm.post(route('mes.prodOrders.materialConsumptions.store', props.order.id), {
    preserveScroll: true,
    onSuccess: () => {
      showConsumptionModal.value = false
      consumptionForm.reset()
    },
  })
}

// Production Output modal
const showOutputModal = ref(false)
const outputForm = useForm({
  output_type: 'finished',
  product_id: null as number | null,
  operation_ref: null as number | null,
  location_id: null as number | null,
  lot_number: '',
  serial_number: '',
  qty: 1,
  uom_code: props.order.uom_code ?? '',
  reason_code: '',
  disposition: '',
})

const submitOutput = () => {
  outputForm.post(route('mes.prodOrders.productionOutputs.store', props.order.id), {
    preserveScroll: true,
    onSuccess: () => {
      showOutputModal.value = false
      outputForm.reset()
    },
  })
}

const sendToRework = (output: ProductionOutputItem) => {
  confirm({
    title: `Send ${formatNumber(output.qty)} ${output.uom_code ?? ''} to rework?`,
    description: 'Creates a child production order starting at this routing\'s rework-flagged operation (§3N).',
    confirmText: 'Send to Rework',
    onConfirm: () => router.post(route('mes.productionOutputs.rework', output.id)),
  })
}

// QC Sample modal (§3L) — only offered once a plan exists for this order's product
const showQcModal = ref(false)
const qcForm = useForm({
  order_id: props.order.id,
  output_id: null as number | null,
  results: [] as Array<{ characteristic_id: number; actual_value: number | null; result: string }>,
})

const openQcModal = () => {
  qcForm.output_id = null
  qcForm.results = (props.qcPlan?.characteristics ?? []).map((c) => ({
    characteristic_id: c.id,
    actual_value: c.target_value,
    result: 'pass',
  }))
  showQcModal.value = true
}

const finishedOutputs = props.order.production_outputs.filter((o) => o.output_type !== 'waste')

const submitQcSample = () => {
  qcForm.post(route('mes.qcSamples.store'), {
    preserveScroll: true,
    onSuccess: () => { showQcModal.value = false },
  })
}

const releaseHold = (hold: QcHoldItem) => {
  confirm({
    title: `Release hold #${hold.id}?`,
    description: hold.reason ?? undefined,
    confirmText: 'Release',
    onConfirm: () => router.post(route('mes.qcHolds.release', hold.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="order.order_number" description="Production Order (MES_SPECS.md §3A).">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('mes.prodOrders.index')">Back to list</SecondaryButton>
          <SecondaryButton v-if="order.status === 'draft'" :href="route('mes.prodOrders.edit', order.id)">Edit</SecondaryButton>
          <PrimaryButton v-if="order.status === 'draft'" @click="release">Release</PrimaryButton>
          <PrimaryButton
            v-if="canRecordMovements && order.production_model === 'assembly'"
            :href="route('mes.shopFloor.show', order.id)"
          >
            Shop Floor
          </PrimaryButton>
          <PrimaryButton
            v-if="canRecordMovements && order.production_model === 'process'"
            :href="route('mes.shopFloor.batch.show', order.id)"
          >
            Batch Execution
          </PrimaryButton>
          <DangerButton v-if="!['completed', 'cancelled'].includes(order.status)" @click="cancelOrder">Cancel Order</DangerButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-3">
      <Panel title="Order Details" class="md:col-span-2">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm md:grid-cols-3">
          <div>
            <dt class="font-medium text-ink-500">Status</dt>
            <dd class="mt-1"><StatusBadge :status="order.status" /></dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Production Model</dt>
            <dd class="mt-1 text-ink-900 uppercase">{{ order.production_model }}</dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Product</dt>
            <dd class="mt-1 font-semibold text-ink-900">{{ order.product?.sku }} — {{ order.product?.name }}</dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Quantity</dt>
            <dd class="mt-1 font-mono text-ink-900">{{ formatNumber(order.qty) }} {{ order.uom_code }}</dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Yield (§3N)</dt>
            <dd class="mt-1 font-mono text-ink-900">
              <span v-if="order.yield.yield_pct !== null">{{ order.yield.yield_pct }}%</span>
              <span v-else class="text-ink-500">—</span>
              <span class="text-xs text-ink-500"> ({{ formatNumber(order.yield.good_qty) }} good / {{ formatNumber(order.yield.scrap_qty) }} scrap)</span>
            </dd>
          </div>
          <div v-if="order.is_rework_order">
            <dt class="font-medium text-ink-500">Rework Order</dt>
            <dd class="mt-1"><StatusBadge status="rework" /></dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Master Data Version</dt>
            <dd class="mt-1 text-ink-900">
              <span v-if="order.production_model === 'assembly'">BOM v{{ order.bom_version }}, Routing v{{ order.routing_version }}</span>
              <span v-else>Recipe v{{ order.recipe_version }}</span>
            </dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Priority</dt>
            <dd class="mt-1 text-ink-900 capitalize">{{ order.priority }}</dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Warehouse</dt>
            <dd class="mt-1 text-ink-900">{{ order.warehouse_name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Production Line / Area</dt>
            <dd class="mt-1 text-ink-900">{{ order.line_area ?? '—' }}</dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Parent Order</dt>
            <dd class="mt-1">
              <Link v-if="order.parent_order" :href="route('mes.prodOrders.show', order.parent_order.id)" class="font-medium text-accent hover:underline">
                {{ order.parent_order.order_number }}
              </Link>
              <span v-else class="text-ink-500">—</span>
            </dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Planned Start / End</dt>
            <dd class="mt-1 text-ink-900">{{ order.planned_start ?? '—' }} → {{ order.planned_end ?? '—' }}</dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Actual Start / End</dt>
            <dd class="mt-1 text-ink-900">{{ order.actual_start ?? '—' }} → {{ order.actual_end ?? '—' }}</dd>
          </div>
          <div>
            <dt class="font-medium text-ink-500">Created At</dt>
            <dd class="mt-1 text-ink-900">{{ order.created_at ?? '—' }}</dd>
          </div>
        </dl>
      </Panel>

      <Panel title="Lifecycle">
        <p class="text-sm text-ink-600">
          draft → released → in_progress → (paused) → completed / cancelled
        </p>
        <p class="mt-2 text-xs text-ink-600">
          Shop-floor execution (start/pause/complete) ships with the Shop Floor UI (§3G/§3I), not built yet — this build covers release, cancel, and material consumption/output (§3J) only.
        </p>
      </Panel>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">
      <Panel title="Material Consumption (§3J)">
        <template #actions>
          <PrimaryButton v-if="canRecordMovements" @click="showConsumptionModal = true">Record Movement</PrimaryButton>
        </template>

        <div v-if="order.material_consumptions.length === 0" class="text-sm text-ink-600">No material movements recorded yet.</div>
        <div v-else class="space-y-2">
          <div
            v-for="c in order.material_consumptions"
            :key="c.id"
            class="flex items-center justify-between rounded-md border border-border p-2.5"
          >
            <div>
              <div class="text-sm font-medium text-ink-900">{{ c.material_sku }} — {{ c.material_name }}</div>
              <div class="text-xs text-ink-500">
                {{ formatNumber(c.qty) }} {{ c.uom_code }}
                <span v-if="c.lot_number"> · Lot {{ c.lot_number }}</span>
                <span v-if="c.serial_number"> · SN {{ c.serial_number }}</span>
                · {{ c.created_at }}
              </div>
            </div>
            <StatusBadge :status="c.type" />
          </div>
        </div>
      </Panel>

      <Panel title="Production Output (§3J)">
        <template #actions>
          <PrimaryButton v-if="canRecordMovements" @click="showOutputModal = true">Record Output</PrimaryButton>
        </template>

        <div v-if="order.production_outputs.length === 0" class="text-sm text-ink-600">No output recorded yet.</div>
        <div v-else class="space-y-2">
          <div
            v-for="o in order.production_outputs"
            :key="o.id"
            class="flex items-center justify-between rounded-md border border-border p-2.5"
          >
            <div>
              <div class="text-sm font-medium text-ink-900">{{ o.product_sku }} — {{ o.product_name }}</div>
              <div class="text-xs text-ink-500">
                {{ formatNumber(o.qty) }} {{ o.uom_code }}
                <span v-if="o.lot_number"> · Lot {{ o.lot_number }}</span>
                <span v-if="o.serial_number"> · SN {{ o.serial_number }}</span>
                <span v-if="o.reason_code"> · {{ o.reason_code }}</span>
                · {{ o.created_at }}
              </div>
              <div v-if="o.disposition === 'rework'" class="mt-1 text-xs">
                <Link
                  v-if="o.rework_order"
                  :href="route('mes.prodOrders.show', o.rework_order.id)"
                  class="font-medium text-accent hover:underline"
                >
                  Reworked as {{ o.rework_order.order_number }}
                </Link>
                <button
                  v-else
                  type="button"
                  class="font-medium text-accent hover:underline"
                  @click="sendToRework(o)"
                >
                  Send to Rework
                </button>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <StatusBadge v-if="o.disposition" :status="o.disposition" />
              <StatusBadge :status="o.output_type" />
            </div>
          </div>
        </div>
      </Panel>
    </div>

    <Panel v-if="qcPlan" title="Quality Control (§3L)" class="mt-6">
      <template #actions>
        <PrimaryButton @click="openQcModal">Record Sample</PrimaryButton>
      </template>

      <p class="mb-3 text-xs text-ink-600">Plan: {{ qcPlan.name }}. Record-only holds — see the plan/hold docs for why this doesn't block Inventory.</p>

      <div v-if="qcSamples.length === 0 && qcHolds.length === 0" class="text-sm text-ink-600">No samples recorded yet.</div>

      <div v-if="qcSamples.length > 0" class="space-y-2">
        <div v-for="s in qcSamples" :key="s.id" class="rounded-md border border-border p-2.5">
          <div class="flex items-center justify-between">
            <span class="font-mono text-xs font-medium text-ink-900">{{ s.sample_number }}</span>
            <span class="text-xs text-ink-500">{{ s.taken_by_name }} · {{ s.taken_at }}</span>
          </div>
          <div class="mt-1 flex flex-wrap gap-2">
            <span v-for="(r, i) in s.results" :key="i" class="text-xs text-ink-600">
              {{ r.characteristic_name }}: {{ r.actual_value ?? '—' }}
              <StatusBadge :status="r.result" />
            </span>
          </div>
        </div>
      </div>

      <div v-if="qcHolds.length > 0" class="mt-4 space-y-2 border-t border-border pt-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Holds</p>
        <div v-for="h in qcHolds" :key="h.id" class="flex items-center justify-between rounded-md border border-border p-2.5">
          <div>
            <div class="text-sm text-ink-900">{{ h.reason }}</div>
            <div class="text-xs text-ink-500">{{ h.subject_type }} #{{ h.subject_id }} · {{ h.created_at }}</div>
          </div>
          <div class="flex items-center gap-2">
            <StatusBadge :status="h.status" />
            <SecondaryButton v-if="h.status === 'open'" @click="releaseHold(h)">Release</SecondaryButton>
          </div>
        </div>
      </div>
    </Panel>

    <Panel title="Production Event Ledger (§3C)" class="mt-6">
      <div v-if="order.events.length === 0" class="text-sm text-ink-600">No events recorded yet.</div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-border text-sm">
          <thead>
            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-ink-500">
              <th class="px-3 py-2.5">Occurred At</th>
              <th class="px-3 py-2.5">Event</th>
              <th class="px-3 py-2.5">Payload</th>
              <th class="px-3 py-2.5">User</th>
              <th class="px-3 py-2.5">Machine</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="event in order.events" :key="event.id">
              <td class="px-3 py-2.5 text-ink-600">{{ event.occurred_at }}</td>
              <td class="px-3 py-2.5"><StatusBadge :status="event.event_type" /></td>
              <td class="px-3 py-2.5 font-mono text-xs text-ink-600">{{ event.payload ? JSON.stringify(event.payload) : '—' }}</td>
              <td class="px-3 py-2.5 text-ink-900">{{ event.user_name ?? '—' }}</td>
              <td class="px-3 py-2.5 text-ink-600">{{ event.machine_code ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>

    <Panel v-if="order.serial_links.length > 0" title="Serial Genealogy (§3H)" class="mt-6">
      <p class="mb-3 text-xs text-ink-600">Which components went into which finished serial, linked as each was consumed/completed.</p>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-border text-sm">
          <thead>
            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-ink-500">
              <th class="px-3 py-2.5">Finished Serial</th>
              <th class="px-3 py-2.5">Component</th>
              <th class="px-3 py-2.5">Component Lot/Serial</th>
              <th class="px-3 py-2.5">Linked At</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="link in order.serial_links" :key="link.id">
              <td class="px-3 py-2.5 font-mono text-xs text-ink-900">{{ link.serial_number }}</td>
              <td class="px-3 py-2.5 text-ink-900">{{ link.material_sku }} — {{ link.material_name }}</td>
              <td class="px-3 py-2.5 font-mono text-xs text-ink-600">{{ link.component_serial_number ?? link.component_lot_number }}</td>
              <td class="px-3 py-2.5 text-ink-600">{{ link.created_at }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>

    <!-- Record Material Movement Modal -->
    <Modal :show="showConsumptionModal" max-width="lg" @close="showConsumptionModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Record Material Movement</h3>
        <p class="mt-1 text-sm text-ink-600">Issues call InventoryService::issue(); returns call InventoryService::receive() (§3J).</p>

        <form class="mt-4 space-y-4" @submit.prevent="submitConsumption">
          <FormSelect
            v-model="consumptionForm.type"
            name="type"
            label="Movement Type"
            :options="[{ value: 'issue', label: 'Issue (draw from stock)' }, { value: 'return', label: 'Return (put back into stock)' }]"
            :error="consumptionForm.errors.type"
            required
          />

          <FormAsyncSearchableSelect
            v-model="consumptionForm.material_product_id"
            name="material_product_id"
            label="Material"
            api-entity="inventory_product"
            placeholder="Search SKU or name…"
            :error="consumptionForm.errors.material_product_id"
            required
          />

          <div class="grid grid-cols-2 gap-4">
            <FormNumberInput v-model="consumptionForm.qty" name="qty" label="Quantity" :decimals="4" :error="consumptionForm.errors.qty" required />
            <FormInput v-model="consumptionForm.uom_code" name="uom_code" label="UoM code" :error="consumptionForm.errors.uom_code" />
          </div>

          <FormSelect
            v-model="consumptionForm.location_id"
            name="location_id"
            label="Location"
            :options="locations"
            :error="consumptionForm.errors.location_id"
            :required="consumptionForm.type === 'issue'"
          />

          <div class="grid grid-cols-2 gap-4">
            <FormAsyncSearchableSelect
              v-model="consumptionForm.lot_id"
              name="lot_id"
              label="Lot (if batch-tracked)"
              api-entity="inventory_batch"
              :extra-params="{ product_id: consumptionForm.material_product_id }"
              placeholder="Search lot number…"
              :error="consumptionForm.errors.lot_id"
            />
            <FormInput v-model="consumptionForm.serial_number" name="serial_number" label="Serial (if serial-tracked)" :error="consumptionForm.errors.serial_number" />
          </div>

          <FormSelect
            v-if="routingOps.length > 0"
            v-model="consumptionForm.operation_ref"
            name="operation_ref"
            label="Operation (optional)"
            :options="routingOps"
            :error="consumptionForm.errors.operation_ref"
          />

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showConsumptionModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="consumptionForm.processing">Record</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Record Production Output Modal -->
    <Modal :show="showOutputModal" max-width="lg" @close="showOutputModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Record Production Output</h3>
        <p class="mt-1 text-sm text-ink-600">Posts stock via InventoryService::receive(); mints a new lot/serial for a batch-/serial-tracked product (§3J).</p>

        <form class="mt-4 space-y-4" @submit.prevent="submitOutput">
          <FormSelect
            v-model="outputForm.output_type"
            name="output_type"
            label="Output Type"
            :options="[
              { value: 'finished', label: 'Finished' },
              { value: 'co_product', label: 'Co-product' },
              { value: 'by_product', label: 'By-product' },
              { value: 'waste', label: 'Waste' },
            ]"
            :error="outputForm.errors.output_type"
            required
          />

          <FormAsyncSearchableSelect
            v-model="outputForm.product_id"
            name="product_id"
            label="Product"
            api-entity="inventory_product"
            placeholder="Search SKU or name…"
            :error="outputForm.errors.product_id"
            required
          />

          <div class="grid grid-cols-2 gap-4">
            <FormNumberInput v-model="outputForm.qty" name="qty" label="Quantity" :decimals="4" :error="outputForm.errors.qty" required />
            <FormInput v-model="outputForm.uom_code" name="uom_code" label="UoM code" :error="outputForm.errors.uom_code" />
          </div>

          <FormSelect v-model="outputForm.location_id" name="location_id" label="Destination Location (optional — falls back to put-away rule)" :options="locations" :error="outputForm.errors.location_id" />

          <div class="grid grid-cols-2 gap-4">
            <FormInput v-model="outputForm.lot_number" name="lot_number" label="Lot number (if batch-tracked)" :error="outputForm.errors.lot_number" />
            <FormInput v-model="outputForm.serial_number" name="serial_number" label="Serial (if serial-tracked)" :error="outputForm.errors.serial_number" />
          </div>

          <FormSelect
            v-if="routingOps.length > 0"
            v-model="outputForm.operation_ref"
            name="operation_ref"
            label="Operation (optional)"
            :options="routingOps"
            :error="outputForm.errors.operation_ref"
          />

          <template v-if="outputForm.output_type === 'waste'">
            <FormInput v-model="outputForm.reason_code" name="reason_code" label="Reason Code" placeholder="e.g. bolt_torque_out_of_spec" :error="outputForm.errors.reason_code" required />
            <FormSelect
              v-model="outputForm.disposition"
              name="disposition"
              label="Disposition"
              :options="[{ value: 'scrap', label: 'Scrap' }, { value: 'rework', label: 'Rework' }]"
              :error="outputForm.errors.disposition"
            />
          </template>

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showOutputModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="outputForm.processing">Record</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Record QC Sample Modal -->
    <Modal :show="showQcModal" max-width="lg" @close="showQcModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Record QC Sample</h3>
        <p class="mt-1 text-sm text-ink-600">Plan: {{ qcPlan?.name }}. A fail against a named output auto-creates a hold on its lot/serial (§3L).</p>

        <form class="mt-4 space-y-4" @submit.prevent="submitQcSample">
          <FormSelect
            v-if="finishedOutputs.length > 0"
            v-model="qcForm.output_id"
            name="output_id"
            label="Inspecting this output (optional — finished-goods checkpoint)"
            :options="finishedOutputs.map((o) => ({ value: o.id, label: `${o.product_sku} — ${o.lot_number ?? o.serial_number ?? o.created_at}` }))"
            :error="qcForm.errors.output_id"
          />

          <div v-for="(result, i) in qcForm.results" :key="i" class="grid grid-cols-[2fr_1fr_1fr] items-end gap-3 rounded-md border border-border p-3">
            <span class="text-sm font-medium text-ink-900">{{ qcPlan?.characteristics[i]?.characteristic_name }}</span>
            <FormNumberInput
              v-model="result.actual_value"
              :name="`results.${i}.actual_value`"
              label="Actual"
              :decimals="4"
            />
            <FormSelect
              v-model="result.result"
              :name="`results.${i}.result`"
              label="Result"
              :options="[{ value: 'pass', label: 'Pass' }, { value: 'fail', label: 'Fail' }, { value: 'hold', label: 'Hold' }]"
            />
          </div>

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showQcModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="qcForm.processing">Record Sample</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
