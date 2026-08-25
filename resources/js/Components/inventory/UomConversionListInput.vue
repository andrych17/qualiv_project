<!-- ponytail: repeatable additional-UoM rows (§3B) — factor is always relative to the
     product's base UoM, e.g. Box = 24 x Each. -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'

export interface UomConversionRow {
  uom_id: number | null
  conversion_factor: number
}

const props = defineProps<{
  modelValue: UomConversionRow[]
  uoms: Array<{ id: number; code: string; name: string }>
  baseUomCode?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: UomConversionRow[]]
}>()

const emptyRow = (): UomConversionRow => ({ uom_id: null, conversion_factor: 1 })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<UomConversionRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Additional UoMs</p>
      <button
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add UoM
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No additional UoMs — product is tracked in its base UoM only.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="grid grid-cols-[1fr_1fr_auto] items-end gap-3 rounded-md border border-border p-3"
    >
      <FormSelect
        :model-value="row.uom_id"
        :name="`uom_conversions.${index}.uom_id`"
        label="UoM"
        :options="uoms.map((u) => ({ label: `${u.code} — ${u.name}`, value: u.id }))"
        @update:model-value="update(index, { uom_id: Number($event) })"
      />

      <div class="space-y-1.5">
        <label class="text-sm font-medium text-ink-900">1 UoM = × base{{ baseUomCode ? ` (${baseUomCode})` : '' }}</label>
        <input
          :value="row.conversion_factor"
          type="number"
          step="0.000001"
          min="0.000001"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @input="update(index, { conversion_factor: Number(($event.target as HTMLInputElement).value) })"
        />
      </div>

      <button type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
