<!-- ponytail: repeatable BOM component lines (PP_SPECS.md §3D) -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'

export interface BomLineRow {
  component_product_id: number | null
  component_label?: string
  qty_per_parent_unit: number
  uom_code: string | null
  scrap_pct: number
}

const props = defineProps<{
  modelValue: BomLineRow[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: BomLineRow[]]
}>()

const emptyRow = (): BomLineRow => ({ component_product_id: null, qty_per_parent_unit: 1, uom_code: null, scrap_pct: 0 })

const addRow = () => emit('update:modelValue', [...props.modelValue, emptyRow()])
const removeRow = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const update = (index: number, patch: Partial<BomLineRow>) => {
  const rows = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row))
  emit('update:modelValue', rows)
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Components</p>
      <button
        v-if="!disabled"
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addRow"
      >
        <Plus class="h-3.5 w-3.5" /> Add component
      </button>
    </div>

    <div v-if="modelValue.length === 0" class="text-sm text-ink-600">No components yet.</div>

    <div
      v-for="(row, index) in modelValue"
      :key="index"
      class="grid grid-cols-[2fr_1fr_1fr_1fr_auto] items-end gap-3 rounded-md border border-border p-3"
    >
      <FormAsyncSearchableSelect
        :model-value="row.component_product_id"
        :name="`lines.${index}.component_product_id`"
        label="Component"
        api-entity="inventory_product"
        placeholder="Search SKU or name…"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { component_product_id: Number(value) })"
      />

      <FormNumberInput
        :model-value="row.qty_per_parent_unit"
        :name="`lines.${index}.qty_per_parent_unit`"
        label="Qty per unit"
        :decimals="6"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { qty_per_parent_unit: value ?? 0 })"
      />

      <FormInput
        :model-value="row.uom_code ?? ''"
        :name="`lines.${index}.uom_code`"
        label="UoM code"
        placeholder="e.g. PCS"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { uom_code: String(value) || null })"
      />

      <FormNumberInput
        :model-value="row.scrap_pct"
        :name="`lines.${index}.scrap_pct`"
        label="Scrap %"
        :decimals="2"
        suffix="%"
        :disabled="disabled"
        @update:model-value="(value) => update(index, { scrap_pct: value ?? 0 })"
      />

      <button v-if="!disabled" type="button" class="text-signal-danger" @click="removeRow(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
