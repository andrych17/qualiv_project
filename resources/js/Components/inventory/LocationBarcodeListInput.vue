<!-- ponytail: repeatable Location barcode rows (§3K) — simpler than Product's (BarcodeListInput):
     no type/unit_multiplier, a bin label is just a code that resolves back to this location. -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'

export interface LocationBarcodeRow {
  barcode: string
}

const props = defineProps<{
  modelValue: LocationBarcodeRow[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: LocationBarcodeRow[]]
}>()

const addRow = () => emit('update:modelValue', [...props.modelValue, { barcode: '' }])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, barcode: string) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { barcode } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Barcodes</p>
      <button
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add barcode
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No barcodes added — the location code above is only searchable, not scannable.</div>

    <div v-for="(row, index) in modelValue" :key="index" class="flex items-end gap-3">
      <div class="flex-1 space-y-1.5">
        <label class="text-sm font-medium text-ink-900">Barcode</label>
        <input
          :value="row.barcode"
          placeholder="Scan or type…"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, ($event.target as HTMLInputElement).value)"
        />
      </div>
      <button type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
