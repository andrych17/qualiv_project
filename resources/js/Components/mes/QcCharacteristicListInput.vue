<!-- ponytail: repeatable QC characteristics (MES_SPECS.md §3L) -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

export interface QcCharacteristicRow {
  characteristic_name: string
  spec_type: string
  target_value: number | null
  min_value: number | null
  max_value: number | null
  uom_code: string | null
}

const props = defineProps<{
  modelValue: QcCharacteristicRow[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: QcCharacteristicRow[]]
}>()

const emptyRow = (): QcCharacteristicRow => ({
  characteristic_name: '', spec_type: 'numeric', target_value: null, min_value: null, max_value: null, uom_code: null,
})

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<QcCharacteristicRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Characteristics</p>
      <button
        v-if="!disabled"
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add characteristic
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No characteristics yet.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="grid grid-cols-[2fr_1fr_1fr_1fr_1fr_1fr_auto] items-end gap-2 rounded-md border border-border p-3"
    >
      <FormInput
        :model-value="row.characteristic_name"
        :name="`characteristics.${index}.characteristic_name`"
        label="Characteristic"
        placeholder="e.g. Bolt Torque"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { characteristic_name: String(value) })"
      />
      <FormSelect
        :model-value="row.spec_type"
        :name="`characteristics.${index}.spec_type`"
        label="Spec Type"
        :options="[{ value: 'numeric', label: 'Numeric' }, { value: 'pass_fail', label: 'Pass/Fail' }]"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { spec_type: String(value) })"
      />
      <FormNumberInput
        :model-value="row.target_value"
        :name="`characteristics.${index}.target_value`"
        label="Target"
        :decimals="4"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { target_value: value })"
      />
      <FormNumberInput
        :model-value="row.min_value"
        :name="`characteristics.${index}.min_value`"
        label="Min"
        :decimals="4"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { min_value: value })"
      />
      <FormNumberInput
        :model-value="row.max_value"
        :name="`characteristics.${index}.max_value`"
        label="Max"
        :decimals="4"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { max_value: value })"
      />
      <FormInput
        :model-value="row.uom_code ?? ''"
        :name="`characteristics.${index}.uom_code`"
        label="UoM"
        placeholder="e.g. Nm"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { uom_code: String(value) || null })"
      />
      <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
