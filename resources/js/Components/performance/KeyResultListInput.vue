<!-- ponytail: repeatable Key Results (§3E) under one Objective. -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

export interface KeyResultRow {
  description: string
  metric_type: 'numeric' | 'percent' | 'boolean' | 'milestone'
  start_value: number | null
  current_value: number | null
  target_value: number | null
  weight: number | null
}

const props = defineProps<{
  modelValue: KeyResultRow[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: KeyResultRow[]]
}>()

const emptyRow = (): KeyResultRow => ({ description: '', metric_type: 'numeric', start_value: 0, current_value: 0, target_value: null, weight: 100 })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<KeyResultRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Key Results</p>
      <button
        v-if="!disabled"
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add key result
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No key results yet.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="space-y-3 rounded-md border border-border p-3"
    >
      <div class="grid grid-cols-[2fr_1fr_auto] items-end gap-3">
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Description</label>
          <input
            :value="row.description"
            type="text"
            placeholder="e.g. Reduce churn to 2%"
            :disabled="disabled"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:bg-surface-50 disabled:text-ink-600"
            @input="update(index, { description: ($event.target as HTMLInputElement).value })"
          />
        </div>

        <FormSelect
          :model-value="row.metric_type"
          :name="`key_results.${index}.metric_type`"
          label="Metric type"
          :options="[
            { label: 'Numeric', value: 'numeric' },
            { label: 'Percent', value: 'percent' },
            { label: 'Boolean', value: 'boolean' },
            { label: 'Milestone', value: 'milestone' },
          ]"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { metric_type: value as KeyResultRow['metric_type'] })"
        />

        <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
          <Trash2 class="h-4 w-4" />
        </button>
      </div>

      <div v-if="row.metric_type !== 'boolean'" class="grid grid-cols-4 gap-3">
        <FormNumberInput
          :model-value="row.start_value"
          :name="`key_results.${index}.start_value`"
          label="Start"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { start_value: value })"
        />
        <FormNumberInput
          :model-value="row.current_value"
          :name="`key_results.${index}.current_value`"
          label="Current"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { current_value: value })"
        />
        <FormNumberInput
          :model-value="row.target_value"
          :name="`key_results.${index}.target_value`"
          label="Target"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { target_value: value })"
        />
        <FormNumberInput
          :model-value="row.weight"
          :name="`key_results.${index}.weight`"
          label="Weight"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { weight: value })"
        />
      </div>
      <div v-else class="grid grid-cols-4 gap-3">
        <FormSelect
          :model-value="row.current_value ? 1 : 0"
          :name="`key_results.${index}.current_value`"
          label="Achieved?"
          :options="[{ label: 'Not yet', value: 0 }, { label: 'Yes', value: 1 }]"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { current_value: Number(value), start_value: 0, target_value: 1 })"
        />
        <FormNumberInput
          :model-value="row.weight"
          :name="`key_results.${index}.weight`"
          label="Weight"
          :disabled="disabled"
          @update:model-value="(value) => update(index, { weight: value })"
        />
      </div>
    </div>
  </div>
</template>
