<!-- ponytail: Traceability & Genealogy, read-only recursive trace (MES_SPECS.md §3K) -->
<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface ConsumptionTrailRow {
  order_id: number
  order_number: string
  order_status: string
  material_sku: string
  material_name: string
  consumed_qty: number
  depth: number
  lot_number: string | null
  serial_number: string | null
}

interface OutputTrailRow {
  order_id: number
  order_number: string
  order_status: string
  product_sku: string
  product_name: string
  output_type: string
  output_qty: number
  depth: number
  lot_number: string | null
  serial_number: string | null
}

interface OrderOutputRow {
  order_id: number
  order_number: string
  product_sku: string
  product_name: string
  output_type: string
  qty: number
  lot_number: string | null
  serial_number: string | null
}

interface OrderConsumptionRow {
  order_id: number
  order_number: string
  material_sku: string
  material_name: string
  type: string
  qty: number
  lot_number: string | null
  serial_number: string | null
}

const props = defineProps<{
  filters: { lot_number?: string; serial_number?: string; direction?: string }
  result: {
    consumption_trail?: ConsumptionTrailRow[]
    outputs_by_order?: OrderOutputRow[]
    output_trail?: OutputTrailRow[]
    consumptions_by_order?: OrderConsumptionRow[]
  } | null
  notFound: boolean
}>()

const lotNumber = ref(props.filters.lot_number ?? '')
const serialNumber = ref(props.filters.serial_number ?? '')
const direction = ref(props.filters.direction ?? 'backward')

const search = () => {
  router.get(route('mes.traceability.index'), {
    lot_number: lotNumber.value || undefined,
    serial_number: serialNumber.value || undefined,
    direction: direction.value,
  }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Traceability & Genealogy"
      description="No dedicated genealogy table — derived over Material Consumption, Production Output, and Serial Genealogy (§3K)."
    />

    <Panel class="mt-6 max-w-3xl">
      <form class="grid grid-cols-1 gap-4 sm:grid-cols-4" @submit.prevent="search">
        <FormInput v-model="lotNumber" name="lot_number" label="Lot Number" placeholder="e.g. LOT-2026-001" />
        <FormInput v-model="serialNumber" name="serial_number" label="Serial Number" placeholder="e.g. SN-0001" />
        <FormSelect
          v-model="direction"
          name="direction"
          label="Direction"
          :options="[
            { value: 'backward', label: 'Backward (recall) — what went into it' },
            { value: 'forward', label: 'Forward — what it fed into' },
          ]"
        />
        <div class="flex items-end">
          <PrimaryButton type="submit" class="w-full justify-center">Trace</PrimaryButton>
        </div>
      </form>
    </Panel>

    <Panel v-if="notFound" class="mt-6 max-w-3xl">
      <p class="text-sm text-ink-600">No lot or serial found matching that number.</p>
    </Panel>

    <template v-if="result">
      <Panel v-if="direction === 'backward'" title="Backward Trace — what went into it" class="mt-6">
        <div v-if="!result.output_trail || result.output_trail.length === 0" class="text-sm text-ink-600">No trail found.</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-sm">
            <thead>
              <tr class="text-left text-xs font-semibold uppercase tracking-wider text-ink-500">
                <th class="px-3 py-2.5">Depth</th>
                <th class="px-3 py-2.5">Order</th>
                <th class="px-3 py-2.5">Product</th>
                <th class="px-3 py-2.5">Type</th>
                <th class="px-3 py-2.5">Qty</th>
                <th class="px-3 py-2.5">Lot / Serial</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="(row, i) in result.output_trail" :key="i">
                <td class="px-3 py-2.5 text-ink-500">{{ row.depth }}</td>
                <td class="px-3 py-2.5 font-mono text-xs text-ink-900">{{ row.order_number }}</td>
                <td class="px-3 py-2.5 text-ink-900">{{ row.product_sku }} — {{ row.product_name }}</td>
                <td class="px-3 py-2.5 text-ink-600">{{ row.output_type }}</td>
                <td class="px-3 py-2.5 text-ink-600">{{ row.output_qty }}</td>
                <td class="px-3 py-2.5 font-mono text-xs text-ink-600">{{ row.lot_number ?? row.serial_number ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="result.consumptions_by_order && result.consumptions_by_order.length > 0" class="mt-4 border-t border-border pt-4">
          <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-600">Raw materials consumed at each order in the trail</p>
          <div class="space-y-1.5 text-sm">
            <div v-for="(row, i) in result.consumptions_by_order" :key="i" class="flex justify-between">
              <span class="text-ink-900">{{ row.order_number }} — {{ row.material_sku }} ({{ row.type }})</span>
              <span class="font-mono text-xs text-ink-600">{{ row.qty }} · {{ row.lot_number ?? row.serial_number ?? '—' }}</span>
            </div>
          </div>
        </div>
      </Panel>

      <Panel v-else title="Forward Trace — what it fed into" class="mt-6">
        <div v-if="!result.consumption_trail || result.consumption_trail.length === 0" class="text-sm text-ink-600">No trail found.</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-sm">
            <thead>
              <tr class="text-left text-xs font-semibold uppercase tracking-wider text-ink-500">
                <th class="px-3 py-2.5">Depth</th>
                <th class="px-3 py-2.5">Order</th>
                <th class="px-3 py-2.5">Material</th>
                <th class="px-3 py-2.5">Qty Consumed</th>
                <th class="px-3 py-2.5">Lot / Serial</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="(row, i) in result.consumption_trail" :key="i">
                <td class="px-3 py-2.5 text-ink-500">{{ row.depth }}</td>
                <td class="px-3 py-2.5 font-mono text-xs text-ink-900">{{ row.order_number }}</td>
                <td class="px-3 py-2.5 text-ink-900">{{ row.material_sku }} — {{ row.material_name }}</td>
                <td class="px-3 py-2.5 text-ink-600">{{ row.consumed_qty }}</td>
                <td class="px-3 py-2.5 font-mono text-xs text-ink-600">{{ row.lot_number ?? row.serial_number ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="result.outputs_by_order && result.outputs_by_order.length > 0" class="mt-4 border-t border-border pt-4">
          <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-600">What each order in the trail produced</p>
          <div class="space-y-1.5 text-sm">
            <div v-for="(row, i) in result.outputs_by_order" :key="i" class="flex justify-between">
              <span class="text-ink-900">{{ row.order_number }} — {{ row.product_sku }} ({{ row.output_type }})</span>
              <span class="font-mono text-xs text-ink-600">{{ row.qty }} · {{ row.lot_number ?? row.serial_number ?? '—' }}</span>
            </div>
          </div>
        </div>
      </Panel>
    </template>
  </AppLayout>
</template>
