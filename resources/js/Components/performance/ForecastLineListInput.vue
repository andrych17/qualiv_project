<!-- ponytail: repeatable Forecast lines (§3H) — one projected value per period slice, no category
     (a forecast is one trajectory per header, not per-category — see ForecastLine model docblock). -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

export interface ForecastLineRow {
  period_id: number | null
  forecast_value: number | null
}

const props = defineProps<{
  modelValue: ForecastLineRow[]
  periods: Array<{ id: number; label: string }>
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: ForecastLineRow[]]
}>()

const emptyRow = (): ForecastLineRow => ({ period_id: null, forecast_value: null })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<ForecastLineRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
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

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No lines yet.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="grid grid-cols-[2fr_1fr_auto] items-end gap-3 rounded-md border border-border p-3"
    >
      <FormSelect
        :model-value="row.period_id"
        :name="`lines.${index}.period_id`"
        label="Period"
        placeholder="Select…"
        :options="periods.map((p) => ({ label: p.label, value: p.id }))"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { period_id: Number(value) })"
      />

      <FormNumberInput
        :model-value="row.forecast_value"
        :name="`lines.${index}.forecast_value`"
        label="Forecast value"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { forecast_value: value })"
      />

      <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
