<!-- ponytail: repeatable Barcode rows (§3B/§3K) — shared by Product Create+Edit -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'

export interface BarcodeRow {
  barcode: string
  type: string
  unit_multiplier: number
}

const props = defineProps<{
  modelValue: BarcodeRow[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: BarcodeRow[]]
}>()

const emptyRow = (): BarcodeRow => ({
  barcode: '',
  type: props.modelValue.length === 0 ? 'primary' : 'alternate',
  unit_multiplier: 1,
})

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<BarcodeRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
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

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No barcodes added.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="grid grid-cols-[2fr_1fr_1fr_auto] items-end gap-3 rounded-md border border-border p-3"
    >
      <div class="space-y-1.5">
        <label class="text-sm font-medium text-ink-900">Barcode</label>
        <input
          :value="row.barcode"
          placeholder="Scan or type…"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { barcode: ($event.target as HTMLInputElement).value })"
        />
      </div>

      <FormSelect
        :model-value="row.type"
        :name="`barcodes.${index}.type`"
        label="Type"
        :options="[
          { label: 'Primary', value: 'primary' },
          { label: 'Case pack', value: 'case_pack' },
          { label: 'Alternate', value: 'alternate' },
        ]"
        @update:model-value="update(index, { type: String($event) })"
      />

      <div class="space-y-1.5">
        <label class="text-sm font-medium text-ink-900">Unit ×</label>
        <input
          :value="row.unit_multiplier"
          type="number"
          step="0.000001"
          min="0.000001"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { unit_multiplier: Number(($event.target as HTMLInputElement).value) })"
        />
      </div>

      <button type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
