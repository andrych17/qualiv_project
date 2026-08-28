<!-- ponytail: repeatable Budget lines (§3B) — editable only while the budget is a draft; category is
     free text (spec: "free lookup, e.g. Payroll, Marketing"), not a picker. -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

export interface BudgetLineRow {
  category: string
  period_id: number | null
  amount_planned: number | null
  notes?: string | null
}

const props = defineProps<{
  modelValue: BudgetLineRow[]
  periods: Array<{ id: number; label: string }>
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: BudgetLineRow[]]
}>()

const emptyRow = (): BudgetLineRow => ({ category: '', period_id: null, amount_planned: null })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<BudgetLineRow>) => {
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
      class="grid grid-cols-[2fr_1fr_1fr_auto] items-end gap-3 rounded-md border border-border p-3"
    >
      <div class="space-y-1.5">
        <label class="text-sm font-medium text-ink-900">Category</label>
        <input
          :value="row.category"
          type="text"
          placeholder="e.g. Marketing"
          :disabled="disabled"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:bg-surface-50 disabled:text-ink-600"
          @input="update(index, { category: ($event.target as HTMLInputElement).value })"
        />
      </div>

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
        :model-value="row.amount_planned"
        :name="`lines.${index}.amount_planned`"
        label="Amount planned"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { amount_planned: value })"
      />

      <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
