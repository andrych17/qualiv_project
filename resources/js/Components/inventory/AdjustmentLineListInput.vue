<!-- ponytail: repeatable Adjustment lines (§3G) — system_qty is fetched live as a convenience
     default when a product is picked; posting always re-checks the live balance regardless
     (AdjustmentService::post()), so a stale value here never posts a wrong variance. -->
<script setup lang="ts">
import { computed } from 'vue'
import axios from 'axios'
import { Plus, Trash2 } from 'lucide-vue-next'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'

export interface AdjustmentLineRow {
  product_id: number | null
  system_qty: number | null
  counted_qty: number
  batch_id?: number | null
  batch_label?: string
}

const props = defineProps<{
  modelValue: AdjustmentLineRow[]
  warehouseId: number | null
  locationId: number | null
  /** §3L: product_id → tracking_mode, so a batch-tracked line shows the lot picker before system_qty can be fetched. */
  productTracking?: Record<number, string>
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: AdjustmentLineRow[]]
}>()

const isBatchTracked = (productId: number | null) => !!productId && props.productTracking?.[productId] === 'batch'

const emptyRow = (): AdjustmentLineRow => ({ product_id: null, system_qty: null, counted_qty: 0 })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<AdjustmentLineRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}

const variance = (row: AdjustmentLineRow) => (row.system_qty === null ? null : row.counted_qty - row.system_qty)

/** Batch-tracked lines pass `batchId` once a lot is picked — system_qty is that lot's own balance, not the product's total across all lots. */
const fetchSystemQty = async (index: number, productId: number, batchId: number | null = null) => {
  if (!props.warehouseId || !props.locationId) return
  try {
    const { data } = await axios.get(route('inventory.adjustments.balance'), {
      params: { product_id: productId, warehouse_id: props.warehouseId, location_id: props.locationId, batch_id: batchId ?? undefined },
    })
    update(index, { system_qty: Number(data.qty_on_hand), counted_qty: Number(data.qty_on_hand) })
  } catch {
    // leave system_qty blank — post() still works off the live balance regardless
  }
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Lines</p>
      <button
        v-if="!disabled"
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add line
      </button>
    </div>

    <p v-if="!disabled && (!warehouseId || !locationId)" class="text-sm text-ink-600">
      Choose a warehouse and location above to auto-fill system quantity.
    </p>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No lines yet.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="space-y-3 rounded-md border border-border p-3"
    >
      <div class="grid grid-cols-[2fr_1fr_1fr_1fr_auto] items-end gap-3">
        <FormAsyncSearchableSelect
          :model-value="row.product_id"
          :name="`lines.${index}.product_id`"
          label="Product"
          api-entity="inventory_product"
          placeholder="Search SKU or name…"
          :disabled="disabled"
          @update:model-value="(value) => {
            update(index, { product_id: Number(value), batch_id: null, system_qty: null })
            if (value && !isBatchTracked(Number(value))) fetchSystemQty(index, Number(value))
          }"
        />

        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">System qty</label>
          <input
            :value="row.system_qty ?? '—'"
            type="text"
            disabled
            class="w-full rounded-sm border border-border bg-surface-50 px-3 py-2 text-sm text-ink-600"
          />
        </div>

        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Counted qty</label>
          <input
            :value="row.counted_qty"
            type="number"
            step="0.0001"
            min="0"
            :disabled="disabled"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:bg-surface-50 disabled:text-ink-600"
            @input="update(index, { counted_qty: Number(($event.target as HTMLInputElement).value) })"
          />
        </div>

        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Variance</label>
          <p
            class="px-3 py-2 text-sm font-medium"
            :class="{
              'text-signal-success': (variance(row) ?? 0) > 0,
              'text-signal-danger': (variance(row) ?? 0) < 0,
              'text-ink-600': !variance(row),
            }"
          >
            {{ variance(row) === null ? '—' : (variance(row)! > 0 ? '+' : '') + variance(row) }}
          </p>
        </div>

        <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
          <Trash2 class="h-4 w-4" />
        </button>
      </div>

      <div v-if="isBatchTracked(row.product_id)" class="rounded-sm bg-surface-50 p-2.5">
        <FormAsyncSearchableSelect
          :model-value="row.batch_id ?? null"
          :name="`lines.${index}.batch_id`"
          label="Lot"
          api-entity="inventory_batch"
          :extra-params="{ product_id: row.product_id }"
          placeholder="Search lot number…"
          :disabled="disabled"
          @update:model-value="(value) => {
            update(index, { batch_id: Number(value) })
            if (value && row.product_id) fetchSystemQty(index, row.product_id, Number(value))
          }"
        />
      </div>
    </div>
  </div>
</template>
