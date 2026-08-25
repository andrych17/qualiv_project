<!-- ponytail: repeatable Transfer lines (§3F) — no per-line location, source/destination are header fields -->
<script setup lang="ts">
import { ref } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import BarcodeScanInput, { type ProductScanResult, type LocationScanResult } from '@/Components/inventory/BarcodeScanInput.vue'
import SerialNumberListInput from '@/Components/inventory/SerialNumberListInput.vue'

export interface TransferLineRow {
  product_id: number | null
  qty: number
  uom_id: number | null
  batch_id?: number | null
  batch_label?: string
  serial_numbers?: string[]
}

const props = defineProps<{
  modelValue: TransferLineRow[]
  uoms: Array<{ id: number; code: string; name: string }>
  /** §3L: product_id → tracking_mode, so a batch-tracked line shows the lot picker. */
  productTracking?: Record<number, string>
  disabled?: boolean
}>()

const isBatchTracked = (productId: number | null) => !!productId && props.productTracking?.[productId] === 'batch'
/** §3M: a serial-tracked line names which unit(s) move instead of typing a quantity. */
const isSerialTracked = (productId: number | null) => !!productId && props.productTracking?.[productId] === 'serial'

const emit = defineEmits<{
  'update:modelValue': [value: TransferLineRow[]]
}>()

const emptyRow = (): TransferLineRow => ({ product_id: null, qty: 1, uom_id: null })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<TransferLineRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
/** §3M: qty for a serial-tracked line is derived — one entry per physical unit. */
const updateSerials = (index: number, serialNumbers: string[]) => update(index, { serial_numbers: serialNumbers, qty: serialNumbers.length })

// §3K scan-to-transfer: rescanning a product already on a line bumps its qty instead of adding a duplicate row.
const scanMessage = ref<{ type: 'error'; text: string } | null>(null)

const onScanned = (scanned: ProductScanResult | LocationScanResult) => {
  if (!('product_id' in scanned)) return
  const result = scanned

  scanMessage.value = null
  const existingIndex = props.modelValue.findIndex((row) => row.product_id === result.product_id)

  if (existingIndex >= 0) {
    update(existingIndex, { qty: props.modelValue[existingIndex].qty + result.unit_multiplier })
  } else {
    emit('update:modelValue', [...props.modelValue, { product_id: result.product_id, qty: result.unit_multiplier, uom_id: result.uom_id }])
  }
}

const onScanNotFound = (code: string) => {
  scanMessage.value = { type: 'error', text: `No product barcode matches "${code}".` }
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

    <BarcodeScanInput v-if="!disabled" context="product" :disabled="disabled" @resolved="onScanned" @not-found="onScanNotFound" />
    <p v-if="scanMessage" class="text-sm text-signal-danger">{{ scanMessage.text }}</p>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No lines yet.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="space-y-3 rounded-md border border-border p-3"
    >
      <div class="grid grid-cols-[2fr_1fr_1fr_auto] items-end gap-3">
        <FormAsyncSearchableSelect
          :model-value="row.product_id"
          :name="`lines.${index}.product_id`"
          label="Product"
          api-entity="inventory_product"
          placeholder="Search SKU or name…"
          :disabled="disabled"
          @update:model-value="update(index, { product_id: Number($event), batch_id: null })"
        />

        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Qty</label>
          <input
            :value="row.qty"
            type="number"
            step="0.0001"
            min="0.0001"
            :disabled="disabled || isSerialTracked(row.product_id)"
            :title="isSerialTracked(row.product_id) ? 'Derived from the serial numbers entered below' : undefined"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:bg-surface-50 disabled:text-ink-600"
            @input="update(index, { qty: Number(($event.target as HTMLInputElement).value) })"
          />
        </div>

        <FormSelect
          :model-value="row.uom_id"
          :name="`lines.${index}.uom_id`"
          label="UoM"
          :options="uoms.map((u) => ({ label: u.code, value: u.id }))"
          :disabled="disabled"
          @update:model-value="update(index, { uom_id: Number($event) })"
        />

        <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
          <Trash2 class="h-4 w-4" />
        </button>
      </div>

      <div v-if="isBatchTracked(row.product_id)" class="rounded-sm bg-surface-50 p-2.5">
        <FormAsyncSearchableSelect
          :model-value="row.batch_id ?? null"
          :name="`lines.${index}.batch_id`"
          label="Lot (moves with the stock — destination doesn't pick its own)"
          api-entity="inventory_batch"
          :extra-params="{ product_id: row.product_id }"
          placeholder="Search lot number…"
          :disabled="disabled"
          @update:model-value="update(index, { batch_id: Number($event) })"
        />
      </div>

      <div v-if="isSerialTracked(row.product_id)" class="rounded-sm bg-surface-50 p-2.5">
        <SerialNumberListInput
          :model-value="row.serial_numbers ?? []"
          placeholder="Serial number to transfer"
          :disabled="disabled"
          @update:model-value="updateSerials(index, $event)"
        />
      </div>
    </div>
  </div>
</template>
